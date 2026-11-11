<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\UnbilledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Sweep 8, «money-ledger»: adversarial verification, written without the finder's reasoning. Every test here FAILS on
 * main (6f4b6e29) for the reason in its docblock. Invoices as the WHMCS importer brings them (no period, never applied).
 *
 * Since the importer gives the last paid line and the open renewal their period (WhmcsLinePeriods, owner 2026-11-11) the
 * tests of the period-less-line findings pass, with no billing rule changed; the others are marked incomplete, each with
 * its reason.
 */
class Sweep8MoneyLedgerTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow('2026-09-20 10:00:00');
        config(['settings.cronjob_order_suspend' => 8, 'settings.cronjob_order_terminate' => 60, 'settings.cronjob_invoice' => 7]);
        $user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($user->client()->id, $product->product->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A service running to 2026-10-10 on invoice #480, paid in WHMCS (due 2026-09-10). */
    private function paidInWhmcs(): InvoiceTransaction
    {
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->importWhmcs();
        $this->assertSame(Invoice::STATUS_PAID, $this->imported(480)->status);
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString());

        return InvoiceTransaction::query()->where('invoice_id', 480)->sole();
    }

    private function cronOn(string ...$days): void
    {
        foreach ($days as $day) {
            Carbon::setTestNow($day . ' 10:00:00');
            $this->artisan('app:cron-job')->run();
        }
    }

    /** The open invoices that bill service 31, other than #480. */
    private function renewalInvoices(): int
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', 31)->where('invoice_id', '!=', 480)
            ->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_UNPAID))->count();
    }

    /**
     * S8-MONEY-LEDGER-1. A full refund of an invoice paid in WHMCS: its line has no period, so givePeriodsUp gives it the
     * service's NEXT period (2026-10-10..11-10, the one after what the payment bought) and marks that forgone. The cron
     * never bills the renewal; the service is suspended with nothing the customer could pay.
     */
    public function test_s8_ml1_refunding_an_invoice_paid_in_whmcs_does_not_give_up_the_next_period(): void
    {
        $payment = $this->paidInWhmcs();

        ExtensionHelper::refundLocally($payment, 10.0, notify: false);
        $this->assertSame(Invoice::STATUS_REFUNDED, Invoice::query()->findOrFail(480)->status);

        $line = InvoiceItem::query()->where('invoice_id', 480)->where('reference_type', Service::class)->sole();
        $this->assertFalse($line->forgone_at !== null && $line->period_start?->toDateString() === '2026-10-10',
            'the refund of the period WHMCS already delivered gave up the period after it (2026-10-10..)');

        $this->cronOn('2026-10-04', '2026-10-10');
        $this->assertSame(1, $this->renewalInvoices(), 'the renewal from 2026-10-10 is never billed');
    }

    /** S8-MONEY-LEDGER-1, its end: suspended for non-payment with no invoice the customer could pay. */
    public function test_s8_ml1_the_service_is_not_suspended_with_nothing_to_pay(): void
    {
        $payment = $this->paidInWhmcs();
        ExtensionHelper::refundLocally($payment, 10.0, notify: false);

        $this->cronOn('2026-10-04', '2026-10-10', '2026-10-19', '2026-10-20');

        $suspended = Service::query()->findOrFail(31)->status === Service::STATUS_SUSPENDED;
        $this->assertFalse($suspended && $this->renewalInvoices() === 0, 'suspended, and no open invoice bills it');
    }

    /**
     * S8-MONEY-LEDGER-1, second way in: a chargeback reopens #480 (its line marked applied, still no period), staff then
     * cancel it inside the invoice days: the applied line takes 2026-10-10..11-10 and is given up.
     */
    public function test_s8_ml1_cancelling_a_charged_back_whmcs_invoice_does_not_give_up_the_next_period(): void
    {
        $payment = $this->paidInWhmcs();
        (new RecordPaymentService)->deleteTransaction($payment);
        $invoice = Invoice::query()->findOrFail(480);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertNotNull(InvoiceItem::query()->where('invoice_id', 480)->sole()->applied_at, 'precondition: marked applied before MyCS');

        Carbon::setTestNow('2026-10-06 10:00:00');
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        $line = InvoiceItem::query()->where('invoice_id', 480)->where('reference_type', Service::class)->sole();
        $this->assertFalse($line->forgone_at !== null && $line->period_start?->toDateString() === '2026-10-10',
            'cancelling the old invoice gave up the period after the one it had paid');
        $this->cronOn('2026-10-06', '2026-10-10');
        $this->assertSame(1, $this->renewalInvoices(), 'the renewal from 2026-10-10 is never billed');
    }

    /**
     * S8-MONEY-LEDGER-2. The payment of an invoice paid in WHMCS is deleted: #480 is unpaid again, its line applied with
     * no period, and the service runs to 2026-10-10. No rule of service_unbilled reads such a line.
     */
    public function test_s8_ml2_a_service_running_on_a_whmcs_invoice_unpaid_again_is_told(): void
    {
        $payment = $this->paidInWhmcs();

        (new RecordPaymentService)->deleteTransaction($payment);

        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail(480)->status);
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString());
        $this->assertSame([UnbilledServices::PERIOD_NOT_PAID], array_column((new UnbilledServices)->find(), 'kind'),
            'runs to 2026-10-10 on an invoice that is unpaid again, and nothing tells staff');
    }

    /** S8-MONEY-LEDGER-2, by a chargeback told by the gateway. */
    public function test_s8_ml2_a_service_running_on_a_charged_back_whmcs_invoice_is_told(): void
    {
        $this->paidInWhmcs();

        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'tr_480', 'dp_1', 10.0, 'EUR'));

        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail(480)->status);
        $this->assertSame([UnbilledServices::PERIOD_NOT_PAID], array_column((new UnbilledServices)->find(), 'kind'),
            'charged back, the service runs on, and nothing tells staff');
    }
}
