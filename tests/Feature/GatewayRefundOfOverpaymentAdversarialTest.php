<?php

namespace Tests\Feature;

use App\Enums\MoneyEventKind;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Adversarial review of #552 (a dashboard refund takes back first the credit its payment left). test_bug_* fail on the
 * PR's head and name a hole; test_holds_* are guarantees attacked that held.
 */
class GatewayRefundOfOverpaymentAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const STAFF_SUBJECT = 'Gateway refund needs staff attention';

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

    private function invoice(float $price = 100, array $line = []): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => now()->addWeek()]);
        $invoice->items()->create($line + ['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function credit(): float
    {
        return (float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    private function payment(string $reference = 'pi_over'): InvoiceTransaction
    {
        return InvoiceTransaction::query()->where('transaction_id', $reference)->sole();
    }

    private function staffMails(): int
    {
        return EmailLog::query()->where('subject', self::STAFF_SUBJECT)->count();
    }

    private function notes(Invoice $invoice): float
    {
        return (float) $invoice->adjustmentNotes()->sum('amount');
    }

    /** @param list<string> $except rules expected in the scenario (e.g. a debt the test made on purpose) */
    private function assertMoneyCheckClean(array $except = []): void
    {
        $findings = array_values(array_filter((new MoneyCheck)->run(), fn ($finding) => !in_array($finding['rule'] ?? null, $except, true)));
        $this->assertSame([], $findings, json_encode($findings));
    }

    // ---- ADV-552-1: the overpayment's credit was spent (auto-apply, the default) — the refund lands on the invoice, silently

    public function test_bug_overpayment_auto_applied_to_another_invoice_then_refunded_is_booked_on_the_invoice_without_telling_staff(): void
    {
        config(['settings.credits_auto_use' => true]); // the default (CreditAutoApply::enabled)
        $other = $this->invoice(30);
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');
        $this->assertSame([Invoice::STATUS_PAID, Invoice::STATUS_PAID, 0.0], [$invoice->fresh()->status, $other->fresh()->status, $this->credit()],
            'precondition: the 30 over paid the other open invoice at once (auto-apply)');

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd');

        // Owner 2026-10-30 (the fix): the overpayment refunded is taken from the credit though B spent it, so the customer
        // owes 30 (credit −30); invoice A is untouched; staff are told.
        $this->assertSame([0.0, -30.0], [$this->notes($invoice), $this->credit()], 'a debt of 30, not a refund of invoice A');
        $this->assertSame(1, $this->staffMails(), 'the overpayment it refunds was spent on another invoice: staff must be told (or the credit go negative)');
    }

    public function test_bug_waiting_refund_of_the_overpayment_loses_the_race_to_auto_apply(): void
    {
        config(['settings.credits_auto_use' => true]);
        $other = $this->invoice(30);
        $invoice = $this->invoice(100);
        // The refund webhook first (Stripe out of order): it waits for its payment.
        $this->assertFalse(ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd'));

        // The payment: handle() commits and its afterCommit auto-apply spends the 30 over on B before addPayment's
        // afterCommit applies the waiting refund.
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');

        $this->assertSame(Invoice::STATUS_PAID, $other->fresh()->status, 'precondition: auto-apply ran first');
        $this->assertSame('30.00', (string) GatewayRefund::query()->where('reference', 're_30')->sole()->recorded_amount);
        $this->assertSame(1, $this->staffMails(), 'the refund of the overpayment was booked on the invoice while the overpayment paid B: staff must be told');
    }

    // ---- ADV-552-2: the overpayment went to pay a debt (negative credit) — same silent booking on the invoice

    public function test_bug_overpayment_that_paid_off_a_debt_is_refunded_from_the_invoice_silently(): void
    {
        (new CreditLedger)->remove($this->user->client()->id, 'USD', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_LOCAL,
            note: 'debt', mayGoNegative: true);
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');
        $this->assertSame(-20.0, $this->credit(), 'precondition: the 30 over paid part of the debt');

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd');

        // Owner 2026-10-30 (the fix): the debt comes back (−50); invoice A is untouched.
        $this->assertSame(-50.0, $this->credit());
        $this->assertSame(0.0, $this->notes($invoice));
        $this->assertSame(1, $this->staffMails(), 'the overpayment refunded had paid a debt: the debt should come back (credit -50) or staff be told');
    }

    // ---- ADV-552-3 (out of the PR's scope, same rule): a chargeback of an overpaid payment leaves the credit

    public function test_bug_chargeback_of_an_overpaid_payment_leaves_its_overpaid_credit(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');

        $this->markTestSkipped('ADV-552-3: owner 2026-10-30, a chargeback of an overpaid payment is its own PR.');
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $this->assertSame('100.00', (string) $this->payment()->refunded_amount);
        $this->assertSame(0.0, $this->credit(), 'the bank took all 130 back: the 30 overpaid credit should go too, as #552 does for a refund');
    }

    // ---- ADV-552-4: credit of another cause (staff reduced the invoice) taken back under the cause refund_to_credit

    public function test_bug_credit_from_a_reduced_invoice_is_taken_back_as_refund_to_credit(): void
    {
        $invoice = $this->invoice(100);
        $extra = $invoice->items()->create(['description' => 'Setup', 'quantity' => 1, 'price' => 30]);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 120.00, transactionId: 'pi_over', currency: 'usd');
        DB::table('invoice_items')->where('id', $extra->id)->delete(); // staff take the setup off: 100 billed, 120 paid
        $this->assertTrue((new RecordPaymentService)->settle($invoice->id));
        $this->assertSame(20.0, $this->credit(), 'precondition: 20 of credit, cause invoice_reduced');

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_20', 20.00, 'usd');

        $this->assertSame(0.0, $this->credit());
        $this->assertMoneyCheckClean();
        $this->assertSame([MoneyTrail::CAUSE_INVOICE_REDUCED], MoneyEvent::query()->where('kind', MoneyEventKind::CreditOut->value)->pluck('cause')->all(),
            'the credit was the invoice reduced, never a refund to credit: the journal names a cause that never happened');
    }

    // ---- guarantees attacked that held

    private function overpaid(): Invoice
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');
        $this->assertSame(30.0, $this->credit());

        return $invoice->fresh();
    }

    public function test_holds_partial_refunds_in_sequence_take_the_credit_then_the_invoice(): void
    {
        $invoice = $this->overpaid();

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_10', 10.00, 'usd');
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_25', 25.00, 'usd');

        $this->assertSame(0.0, $this->credit());
        $this->assertSame(-5.0, $this->notes($invoice));
        $payment = $this->payment();
        $this->assertSame(['35.00', '0.00'], [(string) $payment->refunded_amount, (string) $payment->credited_amount]);
        $this->assertSame([1000, 2000], MoneyEvent::query()->where('kind', MoneyEventKind::CreditOut->value)->orderBy('id')->pluck('amount')
            ->map(fn ($amount) => RecordPaymentService::toCents($amount))->all(), 'the overpayment taken 10 then 20');
        $this->assertSame(['10.00', '25.00'], GatewayRefund::query()->orderBy('id')->pluck('recorded_amount')->map(fn ($a) => (string) $a)->all(),
            'the customer is told what went back each time');
        $this->assertMoneyCheckClean();
    }

    public function test_holds_a_retried_webhook_takes_nothing_twice(): void
    {
        $this->overpaid();
        (new CreditLedger)->add($this->user->client()->id, 'USD', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_LOCAL, note: 'other');

        $this->assertTrue(ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd'));
        $this->assertFalse(ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd'));

        $this->assertSame(50.0, $this->credit());
        $this->assertSame('30.00', (string) $this->payment()->refunded_amount);
        $this->assertMoneyCheckClean();
    }

    public function test_holds_credit_spent_through_an_allocation_is_not_taken_from_other_credit(): void
    {
        $invoice = $this->overpaid();
        $other = $this->invoice(30);
        $this->assertNotNull((new RecordPaymentService)->allocateFromTransaction($this->payment(), $other->id));
        (new CreditLedger)->add($this->user->client()->id, 'USD', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_LOCAL, note: 'other');

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd');

        $this->assertSame(50.0, $this->credit(), 'the allocated 30 is not refunded out of unrelated credit');
        $this->assertSame(-30.0, $this->notes($invoice));
        $this->assertMoneyCheckClean();
    }

    public function test_holds_a_refund_to_credit_after_a_dashboard_refund_of_the_overpayment(): void
    {
        $invoice = $this->overpaid();
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd');

        ExtensionHelper::refundToCredit($this->payment(), 100.00, notify: false);

        $this->assertSame(100.0, $this->credit());
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->expectException(\InvalidArgumentException::class);
        try {
            ExtensionHelper::refundToCredit($this->payment(), 0.01, notify: false);
        } finally {
            $this->assertMoneyCheckClean();
        }
    }

    public function test_holds_chargeback_and_dispute_won_after_the_overpayment_was_refunded(): void
    {
        $invoice = $this->overpaid();
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd');

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 100.00, 'usd');
        $this->assertSame(['130.00', Invoice::STATUS_UNPAID], [(string) $this->payment()->refunded_amount, $invoice->fresh()->status]);
        $this->assertMoneyCheckClean();

        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 100.00, 'usd');
        $this->assertSame(['30.00', Invoice::STATUS_PAID, 0.0], [(string) $this->payment()->refunded_amount, $invoice->fresh()->status, $this->credit()]);
        $this->assertMoneyCheckClean();
    }

    public function test_holds_an_overpaid_credit_purchase_gives_back_exactly_what_left(): void
    {
        $invoice = $this->invoice(50, ['reference_type' => Credit::class, 'description' => 'Add Funds']);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 80.00, transactionId: 'pi_over', currency: 'usd');
        $this->assertSame(80.0, $this->credit(), 'precondition: 50 bought, 30 over');

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_50', 50.00, 'usd');
        $this->assertSame(30.0, $this->credit());
        $this->assertMoneyCheckClean();

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd');
        $this->assertSame([0.0, Invoice::STATUS_REFUNDED], [$this->credit(), $invoice->fresh()->status]);
        $this->assertMoneyCheckClean();
    }

    public function test_holds_a_refund_in_another_currency_takes_no_credit(): void
    {
        $invoice = $this->overpaid();

        $this->assertFalse(ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_eur', 30.00, 'eur'));

        $this->assertSame([30.0, 0.0], [$this->credit(), $this->notes($invoice)]);
        $this->assertSame('0.00', (string) $this->payment()->refunded_amount);
        $this->assertMoneyCheckClean();
    }

    public function test_holds_a_refund_failed_after_the_credit_was_taken_tells_staff(): void
    {
        $this->overpaid();
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd');

        ExtensionHelper::refundFailedAtGateway('Stripe', 're_30', 'pi_over');

        $this->assertSame(1, EmailLog::query()->where('subject', 'Gateway refund failed after it was recorded')->count());
    }

    /** Owner 2026-10-30: the overpayment the debt took is owed again once refunded to the card (−50 − 30). */
    public function test_holds_a_refunded_overpayment_the_debt_spent_is_owed_again(): void
    {
        $this->overpaid();
        (new CreditLedger)->remove($this->user->client()->id, 'USD', 8000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_LOCAL,
            note: 'debt', mayGoNegative: true);
        $this->assertSame(-50.0, $this->credit());

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd');

        $this->assertSame(-80.0, $this->credit());
        $this->assertSame('30.00', (string) $this->payment()->refunded_amount);
        $this->assertMoneyCheckClean(['credit_negative']);
    }

    public function test_holds_a_whmcs_imported_payment_with_credited_amount_but_no_journal(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, transactionId: 'pi_over', currency: 'usd');
        // As the importer leaves it: credited_amount set, the credit there as an opening balance, no overpayment event.
        DB::table('invoice_transactions')->where('transaction_id', 'pi_over')->update(['amount' => 130, 'credited_amount' => 30]);
        DB::table('money_events')->where('kind', MoneyEventKind::Payment->value)->update(['amount' => 130]);
        (new CreditLedger)->add($this->user->client()->id, 'USD', 3000, MoneyTrail::CAUSE_OPENING_BALANCE, MoneyTrail::SOURCE_IMPORT, note: 'import');

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd');

        $this->assertSame([0.0, 0.0], [$this->credit(), $this->notes($invoice)]);
        $this->assertSame(['30.00', '0.00'], [(string) $this->payment()->refunded_amount, (string) $this->payment()->credited_amount]);
        $this->assertMoneyCheckClean();
    }
}
