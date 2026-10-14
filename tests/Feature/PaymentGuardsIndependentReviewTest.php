<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Admin\Resources\InvoiceTransactions\Pages\ListInvoiceTransactions;
use App\Enums\AdjustmentNoteStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Price;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Models\Staff;
use App\Services\Invoice\RecordPaymentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Independent adversarial review of PR 2a (commits 92246c5..8a95af7, docs/payments.md). Every test here
 * fails on 8a95af7 and describes the expected (fixed) behaviour.
 */
class PaymentGuardsIndependentReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00:00');
        config(['settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 10.00]);

        return Service::factory()->create(array_merge([
            'user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-10-15', 'currency_code' => 'USD', 'price' => 10.00,
        ], $attributes));
    }

    private function invoiceFor(Service $service): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10.00, 'period_start' => '2026-10-15', 'period_end' => '2026-11-15',
        ]);

        return $invoice->fresh();
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->where('currency_code', 'USD')->value('amount') ?? 0), 2, '.', '');
    }

    private function admin(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);
    }

    /**
     * Admin > Invoices > Transactions (the global list, InvoiceTransactionsTable) still has a plain DeleteAction:
     * it bypasses RecordPaymentService::deleteTransaction. Invoice 10, wire of 100 recorded by mistake -> 90 credit;
     * deleting it from the global list leaves the 90 credit with no money behind it.
     */
    public function test_deleting_from_the_global_transactions_list_takes_back_the_credit(): void
    {
        $invoice = $this->invoiceFor($this->service());
        ExtensionHelper::addPayment($invoice->id, null, 100.00, null, 'wire-typo');
        $this->assertSame('90.00', $this->credit());
        $transaction = InvoiceTransaction::query()->sole();

        $this->livewireAs($this->admin())->test(ListInvoiceTransactions::class)
            ->callTableAction('delete', $transaction);

        $deleted = InvoiceTransaction::query()->whereKey($transaction->id)->doesntExist();
        $this->assertSame($deleted ? '0.00' : '90.00', $this->credit(), 'deleted: the 90 credit must go with it (or the deletion is refused)');
    }

    /**
     * Deleting the invoice (EditInvoice > Delete) cascades its transactions in the database: no model event, no
     * deleteTransaction. Invoice 10 paid with 30 (20 became credit): after the deletion the 20 credit stays,
     * with the transaction that produced it gone.
     */
    public function test_deleting_an_invoice_does_not_leave_the_credit_of_its_payments(): void
    {
        $invoice = $this->invoiceFor($this->service());
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 30.00, null, 'pi_over', currency: 'usd');
        $this->assertSame('20.00', $this->credit());

        try {
            $invoice->fresh()->delete(); // what EditInvoice's DeleteAction does
        } catch (\Throwable) {
            // refusing is a valid fix
        }

        $deleted = InvoiceTransaction::query()->where('transaction_id', 'pi_over')->doesntExist();
        $this->assertSame($deleted ? '0.00' : '20.00', $this->credit(), 'the credit of a deleted payment must be taken back (or the deletion refused)');
    }

    /**
     * deleteTransaction on a refunded payment keeps the refund's credit note: the invoice total stays reduced by
     * a refund of a transaction that no longer exists. Invoice 10, paid 10, refunded 4 (note -4), payment deleted:
     * the invoice asks 6 for a 10 line (reopen it and the customer pays 6).
     */
    public function test_deleting_a_refunded_transaction_does_not_leave_its_refund_note(): void
    {
        $invoice = $this->invoiceFor($this->service());
        $payment = ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_ref', currency: 'usd');
        ExtensionHelper::refundLocally($payment->fresh(), 4.00);

        try {
            (new RecordPaymentService)->deleteTransaction($payment->fresh());
        } catch (DisplayException) {
            $this->assertTrue(InvoiceTransaction::query()->whereKey($payment->id)->exists());

            return; // refusing is a valid fix
        }

        $invoice = $invoice->fresh();
        $this->assertSame(0, $invoice->adjustmentNotes()->where('status', AdjustmentNoteStatus::Active->value)->count(), 'orphan refund note');
        $this->assertEquals(10.00, $invoice->remaining, 'nothing paid, nothing refunded: 10 is owed');
    }

    /**
     * Staff record a payment the PayPal webhook already brought, but leave the (optional) gateway empty: the
     * duplicate check compares gateway_id, so it is recorded again and, the invoice being paid, all 10 becomes credit.
     */
    public function test_a_staff_transaction_without_gateway_is_deduplicated_against_a_gateway_payment(): void
    {
        $invoice = $this->invoiceFor($this->service());
        ExtensionHelper::addPayment($invoice->id, 'PayPal', 10.00, null, '5TY05013RG002845M', currency: 'USD');

        $this->livewireAs($this->admin())
            ->test(TransactionsRelationManager::class, ['ownerRecord' => $invoice->fresh(), 'pageClass' => EditInvoice::class])
            ->callTableAction('create', data: ['amount' => '10.00', 'transaction_id' => '5TY05013RG002845M']);

        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', '5TY05013RG002845M')->count());
        $this->assertSame('0.00', $this->credit());
    }

    /**
     * refundable_amount is float arithmetic on decimal strings: 10.00 - 6.03 = 3.9699999999999998 < 3.97. Paid 10.00,
     * refunded 6.03, the remaining 3.97 can never be refunded (helper and admin form both refuse).
     */
    public function test_the_exact_remaining_amount_can_be_refunded(): void
    {
        $invoice = $this->invoiceFor($this->service());
        $payment = ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_float', currency: 'usd');
        ExtensionHelper::refundLocally($payment->fresh(), 6.03);

        ExtensionHelper::refundLocally($payment->fresh(), 3.97);

        $this->assertEquals(10.00, (float) $payment->fresh()->refunded_amount);
    }

    /**
     * Pre-existing (outside 92246c5..8a95af7, but on the refund guard this PR changed): a credit purchase
     * (invoice line of type Credit, 50) paid by card gives 50 account credit; the payment stays fully refundable,
     * so a refund of 50 returns the money while the 50 credit stays. Money in 50 = refunded 50 + credit 50.
     */
    public function test_refunding_a_credit_purchase_does_not_leave_the_credit(): void
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Credit deposit', 'quantity' => 1, 'price' => 50.00]);
        $payment = ExtensionHelper::addPayment($invoice->id, 'Stripe', 50.00, null, 'pi_deposit', currency: 'usd');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('50.00', $this->credit());

        try {
            ExtensionHelper::refundLocally($payment->fresh(), 50.00);
        } catch (\Throwable) {
            // refusing the refund is a valid fix
        }

        $refunded = (float) $payment->fresh()->refunded_amount;
        $this->assertLessThanOrEqual(50.00, $refunded + (float) $this->credit(), 'refunded + credit left must not exceed the 50 paid');
    }
}
