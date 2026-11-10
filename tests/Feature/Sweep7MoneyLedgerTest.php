<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Sweep 7, money-ledger: adversarial verification (tests fail while the finding holds). */
class Sweep7MoneyLedgerTest extends TestCase
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

    private function paidAtStripe(): InvoiceTransaction
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100]);
        ExtensionHelper::addPayment($invoice->fresh(), 'Stripe', 100, null, 'pi_1');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        return InvoiceTransaction::query()->where('invoice_id', $invoice->id)->sole();
    }

    /**
     * Staff delete the payment: it goes (with whatever the fix does with its gateway records), or it is refused with a
     * message staff can read. Never a raw database error.
     */
    private function deleteAsStaff(InvoiceTransaction $payment): void
    {
        try {
            (new RecordPaymentService)->deleteTransaction($payment);
        } catch (DisplayException) {
            $this->assertNotNull($payment->fresh(), 'refused, so the payment stays');

            return;
        } catch (QueryException $e) {
            $this->fail('deleting the payment ended in a database error instead of a deletion or a refusal staff can read: ' . $e->getMessage());
        }
        $this->assertNull($payment->fresh());
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail($payment->invoice_id)->status);
    }

    /**
     * S7-MONEY-LEDGER-1. A dispute that was only opened (no money moved, refunded_amount 0) leaves a gateway_refunds
     * row pointing at the payment; its foreign key has no ON DELETE, so the deletion passes every guard and dies on
     * the DELETE.
     */
    public function test_s7_money_ledger_1_a_payment_with_an_opened_dispute_is_deleted_or_refused_not_a_database_error(): void
    {
        $payment = $this->paidAtStripe();
        ExtensionHelper::chargebackOpened('Stripe', 'pi_1', 'dp_1', 100, 'usd');
        $this->assertSame(1, GatewayRefund::query()->where('invoice_transaction_id', $payment->id)->count());
        $this->assertEqualsWithDelta(0.0, (float) $payment->fresh()->refunded_amount, 0.001);

        $this->deleteAsStaff($payment->fresh());
    }

    /** The same after the dispute was lost and then won back: refunded_amount is 0 again, its records stay. */
    public function test_s7_money_ledger_1_a_payment_whose_dispute_was_won_is_deleted_or_refused_not_a_database_error(): void
    {
        $payment = $this->paidAtStripe();
        ExtensionHelper::chargebackOpened('Stripe', 'pi_1', 'dp_1', 100, 'usd');
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_1', 'dp_1', 100, 'usd'));
        $this->assertTrue(ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_1', 'dp_1', 100, 'usd'));
        $this->assertEqualsWithDelta(0.0, (float) $payment->fresh()->refunded_amount, 0.001);
        $this->assertGreaterThan(0, GatewayRefund::query()->where('invoice_transaction_id', $payment->id)->count());

        $this->deleteAsStaff($payment->fresh());
    }
}
