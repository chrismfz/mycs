<?php

namespace Tests\Feature;

use App\Enums\AdjustmentNoteType;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Adversarial review of PR 2c-1 (refunds back, by hand, to account credit; status refunded; no cancel with money).
 * Written without the author's context. Failing tests are findings; the guard tests must keep holding.
 *
 * Findings:
 * - R1 (high)   A payment refunded (in part) to credit can still be deleted (deleteTransaction): the credit is taken
 *               back, but its is_refund note stays on the invoice as a permanent discount (the refunded_amount guard
 *               does not cover credited_amount from a refund to credit). Paid 100, 30 to credit, delete: owes 70.
 * - R1b (high)  Same, on a fully refunded-to-credit invoice: the payment is deleted, the credit taken back, and the
 *               invoice stays «refunded» with a -100 note: the period it renewed is free, nothing is owed, no check sees it.
 * - R2 (high)   Refund to credit on an OPEN invoice, with credit auto-apply on (the defaults): the afterCommit
 *               CreditAutoApply pays the same invoice straight back. The refund is silently undone, the money stays on
 *               the invoice, and staff can never cancel it (the documented «refund first, then cancel» loops forever).
 * - R3 (medium) CancelOpenInvoices (service cancellation, overdue cron, upgrade cancel) keeps an invoice open as
 *               «holds money a gateway paid» even after that payment was fully refunded / moved to credit: it looks at
 *               the payment row, not at what stays on it. The customer keeps being billed/dunned for a cancelled thing.
 * - R4 (medium) A cancelled invoice that holds money (pre-2c, imported: what cancelled_holds_money tells staff to
 *               refund) gets an is_refund note when refunded; reopened later, the cancellation note is voided but the
 *               refund note stays: the invoice is discounted by the refund (owes 60, not 100).
 * - R5 (low)    The PDF still prints the raw status (ucfirst: «Pending», «Refunded» in orange), not WHMCS's words
 *               (Invoice::statusLabel: «Unpaid»), unlike every other screen changed in 2c.
 */
