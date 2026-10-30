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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Round-4 (final) adversarial review of #552 after «keep it simple» (5ae06513): a dashboard refund takes back the
 * payment's credited part, as much as the credit still holds; what was spent meanwhile is recorded nowhere and staff
 * are told; the rest is the invoice's. test_bug_* fail on the PR's head and name a hole; test_holds_* held.
 */
class GatewayRefundOfOverpaymentAdversarialR4Test extends TestCase
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

    private function notes(Invoice $invoice): float
    {
        return (float) $invoice->adjustmentNotes()->sum('amount');
    }

    private function staffBody(): string
    {
        return html_entity_decode(EmailLog::query()->where('subject', self::STAFF_SUBJECT)->orderBy('id')->pluck('body')->implode("\n"), ENT_QUOTES);
    }

    /** @return list<array{0: string, 1: int}> */
    private function creditOuts(): array
    {
        return MoneyEvent::query()->where('kind', MoneyEventKind::CreditOut->value)->orderBy('id')->get()
            ->map(fn (MoneyEvent $event) => [(string) $event->cause, RecordPaymentService::toCents($event->amount)])->values()->all();
    }

    /** @param list<string> $except */
    private function assertMoneyCheckClean(array $except = []): void
    {
        $findings = array_values(array_filter((new MoneyCheck)->run(), fn ($finding) => !in_array($finding['rule'] ?? null, $except, true)));
        $this->assertSame([], $findings, json_encode($findings));
    }

    /** Invoice A of 100 paid 130; auto-apply (the default) spends the 30 over on another open invoice of 30 at once. */
    private function overpaidAndSpent(): Invoice
    {
        config(['settings.credits_auto_use' => true]);
        $other = $this->invoice(30);
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');
        $this->assertSame([Invoice::STATUS_PAID, Invoice::STATUS_PAID, 0.0], [$invoice->fresh()->status, $other->fresh()->status, $this->credit()],
            'precondition: the 30 over paid the other invoice');

        return $invoice->fresh();
    }

    // ---- ADV-552-R4-1: the spent part is claimed again by every later refund of the same payment

    public function test_bug_a_second_refund_claims_the_spent_overpayment_again_and_the_invoice_refund_is_lost(): void
    {
        $invoice = $this->overpaidAndSpent();

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd'); // the 30 over: spent, staff's
        $this->assertSame('0.00', (string) GatewayRefund::query()->where('reference', 're_30')->value('recorded_amount'));

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_100', 100.00, 'usd'); // the invoice itself

        // 130 went back at the gateway: 30 is the spent overpayment (once), 100 is invoice A's. The second refund
        // computes the claim from credited_amount again (still 30, nothing was taken), calls 30 of it «spent» a second
        // time and records only 70: A stays partly paid and 30 of its refund is recorded nowhere.
        $this->assertSame(-100.0, $this->notes($invoice), 'the spent 30 was already accounted to re_30: re_100 is all the invoice\'s');
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
    }

    public function test_bug_partial_refunds_each_count_the_part_the_credit_no_longer_holds(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');
        (new CreditLedger)->remove($this->user->client()->id, 'USD', 2000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_LOCAL, note: 'spent');

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd'); // 10 taken, 20 spent
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_100', 100.00, 'usd');

        $this->assertSame(['10.00', '100.00'], GatewayRefund::query()->orderBy('id')->pluck('recorded_amount')->map(fn ($a) => (string) $a)->all(),
            'the 20 spent is re_30\'s; re_100 claims it again as spent and records only 80');
        $this->assertSame(-100.0, $this->notes($invoice));
    }

    // ---- ADV-552-R4-2: a refund that was all «spent» (recorded 0) fails later: staff, who were told to settle it, never hear

    public function test_bug_a_failed_refund_of_a_spent_overpayment_tells_nobody(): void
    {
        $this->overpaidAndSpent();
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd');
        $this->assertStringContainsString('settle it by hand', $this->staffBody(), 'precondition: staff told to settle the 30');

        ExtensionHelper::refundFailedAtGateway('Stripe', 're_30', 'pi_over');

        // refundFailedAtGateway returns early on recorded_amount <= 0. Staff may have made a debt of the 30 already;
        // the money never went back, and nobody tells them to undo it.
        $this->assertSame(1, EmailLog::query()->where('subject', 'Gateway refund failed after it was recorded')->count());
    }

    // ---- ADV-552-R4-3: the spent overpayment is not netted in creditedParts: a later refund to credit is named an overpayment

    public function test_bug_credited_parts_names_a_refund_to_credit_as_the_overpayment_already_spent(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');
        (new CreditLedger)->remove($this->user->client()->id, 'USD', 3000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_LOCAL, note: 'spent');
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd'); // all spent: staff's
        ExtensionHelper::refundToCredit($this->payment(), 50.00, notify: false);
        $this->assertSame(50.0, $this->credit(), 'precondition: the only credit now is the refund to credit');

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_50', 50.00, 'usd');

        $this->assertSame(0.0, $this->credit());
        $this->assertMoneyCheckClean();
        $this->assertSame([[MoneyTrail::CAUSE_STAFF_ADJUSTMENT, 3000], [MoneyTrail::CAUSE_REFUND_TO_CREDIT, 5000]], $this->creditOuts(),
            'the overpayment was spent (and reported to staff by re_30); the 50 taken is the refund to credit');
    }

    // ---- guarantees attacked that held

    public function test_holds_the_staff_message_names_what_was_taken_and_what_was_spent(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');
        (new CreditLedger)->remove($this->user->client()->id, 'USD', 2000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_LOCAL, note: 'spent');

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd');

        $body = $this->staffBody();
        $this->assertStringContainsString('10.00 of it was taken from the account credit', $body);
        $this->assertStringContainsString('20.00 more of it is credit this payment left that the customer has spent already', $body);
        $this->assertStringNotContainsString('was left on it', $body, 'nothing went beyond the payment');
        $payment = $this->payment();
        $this->assertSame(['10.00', '20.00', 100.0], [(string) $payment->refunded_amount, (string) $payment->credited_amount, (float) $payment->refundable_amount]);
        $this->assertSame('10.00', (string) GatewayRefund::query()->sole()->recorded_amount);
        $this->assertSame([0.0, 0.0], [$this->credit(), $this->notes($invoice)]);
        $this->assertMoneyCheckClean();
    }

    public function test_holds_a_spent_refund_replayed_records_nothing_and_mails_once(): void
    {
        $this->overpaidAndSpent();

        $this->assertFalse(ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd'));
        $this->assertFalse(ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd'));

        $this->assertSame(1, GatewayRefund::query()->count());
        $this->assertSame(1, EmailLog::query()->where('subject', self::STAFF_SUBJECT)->count());
        $this->assertSame(['0.00', '30.00'], [(string) $this->payment()->refunded_amount, (string) $this->payment()->credited_amount]);
        $this->assertSame(0.0, $this->credit(), 'never a debt');
        $this->assertMoneyCheckClean();
    }

    public function test_holds_a_chargeback_after_a_spent_refund_takes_the_invoice_part_only(): void
    {
        $invoice = $this->overpaidAndSpent();
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd');

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 100.00, 'usd');

        $payment = $this->payment();
        $this->assertSame(['100.00', '30.00'], [(string) $payment->refunded_amount, (string) $payment->credited_amount]);
        $this->assertSame([Invoice::STATUS_UNPAID, 0.0], [$invoice->fresh()->status, $this->credit()]);
        $this->assertMoneyCheckClean();
    }

    public function test_holds_a_hand_refund_cannot_record_the_spent_part_on_the_invoice_beyond_what_it_paid(): void
    {
        $invoice = $this->overpaidAndSpent();
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_130', 130.00, 'usd');

        // 100 on the invoice, 30 spent and recorded nowhere: nothing is left for a hand refund or a refund to credit.
        $payment = $this->payment();
        $this->assertSame(['100.00', '30.00', 0.0], [(string) $payment->refunded_amount, (string) $payment->credited_amount, (float) $payment->refundable_amount]);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->expectException(\InvalidArgumentException::class);
        try {
            ExtensionHelper::refundToCredit($payment, 0.01, notify: false);
        } finally {
            $this->assertMoneyCheckClean();
        }
    }

    public function test_holds_a_spent_credit_purchase_with_an_overpayment_stays_consistent(): void
    {
        $invoice = $this->invoice(50, ['reference_type' => Credit::class, 'description' => 'Add Funds']);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 80.00, transactionId: 'pi_over', currency: 'usd');
        $this->assertSame(80.0, $this->credit());
        $this->assertNotNull((new RecordPaymentService)->payWithCredit($this->invoice(70)->id));
        $this->assertSame(10.0, $this->credit());

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_80', 80.00, 'usd');

        $this->assertSame(0.0, $this->credit(), 'the 10 left taken; never a debt');
        $payment = $this->payment();
        $this->assertSame(['60.00', '20.00'], [(string) $payment->refunded_amount, (string) $payment->credited_amount]);
        $this->assertMoneyCheckClean();
    }

    public function test_holds_a_refund_to_credit_spent_then_refunded_at_the_dashboard_is_never_a_debt(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, transactionId: 'pi_over', currency: 'usd');
        ExtensionHelper::refundToCredit($this->payment(), 40.00, notify: false);
        $this->assertNotNull((new RecordPaymentService)->payWithCredit($this->invoice(40)->id));
        $this->assertSame(0.0, $this->credit());

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_40', 40.00, 'usd');

        $this->assertSame(0.0, $this->credit());
        $this->assertSame(['0.00', '40.00'], [(string) $this->payment()->refunded_amount, (string) $this->payment()->credited_amount]);
        $this->assertStringContainsString('40.00 more of it is credit', $this->staffBody());
        $this->assertMoneyCheckClean();
    }
}
