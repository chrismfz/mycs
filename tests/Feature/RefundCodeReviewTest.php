<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteType;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Code review of PR 2c-1 (refunds). Each failing test is a finding.
 */
class RefundCodeReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    private function invoice(float $price = 100, array $line = []): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_PENDING, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(array_merge(['description' => 'Hosting', 'quantity' => 1, 'price' => $price], $line));

        return $invoice->fresh();
    }

    private function paid(float $price = 100): Invoice
    {
        $invoice = $this->invoice($price);
        (new RecordPaymentService)->handle($invoice, null, $price, transactionId: 'wire-' . $invoice->id);

        return $invoice->fresh();
    }

    private function balance(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->value('amount') ?? 0), 2, '.', '');
    }

    private function assertBooksClean(): void
    {
        $findings = (new MoneyCheck)->run();
        $this->assertSame([], $findings, json_encode($findings));
    }

    /** Finding 1: with auto-apply on (the default), the credit goes straight back onto the same open invoice. */
    public function test_refund_to_credit_then_cancel_works_with_credit_auto_apply_on(): void
    {
        config(['settings.credits_auto_use' => true]);
        User::whereKey($this->user->id)->update(['credit_auto_apply' => true]);
        $part = $this->invoice(100);
        (new RecordPaymentService)->handle($part, null, 40.0, transactionId: 'wire-part');

        ExtensionHelper::refundToCredit($part->transactions()->sole(), 40.0);

        $this->assertSame(1, $part->transactions()->count(), 'the refunded credit was paid back onto the same invoice');
        $part->fresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Customer asked']);
        $this->assertSame([Invoice::STATUS_CANCELLED, '40.00'], [$part->fresh()->status, $this->balance()]);
    }

    /** Finding 1b: an open invoice with a credit payment cannot be cancelled by hand; the advised remedy loops. */
    public function test_an_open_invoice_with_a_credit_payment_can_be_cancelled_by_staff_with_auto_apply_on(): void
    {
        config(['settings.credits_auto_use' => true]);
        User::whereKey($this->user->id)->update(['credit_auto_apply' => true]);
        (new CreditLedger)->add($this->user->id, 'USD', 3000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT,
            MoneyTrail::SOURCE_STAFF, note: 'Goodwill');
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->payWithCredit($invoice);

        ExtensionHelper::refundToCredit($invoice->transactions()->sole(), 30.0); // as the refusal message says

        $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Not needed']);
        $this->assertSame([Invoice::STATUS_CANCELLED, '30.00'], [$invoice->fresh()->status, $this->balance()]);
    }

    /** Finding 2: deleting a payment refunded to credit leaves its refund note: a phantom discount. */
    public function test_deleting_a_payment_refunded_to_credit_leaves_no_discount_behind(): void
    {
        $invoice = $this->paid(100);
        $payment = $invoice->transactions()->sole();
        ExtensionHelper::refundToCredit($payment, 40.0);

        try {
            (new RecordPaymentService)->deleteTransaction($payment->fresh());
        } catch (DisplayException) {
            $this->assertSame(1, $invoice->transactions()->count());

            return; // refused, as for a refund back: acceptable
        }

        $invoice = $invoice->fresh();
        $this->assertSame([Invoice::STATUS_PENDING, 10000], [$invoice->status, RecordPaymentService::toCents($invoice->remaining)],
            'the payment never happened: the customer owes the whole invoice, not 60');
    }

    /** Finding 3: a refunded (final) invoice still takes new lines. */
    public function test_a_refunded_invoice_keeps_its_lines(): void
    {
        $invoice = $this->paid(50);
        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 50.0);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);

        try {
            $invoice->items()->create(['description' => 'Extra', 'quantity' => 1, 'price' => 20]);
        } catch (DisplayException) {
        }

        $this->assertBooksClean();
    }

    /** Finding 3b: a refunded (final) invoice still takes staff notes. */
    public function test_a_refunded_invoice_keeps_its_notes(): void
    {
        $invoice = $this->paid(50);
        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 50.0);

        try {
            $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Debit->value, 'amount' => 15, 'description' => 'Fee']);
        } catch (DisplayException) {
        }

        $this->assertBooksClean();
    }

    /** Finding 4: the cron / a cancellation request never cancels an open invoice whose payment went back. */
    public function test_cancel_open_invoices_cancels_one_whose_payment_was_refunded_to_credit(): void
    {
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->handle($invoice, null, 40.0, transactionId: 'wire-x');
        ExtensionHelper::refundToCredit($invoice->transactions()->sole(), 40.0);

        $left = CancelOpenInvoices::handle([$invoice->fresh()], 'Overdue');

        $this->assertSame([], array_map(fn ($e) => $e->getMessage(), $left));
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertBooksClean();
    }

    /** Finding 5: a refunded invoice's period still counts as billed: moved back, the service is never billed for it. */
    public function test_a_refunded_period_is_billed_again_when_staff_move_the_date_back(): void
    {
        $product = $this->createProduct();
        $start = Carbon::now()->addDays(3)->startOfDay();
        $service = Service::factory()->create(['user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => $start]);
        $invoice = $this->invoice(10, ['reference_type' => Service::class, 'reference_id' => $service->id,
            'period_start' => $start, 'period_end' => $start->copy()->addMonth()]);
        (new RecordPaymentService)->handle($invoice, null, 10.0, transactionId: 'wire-s');
        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 10.0);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);

        $service = $service->fresh();
        $service->forceFill(['expires_at' => $start])->save(); // staff take the refunded period back

        $this->assertSame($start->toDateString(), $service->fresh()->nextPeriodStart()->toDateString(),
            'the refunded period is a hole to bill again, as for a cancelled invoice');
    }
}
