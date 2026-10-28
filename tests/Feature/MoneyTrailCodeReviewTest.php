<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\ApiKey;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\MoneyEvent;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MT-1a code review (docs/money-trail.md): the findings' tests stay as regression coverage.
 */
class MoneyTrailCodeReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function invoice(float $price = 100.00): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    /** P1: a processing payment is money on its way; its invoice must keep its customer. */
    public function test_a_customer_with_a_processing_payment_cannot_be_deleted(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 100.00, null, 'pi_sepa', currency: 'USD');

        $this->expectException(DisplayException::class);
        $this->user->client()->delete();
    }

    /** P1: credit set outside the journal (admin page, API, before the journal) is not lost by cascade. */
    public function test_a_customer_with_account_credit_but_no_events_cannot_be_deleted(): void
    {
        Credit::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'amount' => 25]);

        $client = $this->user->client();
        try {
            $client->delete();
            $this->fail('A customer with account credit was deleted.');
        } catch (DisplayException) {
        }

        $this->assertSame(1, Credit::query()->count());
        $this->assertNotNull($client->fresh());
    }

    public function test_a_customer_whose_credit_is_zero_and_has_no_payments_can_be_deleted(): void
    {
        Credit::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD', 'amount' => 0]);
        $invoice = $this->invoice();
        ExtensionHelper::addFailedPayment($invoice->id, 'Stripe', 100.00, null, 'pi_failed', currency: 'USD');

        $client = $this->user->client();
        $client->delete();

        $this->assertNull($client->fresh());
    }

    /**
     * P1 (regression): money landing on an invoice left without a customer (deleted before the journal) is still
     * recorded and journaled; nothing it cannot take becomes credit of nobody.
     */
    public function test_a_payment_on_an_invoice_without_a_customer_is_still_recorded(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 100.00, null, 'pi_late', currency: 'USD');
        DB::table('invoices')->where('id', $invoice->id)->update(['client_id' => null]); // as set null on a past delete

        $transaction = ExtensionHelper::addPayment($invoice->id, 'Stripe', 120.00, null, 'pi_late', currency: 'USD');

        $this->assertSame(InvoiceTransactionStatus::Succeeded, $transaction?->fresh()->status);
        $this->assertSame('0.00', $transaction->fresh()->credited_amount);
        $event = MoneyEvent::query()->sole();
        $this->assertNull($event->client_id);
        $this->assertSame('payment', $event->kind->value);
        $this->assertSame(0, Credit::query()->count());
    }

    public function test_the_admin_api_refuses_to_delete_a_customer_with_money_history_with_422(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_api', currency: 'USD');
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 'test', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token), 'permissions' => ['admin.users.delete']]);

        $this->withToken($token)->deleteJson(route('api.v1.admin.users.destroy', $this->user))->assertStatus(422);

        $this->assertNotNull($this->user->fresh());
    }

    /** P2: what staff type as a manual transaction id can name the payer; it never enters the journal. */
    public function test_a_manual_transaction_id_is_not_copied_into_the_journal(): void
    {
        $invoice = $this->invoice();

        $transaction = ExtensionHelper::addPayment($invoice->id, null, 100.00, null, 'Bank – Some Payer Name');
        ExtensionHelper::refundLocally($transaction, 10.00);

        $this->assertSame([null, null], MoneyEvent::query()->orderBy('id')->pluck('external_reference')->all());
        $this->assertSame('Bank – Some Payer Name', $transaction->fresh()->transaction_id);
    }

    /** Nit: staff completing a gateway's processing payment keep the gateway as the source. */
    public function test_staff_completing_a_processing_gateway_payment_keep_the_gateway_source(): void
    {
        $invoice = $this->invoice();
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 100.00, null, 'pi_proc', currency: 'USD');

        (new RecordPaymentService)->handle($invoice->id, null, 100.00, transactionId: 'pi_proc');

        $this->assertSame(['stripe', 'pi_proc'], [MoneyEvent::query()->sole()->source, MoneyEvent::query()->sole()->external_reference]);
    }

    /** Nit: one sale's credit paying other invoices is grouped under that sale. */
    public function test_an_allocation_from_one_sale_is_grouped_under_it(): void
    {
        config(['settings.credits_auto_use' => false]); // else the overpayment's credit pays the second invoice itself (MT-1e)
        $first = $this->invoice(10.00);
        $second = $this->invoice(10.00);
        $sale = ExtensionHelper::addPayment($first->id, 'Stripe', 20.00, null, 'sale_g', currency: 'USD');

        (new RecordPaymentService)->allocateFromTransaction($sale, $second);

        $this->assertSame(
            ['transaction:' . $sale->id, 'transaction:' . $sale->id],
            MoneyEvent::query()->where('invoice_id', $second->id)->pluck('group_reference')->all(),
        );
    }

    /** Nit: a refund of more than two decimals is rounded once, the same in the transaction, the note and the journal. */
    public function test_a_refund_is_rounded_once_for_the_transaction_the_note_and_the_journal(): void
    {
        $invoice = $this->invoice();
        $transaction = ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, null, 'pi_round', currency: 'USD');

        ExtensionHelper::refundLocally($transaction, 10.005);

        $journaled = MoneyEvent::query()->where('kind', 'refund')->value('amount');
        $this->assertSame($journaled, $transaction->fresh()->refunded_amount);
        $this->assertSame('-' . $journaled, number_format((float) $invoice->adjustmentNotes()->value('amount'), 2, '.', ''));
    }
}