class RefundAdversarialTest extends TestCase
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

    private function invoice(float $price = 100, ?string $reference = null): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(array_filter(['description' => 'Hosting', 'quantity' => 1, 'price' => $price,
            'reference_type' => $reference], fn ($value) => $value !== null));

        return $invoice->fresh();
    }

    private function paid(float $price = 100, ?float $amount = null): Invoice
    {
        $invoice = $this->invoice($price);
        (new RecordPaymentService)->handle($invoice, null, $amount ?? $price, transactionId: 'wire-' . $invoice->id);

        return $invoice->fresh();
    }

    private function balance(): string
    {
        return number_format((float) (Credit::query()->where('user_id', $this->user->id)->value('amount') ?? 0), 2, '.', '');
    }

    private function cents(mixed $amount): int
    {
        return RecordPaymentService::toCents($amount);
    }

    private function assertBooksClean(): void
    {
        $findings = (new MoneyCheck)->run();
        $this->assertSame([], $findings, json_encode($findings));
    }

    // ================================================================== findings (fail on this branch)

    /**
     * R1, high. Paid 100, 30 refunded to credit (note -30), then staff delete the payment as a mistake: the credit is
     * taken back, the invoice reopens, but the -30 refund note stays: it owes 70 for a 100 invoice.
     */
    public function test_r1_deleting_a_payment_refunded_to_credit_leaves_its_refund_note_as_a_discount(): void
    {
        $invoice = $this->paid(100);
        $payment = $invoice->transactions()->sole();
        ExtensionHelper::refundToCredit($payment, 30.0);

        try {
            (new RecordPaymentService)->deleteTransaction($payment->fresh());
        } catch (DisplayException) {
            $this->assertTrue(true, 'refused, as a refunded payment is');

            return;
        }

        $invoice = $invoice->fresh();
        $this->assertSame('0.00', $this->balance());
        $this->assertSame(10000, $this->cents($invoice->remaining),
            'the payment is gone, so the whole invoice is owed again; the refund note of a payment that no longer exists is a 30.00 discount');
    }

    /**
     * R1b, high. Paid 100, all of it refunded to credit (refunded), then the payment is deleted: the credit is taken
     * back, the invoice stays «refunded» with a -100 note and no payment. The period it renewed was given for nothing
     * and the money check is clean.
     */
    public function test_r1b_deleting_the_payment_of_a_refunded_invoice_gives_its_period_away(): void
    {
        $invoice = $this->paid(100);
        $payment = $invoice->transactions()->sole();
        ExtensionHelper::refundToCredit($payment, 100.0);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);

        try {
            (new RecordPaymentService)->deleteTransaction($payment->fresh());
        } catch (DisplayException) {
            $this->assertTrue(true, 'refused, as a refunded payment is');

            return;
        }

        $invoice = $invoice->fresh();
        $this->assertSame('0.00', $this->balance());
        $this->assertSame(10000, $this->cents($invoice->remaining),
            'no money arrived and none was given back: the invoice should owe its 100.00 (it is «' . $invoice->status . '», owing ' . $invoice->remaining . ')');
    }

    /**
     * R2, high. Open invoice 100 with 40 paid; staff refund the 40 to credit to cancel it (as the refusal says). With
     * auto-apply on (the default), the credit pays the same invoice straight back: the refund is undone and the
     * invoice still cannot be cancelled.
     */
    public function test_r2_refund_to_credit_on_an_open_invoice_is_paid_straight_back_into_it(): void
    {
        config(['settings.credits_auto_use' => true]);
        User::whereKey($this->user->id)->update(['credit_auto_apply' => true]);
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->handle($invoice, null, 40.0, transactionId: 'wire-part');

        ExtensionHelper::refundToCredit($invoice->transactions()->sole(), 40.0);

        $this->assertSame('40.00', $this->balance(), 'the refund to credit stays credit, not paid back into the invoice it left');
        $this->assertSame(10000, $this->cents($invoice->fresh()->remaining));
        $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Customer asked']);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    /**
     * R2 (credit payment variant), high. Open invoice 100, 40 paid with credit; a credit payment goes back to credit
     * only, and auto-apply puts it right back: staff can never cancel an invoice paid in part with credit from the admin.
     */
    public function test_r2b_a_credit_payment_refunded_to_credit_comes_straight_back(): void
    {
        config(['settings.credits_auto_use' => true]);
        User::whereKey($this->user->id)->update(['credit_auto_apply' => false]);
        (new CreditLedger)->add($this->user->id, 'USD', 4000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Goodwill');
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->payWithCredit($invoice);
        $this->assertSame('0.00', $this->balance());
        User::whereKey($this->user->id)->update(['credit_auto_apply' => true]);

        $payment = $invoice->transactions()->sole();
        ExtensionHelper::refundToCredit($payment, 40.0);

        $this->assertSame('40.00', $this->balance(), 'the credit went back to the customer, not onto the same invoice again');
        $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Customer asked']);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertBooksClean();
    }

    /**
     * R3, medium. An open invoice with gateway money is left open by CancelOpenInvoices; staff refund the money (to
     * credit here), as asked. The next cancellation (cron, cancellation request) still leaves it open: it looks at the
     * payment row, not at what stays on the invoice.
     */
    public function test_r3_cancel_open_invoices_keeps_an_invoice_open_whose_gateway_money_already_went_back(): void
    {
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->handle($invoice, Gateway::query()->sole(), 40.0, transactionId: 'pi_part');
        $this->assertNotEmpty(CancelOpenInvoices::handle([$invoice->fresh()], 'Overdue'), 'gateway money on it: left open');

        ExtensionHelper::refundToCredit($invoice->transactions()->sole(), 40.0);
        $left = CancelOpenInvoices::handle([$invoice->fresh()], 'Overdue');

        $this->assertSame([], array_map(fn ($e) => $e->getMessage(), $left), 'nothing stays on it any more');
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertBooksClean();
    }

    /**
     * R4, medium. A cancelled invoice holding 40 (cancelled before 2c, or imported: cancelled_holds_money) is refunded
     * as the check asks. Reopened later, its cancellation note is voided, its refund note stays: it owes 60 for 100.
     */
    public function test_r4_a_refunded_cancelled_invoice_reopened_is_discounted_by_the_refund(): void
    {
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->handle($invoice, null, 40.0, transactionId: 'wire-legacy');
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_CANCELLED]);
        $invoice->fresh()->createCancellationCreditNote('Cancelled before 2c');
        $this->assertSame(['cancelled_holds_money'], array_column((new MoneyCheck)->run(['cancelled_holds_money']), 'rule'));

        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 40.0);
        $this->assertBooksClean();

        $invoice->fresh()->update(['status' => Invoice::STATUS_UNPAID]);
        $this->assertSame(10000, $this->cents($invoice->fresh()->remaining),
            'reopened with no money on it: the whole invoice is owed, the refund of money it no longer holds is no discount');
    }

    /** R5, low. The PDF prints the raw status, not WHMCS's words (Unpaid). */
    public function test_r5_the_pdf_reads_the_status_as_whmcs(): void
    {
        $invoice = $this->invoice(100);

        $html = view('pdf.invoice', ['invoice' => $invoice])->render();

        $this->assertStringContainsString(Invoice::statusLabel(Invoice::STATUS_UNPAID), $html);
    }

    // ================================================================== guards (must keep holding)

    /** Refunds back and to credit together never exceed the payment, and the books add up. */
    public function test_guard_mixed_refunds_never_exceed_the_payment(): void
    {
        $invoice = $this->paid(100);
        $payment = $invoice->transactions()->sole();
        ExtensionHelper::refundLocally($payment, 60.0);
        ExtensionHelper::refundToCredit($payment->fresh(), 30.0);

        foreach ([fn () => ExtensionHelper::refundToCredit($payment->fresh(), 10.01), fn () => ExtensionHelper::refundLocally($payment->fresh(), 10.01)] as $over) {
            try {
                $over();
                $this->fail('refunded over the payment');
            } catch (InvalidArgumentException) {
            }
        }
        ExtensionHelper::refundToCredit($payment->fresh(), 10.0);

        $this->assertSame([Invoice::STATUS_REFUNDED, '40.00'], [$invoice->fresh()->status, $this->balance()]);
        $this->assertBooksClean();
    }

    /** A refunded invoice cannot be cancelled, reopened or marked paid; a paid one is not cancelled. */
    public function test_guard_refunded_is_final_and_paid_is_not_cancelled(): void
    {
        $invoice = $this->paid(50);
        try {
            $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'x']);
            $this->fail('a paid invoice was cancelled');
        } catch (DisplayException) {
        }
        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 50.0);
        foreach ([Invoice::STATUS_CANCELLED, Invoice::STATUS_UNPAID, Invoice::STATUS_PAID, Invoice::STATUS_DRAFT] as $status) {
            try {
                $invoice->fresh()->update(['status' => $status, 'cancellation_reason' => 'x']);
                $this->fail("a refunded invoice became {$status}");
            } catch (DisplayException) {
            }
        }
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->assertBooksClean();
    }

    /** Overpaid (credited_amount from the overpayment), then all of what stayed refunded: refunded, credit kept. */
    public function test_guard_an_overpaid_invoice_refunded_in_full(): void
    {
        $invoice = $this->paid(100, 120);
        $payment = $invoice->transactions()->sole();
        $this->assertSame('20.00', $this->balance());
        $this->assertSame(10000, $this->cents($payment->refundable_amount), 'the part that became credit is not refundable');

        ExtensionHelper::refundToCredit($payment, 50.0);
        ExtensionHelper::refundLocally($payment->fresh(), 50.0);

        $this->assertSame([Invoice::STATUS_REFUNDED, '70.00'], [$invoice->fresh()->status, $this->balance()]);
        $this->assertBooksClean();
    }

    /** Closed as paid (a discount took it under its payment), then refunded in full. */
    public function test_guard_closed_as_paid_then_refunded(): void
    {
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->handle($invoice, null, 80.0, transactionId: 'wire-close-2');
        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -30, 'description' => 'Goodwill']);
        $this->assertTrue((new RecordPaymentService)->settle($invoice->id));
        $this->assertSame([Invoice::STATUS_PAID, '10.00'], [$invoice->fresh()->status, $this->balance()]);

        ExtensionHelper::refundToCredit($invoice->transactions()->sole(), 70.0);

        $this->assertSame([Invoice::STATUS_REFUNDED, '80.00'], [$invoice->fresh()->status, $this->balance()]);
        $this->assertBooksClean();
    }

    /** A credit payment and a gateway payment on one paid invoice: each back its own way; refunded; books clean. */
    public function test_guard_credit_and_gateway_payment_on_one_invoice(): void
    {
        (new CreditLedger)->add($this->user->id, 'USD', 3000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Goodwill');
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->payWithCredit($invoice);
        (new RecordPaymentService)->handle($invoice, null, 70.0, transactionId: 'wire-mix');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        [$credit, $wire] = [$invoice->transactions()->where('is_credit_transaction', true)->sole(), $invoice->transactions()->where('is_credit_transaction', false)->sole()];

        try {
            ExtensionHelper::refundLocally($credit, 30.0);
            $this->fail('credit refunded as money');
        } catch (InvalidArgumentException) {
        }
        ExtensionHelper::refundToCredit($credit, 30.0);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        ExtensionHelper::refundLocally($wire, 70.0);

        $this->assertSame([Invoice::STATUS_REFUNDED, '30.00'], [$invoice->fresh()->status, $this->balance()]);
        $this->assertBooksClean();
    }

    /** A customer-less invoice is not refunded to credit, and nothing moves. */
    public function test_guard_no_customer_no_refund_to_credit(): void
    {
        $invoice = $this->paid(40);
        DB::table('invoices')->where('id', $invoice->id)->update(['user_id' => null]);

        try {
            ExtensionHelper::refundToCredit($invoice->transactions()->sole(), 40.0);
            $this->fail('refunded to nobody\'s credit');
        } catch (InvalidArgumentException) {
        }
        $this->assertSame(0, $this->cents($invoice->transactions()->sole()->credited_amount));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** A partial refund to credit on an open invoice: no note, its balance grows back, it stays open, then cancels. */
    public function test_guard_partial_refund_to_credit_on_an_open_invoice(): void
    {
        $invoice = $this->invoice(100);
        (new RecordPaymentService)->handle($invoice, null, 60.0, transactionId: 'wire-open');
        $payment = $invoice->transactions()->sole();

        ExtensionHelper::refundToCredit($payment, 25.0);
        $this->assertSame([Invoice::STATUS_UNPAID, 6500, 0], [$invoice->fresh()->status, $this->cents($invoice->fresh()->remaining), $invoice->adjustmentNotes()->count()]);
        try {
            $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'x']);
            $this->fail('cancelled with 35.00 on it');
        } catch (DisplayException) {
        }
        ExtensionHelper::refundToCredit($payment->fresh(), 35.0);
        $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'x']);

        $this->assertSame([Invoice::STATUS_CANCELLED, '60.00'], [$invoice->fresh()->status, $this->balance()]);
        $this->assertBooksClean();
    }
}
