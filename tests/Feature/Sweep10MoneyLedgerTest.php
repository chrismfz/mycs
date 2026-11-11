<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\UnbilledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Sweep 10, money-ledger: adversarial verification. Each test fails on f9f145bb for the reported reason.
 */
class Sweep10MoneyLedgerTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * S10-MONEY-LEDGER-1. An open invoice of 100.00 with a renewal line; 80.00 paid by card, 20.00 of it refunded to
     * credit by staff, the line then brought down to 50.00: open and 10.00 over, not closed yet. The whole 80.00 then
     * goes back at the gateway (a refund, or a chargeback).
     *
     * @return array{payment: InvoiceTransaction, invoice: Invoice, service: Service, expires: string, credit: string}
     */
    private function coveredOpenInvoiceThenAllGoesBack(bool $chargeback): array
    {
        $this->freezeTime();
        $this->storeSettings(['credits_enabled' => true, 'credits_auto_use' => true]);
        $user = User::factory()->create();
        $client = $user->client()->id;
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 100.00]);
        $service = Service::factory()->create(['client_id' => $client, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addDays(5)->startOfDay(), 'currency_code' => 'USD', 'price' => 100.00]);
        $invoice = Invoice::factory()->create(['client_id' => $client, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addDays(5)]);
        $line = $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100, 'reference_type' => Service::class, 'reference_id' => $service->id,
            'period_start' => now()->addDays(5)->toDateString(), 'period_end' => now()->addDays(5)->addMonth()->toDateString()]);
        $expires = $service->fresh()->expires_at->toDateString();

        (new RecordPaymentService)->handle($invoice->id, $gateway, '80.00', null, 'pi_s10_1');
        ExtensionHelper::refundToCredit(InvoiceTransaction::query()->firstOrFail(), 20.00, false);
        $line->refresh()->update(['price' => 50]);

        // The premise: still open, 10.00 over, nothing renewed, 20.00 of credit.
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(-1000, RecordPaymentService::toCents($invoice->fresh()->remaining));
        $this->assertSame($expires, $service->fresh()->expires_at->toDateString());
        $this->assertNull($line->fresh()->applied_at);
        $this->assertSame(2000, RecordPaymentService::toCents(Credit::query()->where('client_id', $client)->value('amount')));

        $chargeback
            ? ExtensionHelper::chargebackAtGateway('Stripe', 'pi_s10_1', 'dp_s10_1', 80.00, 'USD')
            : ExtensionHelper::refundedAtGateway('Stripe', 'pi_s10_1', 're_s10_1', 80.00, 'USD');

        return ['payment' => InvoiceTransaction::query()->where('transaction_id', 'pi_s10_1')->firstOrFail(), 'invoice' => $invoice->fresh(),
            'service' => $service->fresh(), 'line' => $line->fresh(), 'expires' => $expires,
            'credit' => (string) Credit::query()->where('client_id', $client)->value('amount')];
    }

    public function test_s10_money_ledger_1_gateway_refund_of_a_payment_on_a_covered_open_invoice_gives_back_no_more_than_it_brought(): void
    {
        $after = $this->coveredOpenInvoiceThenAllGoesBack(chargeback: false);

        $this->assertLessThanOrEqual(8000, RecordPaymentService::toCents($after['payment']->refunded_amount) + RecordPaymentService::toCents($after['payment']->credited_amount),
            'the payment of 80.00 has more refunded and credited than it brought');
        $this->assertSame(0, RecordPaymentService::toCents($after['credit']), 'all 80.00 went back on the card, and the customer keeps account credit from it too');
    }

    public function test_s10_money_ledger_1_gateway_refund_of_the_whole_payment_does_not_renew_the_service(): void
    {
        $after = $this->coveredOpenInvoiceThenAllGoesBack(chargeback: false);

        $this->assertSame($after['expires'], $after['service']->expires_at->toDateString(), 'a refund of all the money on the invoice renewed its service');
        $this->assertNotSame(Invoice::STATUS_PAID, $after['invoice']->status, 'the invoice is paid with no money left on it');
    }

    public function test_s10_money_ledger_1_chargeback_of_a_payment_on_a_covered_open_invoice_gives_back_no_more_than_it_brought(): void
    {
        $after = $this->coveredOpenInvoiceThenAllGoesBack(chargeback: true);

        $this->assertLessThanOrEqual(8000, RecordPaymentService::toCents($after['payment']->refunded_amount) + RecordPaymentService::toCents($after['payment']->credited_amount),
            'the payment of 80.00 has more charged back and credited than it brought');
        $this->assertSame(0, RecordPaymentService::toCents($after['credit']), 'all 80.00 was charged back, and the customer keeps account credit from it too');
    }

    /**
     * S10-MONEY-LEDGER-2. Two imported services, both paid to 2026-10-10: 31's paid line fits and has its period (#788),
     * 32's has no WHMCS due date and comes with none (the «named» shape). Both WHMCS payments are deleted: both invoices
     * owe again and both services run on. The rule tells 31 and says nothing of 32.
     */
    public function test_s10_money_ledger_2_service_unbilled_tells_an_imported_no_period_service_whose_payment_was_deleted(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');
        $user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($user->client()->id, $product->product->id);
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsService(32, '2026-10-10');
        $this->whmcsInvoice(481, 'Paid', [32 => 10.0], due: '2026-09-10', lineDue: null);
        $this->importWhmcs();
        $kinds = function (): array {
            $kinds = [];
            foreach ((new UnbilledServices)->find() as $finding) {
                $kinds[$finding['service']][] = $finding['kind'];
            }

            return $kinds;
        };
        $this->assertSame([], $kinds());

        foreach ([480, 481] as $id) {
            (new RecordPaymentService)->deleteTransaction(InvoiceTransaction::query()->where('invoice_id', $id)->firstOrFail());
        }

        // The premise: both owe again, both services still run to 2026-10-10.
        $this->assertSame([Invoice::STATUS_UNPAID, Invoice::STATUS_UNPAID], Invoice::query()->whereKey([480, 481])->orderBy('id')->pluck('status')->all());
        $this->assertSame([Service::STATUS_ACTIVE, Service::STATUS_ACTIVE], Service::query()->whereKey([31, 32])->orderBy('id')->pluck('status')->all());
        $this->assertSame('2026-10-10', Service::query()->findOrFail(32)->expires_at->toDateString());
        $this->assertArrayHasKey(31, $kinds());
        $this->assertArrayHasKey(32, $kinds(), 'service 32 runs to 2026-10-10 on an invoice that is unpaid again, and service_unbilled says nothing');
    }
}
