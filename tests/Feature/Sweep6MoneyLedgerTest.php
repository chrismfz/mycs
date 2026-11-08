<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteType;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Service\DeleteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Sweep 6, money-ledger: adversarial verification (tests fail while the finding holds). */
class Sweep6MoneyLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.credits_enabled' => true,
            'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
    }

    /**
     * S6-MONEY-LEDGER-1. An Add Funds invoice of 100 with a -20 credit note (allowed while unpaid) shows 80 to pay. The
     * customer pays 80 at Stripe: the listener marks it paid, InvoiceObserver refuses (credit 100 > received 80), and the
     * exception rolls the whole payment back. The card was charged; nothing is recorded.
     */
    public function test_s6_money_ledger_1_a_gateway_payment_of_a_discounted_credit_purchase_is_recorded(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Add funds', 'quantity' => 1, 'price' => 100, 'reference_type' => Credit::class]);
        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -20, 'description' => 'Discount']);
        $this->assertEqualsWithDelta(80.0, (float) $invoice->fresh()->remaining, 0.001, 'precondition: 80 to pay');

        $thrown = null;
        try {
            ExtensionHelper::addPayment($invoice->id, 'Stripe', 80.00, transactionId: 'pi_disc', currency: 'usd');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'pi_disc')->count(),
            'the 80 the card paid is not recorded: ' . ($thrown === null ? 'no exception' : get_class($thrown) . ': ' . $thrown->getMessage()));
    }

    /** A paid invoice of a service, by Stripe 'pi_1'. */
    private function paidServiceInvoice(): array
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 100.00]);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, transactionId: 'pi_1', currency: 'usd');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'precondition: paid');

        return [$invoice, $service];
    }

    private function assertChargebackRecorded(Invoice $invoice): void
    {
        $thrown = null;
        try {
            ExtensionHelper::chargebackAtGateway('Stripe', 'pi_1', 'dp_1', 100.00, 'usd');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $payment = InvoiceTransaction::query()->where('transaction_id', 'pi_1')->sole();
        $this->assertSame(1, GatewayRefund::query()->where('reference', 'dp_1')->count(),
            'the chargeback is not recorded: ' . ($thrown === null ? 'no exception' : get_class($thrown) . ': ' . $thrown->getMessage()));
        $this->assertEqualsWithDelta(100.0, (float) $payment->refunded_amount, 0.001, 'the money the bank took is not on the payment');
    }

    /** S6-MONEY-LEDGER-2a. Its service moved to another customer: the chargeback rolls back. */
    public function test_s6_money_ledger_2_a_chargeback_is_recorded_after_its_service_moved(): void
    {
        [$invoice, $service] = $this->paidServiceInvoice();
        (new MoveToClient)->service($service->fresh(), User::factory()->create()->client(), $this->staff());
        $this->assertSame($this->user->client()->id, (int) $invoice->fresh()->client_id, 'precondition: the paid invoice stays');

        $this->assertChargebackRecorded($invoice);
    }

    /** S6-MONEY-LEDGER-2b. Its service deleted (paid lines kept): the chargeback rolls back. */
    public function test_s6_money_ledger_2_a_chargeback_is_recorded_after_its_service_was_deleted(): void
    {
        [$invoice, $service] = $this->paidServiceInvoice();
        (new DeleteService)->handle($service->fresh());
        $this->assertNull(Service::query()->find($service->id), 'precondition: deleted');

        $this->assertChargebackRecorded($invoice);
    }
}
