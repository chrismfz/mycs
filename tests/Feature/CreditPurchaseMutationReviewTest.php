<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/** Follow-up review of PR39: cached ownership and credit purchase mutations. */
class CreditPurchaseMutationReviewTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->beforeApplicationDestroyed(fn () => $this->truncateDatabaseTables());
    }

    public function test_reassigning_loaded_credit_invoice_and_marking_paid_grants_the_current_owner(): void
    {
        $oldOwner = User::factory()->create();
        $newOwner = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $oldOwner->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => 50]);
        $invoice->load('client');

        // MT-1c: staff mark it paid by recording the payment (its status alone no longer does), after moving it.
        $invoice->update(['client_id' => $newOwner->client()->id]);
        (new RecordPaymentService)->recordByStaff($invoice, null, '50.00');

        $this->assertSame($newOwner->client()->id, $invoice->fresh()->client_id);
        $this->assertSame(['old_owner' => '0.00', 'new_owner' => '50.00'], [
            'old_owner' => $this->balance($oldOwner),
            'new_owner' => $this->balance($newOwner),
        ], 'Credit must follow the current foreign key, not the cached user relationship');
    }

    public function test_reassigning_loaded_credit_invoice_with_existing_target_credit_grants_current_owner(): void
    {
        $oldOwner = User::factory()->create();
        $newOwner = User::factory()->create();
        Credit::create(['client_id' => $newOwner->client()->id, 'currency_code' => 'USD', 'amount' => 10]);
        $invoice = Invoice::factory()->create(['client_id' => $oldOwner->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => 50]);
        $invoice->load('client');

        // MT-1c: staff mark it paid by recording the payment (its status alone no longer does), after moving it.
        $invoice->update(['client_id' => $newOwner->client()->id]);
        (new RecordPaymentService)->recordByStaff($invoice, null, '50.00');

        $this->assertSame('60.00', number_format((float) Credit::where('client_id', $newOwner->client()->id)->where('currency_code', 'USD')->sum('amount'), 2, '.', ''));
        $this->assertSame(0, Credit::where('client_id', $oldOwner->client()->id)->count());
    }

    public function test_overpaid_credit_purchase_refund_preserves_only_excess_credit(): void
    {
        $owner = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $owner->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => 50]);
        $payment = ExtensionHelper::addPayment($invoice->id, null, 70, transactionId: 'REVIEW-OVERPAY');
        $this->assertSame('20.00', $payment->fresh()->credited_amount);
        $this->assertSame('70.00', $this->balance($owner));

        ExtensionHelper::refundLocally($payment, 50);

        $this->assertSame('20.00', $this->balance($owner));
        $this->assertSame('50.00', $payment->fresh()->refunded_amount);
    }

    public function test_deleting_overpaid_credit_purchase_removes_bought_and_excess_credit(): void
    {
        $owner = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $owner->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => 50]);
        $payment = ExtensionHelper::addPayment($invoice->id, null, 70, transactionId: 'REVIEW-DELETE');
        (new RecordPaymentService)->deleteTransaction($payment);

        $this->assertNull($payment->fresh());
        $this->assertSame('0.00', $this->balance($owner));
    }

    public function test_credit_funded_purchase_and_deletion_do_not_change_available_credit(): void
    {
        $owner = User::factory()->create();
        Credit::create(['client_id' => $owner->client()->id, 'currency_code' => 'USD', 'amount' => 60]);
        $invoice = Invoice::factory()->create(['client_id' => $owner->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => 50]);
        $payments = new RecordPaymentService;
        $payment = $payments->payWithCredit($invoice);
        $this->assertNotNull($payment);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('60.00', $this->balance($owner));

        $payments->deleteTransaction($payment);

        $this->assertSame('60.00', $this->balance($owner));
    }

    public function test_multiple_credit_lines_are_granted_once_and_refunded_together(): void
    {
        $owner = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $owner->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        foreach ([20, 30] as $price) {
            $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => $price]);
        }
        $payment = ExtensionHelper::addPayment($invoice->id, null, 50, transactionId: 'REVIEW-LINES');
        $this->assertSame('50.00', $this->balance($owner));
        $this->assertSame(2, $invoice->items()->whereNotNull('applied_at')->count());
        $invoice->fresh()->update(['status' => 'unpaid']);
        $invoice->fresh()->update(['status' => 'paid']);
        $this->assertSame('50.00', $this->balance($owner));

        ExtensionHelper::refundLocally($payment, 50);

        $this->assertSame('0.00', $this->balance($owner));
    }

    private function balance(User $owner): string
    {
        return number_format((float) Credit::where('client_id', $owner->client()->id)->where('currency_code', 'USD')->sum('amount'), 2, '.', '');
    }
}
