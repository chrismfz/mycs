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
use App\Services\Money\CreditAutoApply;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyHistory;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Round-2 adversarial review of #552 (a dashboard refund takes back the credit its payment left; spent, it is owed).
 * test_bug_* fail on the PR's head (ebe55a73) and name a hole; test_holds_* are guarantees attacked that held.
 */
class GatewayRefundOfOverpaymentAdversarialR2Test extends TestCase
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
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
    }

    private function invoice(float $price = 100): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

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

    /** @return list<array{0: string, 1: int}> each credit_out's cause and cents, in order */
    private function creditOuts(): array
    {
        return MoneyEvent::query()->where('kind', MoneyEventKind::CreditOut->value)->orderBy('id')->get()
            ->map(fn (MoneyEvent $event) => [(string) $event->cause, RecordPaymentService::toCents($event->amount)])->values()->all();
    }

    /** @param list<string> $except rules expected in the scenario */
    private function assertMoneyCheckClean(array $except = []): void
    {
        $findings = array_values(array_filter((new MoneyCheck)->run(), fn ($finding) => !in_array($finding['rule'] ?? null, $except, true)));
        $this->assertSame([], $findings, json_encode($findings));
    }

    /** Invoice A paid 130 for 100 (30 over, to credit), the 30 spent on another invoice, then refunded at the dashboard: credit −30. */
    private function debtFromARefundedOverpayment(): Invoice
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');
        $spent = $this->invoice(30);
        $this->assertNotNull((new RecordPaymentService)->payWithCredit($spent->id));
        $this->assertSame([0.0, Invoice::STATUS_PAID], [$this->credit(), $spent->fresh()->status]);

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd');
        $this->assertSame(-30.0, $this->credit(), 'precondition: owner 2026-10-30, the customer owes the 30');

        return $invoice;
    }

    // ---- ADV-552-R2-1: the debt a dashboard refund leaves eats the next subscription sale; its renewal stays unpaid

    public function test_bug_debt_from_a_dashboard_refund_eats_a_subscription_sale_and_leaves_its_renewal_unpaid(): void
    {
        $this->debtFromARefundedOverpayment();
        // One subscription sale of 100 for two services' renewals, B and C (PayPal::paymentSaleCompleted): it lands on
        // B (50 over, to credit) and the rest is allocated to C (allocateFromTransaction, not «chosen for payment»).
        $b = $this->invoice(50);
        $c = $this->invoice(50);
        ExtensionHelper::addPayment($b->id, 'Stripe', 100.00, transactionId: 'pi_sale', currency: 'usd');
        (new RecordPaymentService)->allocateFromTransaction($this->payment('pi_sale'), $c->id);

        // The sale paid C in full at the gateway; the debt the webhook made silently took 30 of it, so C (a renewal the
        // subscription paid) stays open: reminders, then suspension of a service whose subscription was charged. Staff
        // and customer chose nothing (decision 1 keeps a debt from eating a payment chosen for invoices: MassPay,
        // PayInvoices). Owner's call: a subscription sale on its own budget, or the debt collected otherwise.
        $this->assertSame(Invoice::STATUS_PAID, $c->fresh()->status, 'C remaining ' . $c->fresh()->remaining . ': the subscription sale paid it');
    }

    // ---- ADV-552-R2-2: an invoice without a customer (imported, its WHMCS client deleted) with a credited part

    public function test_bug_refund_of_an_overpayment_on_an_invoice_without_customer_fails_the_webhook(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');
        DB::table('invoices')->where('id', $invoice->id)->update(['client_id' => null]); // as the importer may leave it

        $thrown = null;
        try {
            ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        // Before #552 it was recorded on the invoice; now refundOverpaidCredit moves the credit of customer #0 (no such
        // row), the transaction rolls back, the webhook answers 500 and is retried forever, and staff are never told.
        $this->assertNull($thrown, 'the webhook threw: ' . ($thrown === null ? '' : get_class($thrown) . ': ' . $thrown->getMessage()));
        $this->assertSame(1, GatewayRefund::query()->where('reference', 're_30')->count(), 'the refund is recorded somewhere (or handed to staff)');
    }

    // ---- ADV-552-R2-3: the customer's history says «card» for a refund that went to PayPal (or a bank)

    public function test_bug_credit_history_says_refunded_to_your_card_for_a_paypal_refund(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'PayPal', 130.00, transactionId: 'PAY-OVER', currency: 'usd');

        ExtensionHelper::refundedAtGateway('PayPal', 'PAY-OVER', 'RF-30', 30.00, 'usd');

        $out = MoneyEvent::query()->where('kind', MoneyEventKind::CreditOut->value)->sole();
        app()->setLocale('en');
        $this->assertStringNotContainsString('card', MoneyHistory::customerLabel($out),
            'PayPal refunds to the PayPal balance; WHMCS\'s imported «Removal of Credit ... refunded» may be a bank transfer: «Refunded to you»');
    }

    // ---- ADV-552-R2-4: creditedParts ignores what allocations spent: the refund-to-credit part left is named an overpayment

    public function test_bug_credited_parts_names_a_refund_to_credit_as_overpayment_after_an_allocation_spent_the_overpayment(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');
        $other = $this->invoice(30);
        $this->assertNotNull((new RecordPaymentService)->allocateFromTransaction($this->payment(), $other->id)); // the 30 over pays it
        ExtensionHelper::refundToCredit($this->payment(), 20.00, notify: false); // then 20 of the invoice to credit
        $this->assertSame(20.0, $this->credit(), 'precondition: the only credit this payment still has is the refund to credit');

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_20', 20.00, 'usd');

        $this->assertSame(0.0, $this->credit());
        $this->assertMoneyCheckClean();
        $this->assertSame([[MoneyTrail::CAUSE_CREDIT_PAYMENT, 3000], [MoneyTrail::CAUSE_REFUND_TO_CREDIT, 2000]], $this->creditOuts(),
            'the overpayment went to the allocation; the 20 taken back is the refund to credit, not an overpayment');
    }

    // ---- guarantees attacked that held

    public function test_holds_mixed_causes_are_taken_overpayment_first_then_net_of_earlier_credit_outs(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 110.00, transactionId: 'pi_over', currency: 'usd');
        ExtensionHelper::refundToCredit($this->payment(), 20.00, notify: false);
        $this->assertSame(30.0, $this->credit());

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_a', 15.00, 'usd');
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_b', 15.00, 'usd');
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_c', 5.00, 'usd');

        $this->assertSame([[MoneyTrail::CAUSE_OVERPAYMENT, 1000], [MoneyTrail::CAUSE_REFUND_TO_CREDIT, 500], [MoneyTrail::CAUSE_REFUND_TO_CREDIT, 1500]],
            $this->creditOuts());
        $this->assertSame(0.0, $this->credit());
        $payment = $this->payment();
        $this->assertSame(['35.00', '0.00'], [(string) $payment->refunded_amount, (string) $payment->credited_amount]);
        $this->assertSame(-5.0, $this->notes($invoice) + 20.0, 'the third refund (5) is the invoice\'s; the 20 note is the refund to credit');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertMoneyCheckClean();
    }

    public function test_holds_a_refund_larger_than_the_payment_records_what_it_can_and_tells_staff(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');

        $this->assertTrue(ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_big', 200.00, 'usd'));

        $payment = $this->payment();
        $this->assertSame(['130.00', '0.00', 0.0], [(string) $payment->refunded_amount, (string) $payment->credited_amount, $this->credit()]);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $body = (string) EmailLog::query()->where('subject', self::STAFF_SUBJECT)->value('body');
        $this->assertStringContainsString('130.00 was left on it', $body);
        $this->assertStringContainsString('70.00 recorded nowhere', $body);
        $this->assertSame('130.00', (string) GatewayRefund::query()->where('reference', 're_big')->value('recorded_amount'));
        $this->assertMoneyCheckClean();
    }

    public function test_holds_a_debt_is_never_charged_by_auto_apply_nor_eats_a_payment_of_the_exact_amount(): void
    {
        $this->debtFromARefundedOverpayment();
        config(['settings.credits_auto_use' => true]);
        $open = $this->invoice(40);

        $this->assertSame(0, (new CreditAutoApply)->forCustomer($this->user->client()->id, 'USD'));
        $this->assertNull((new RecordPaymentService)->payWithCredit($open->id));
        $this->assertSame(0, $open->transactions()->count());

        ExtensionHelper::addPayment($open->id, 'Stripe', 40.00, transactionId: 'pi_open', currency: 'usd');
        $this->assertSame([Invoice::STATUS_PAID, -30.0], [$open->fresh()->status, $this->credit()]);
        $this->assertMoneyCheckClean(['credit_negative']);
    }

    public function test_holds_the_staff_message_names_the_debt_and_the_credit_taken(): void
    {
        $invoice = $this->debtFromARefundedOverpayment();

        $body = html_entity_decode((string) EmailLog::query()->where('subject', self::STAFF_SUBJECT)->value('body'), ENT_QUOTES);
        $this->assertStringContainsString('30.00 of it was taken from the account credit', $body);
        $this->assertStringContainsString('30.00 of that was spent from the credit already, so the customer owes it now', $body);
        $this->assertStringNotContainsString('recorded nowhere', $body);
        $this->assertSame(0.0, $this->notes($invoice));
        $this->assertMoneyCheckClean(['credit_negative']);
    }

    public function test_holds_a_second_refund_of_the_spent_overpayment_never_doubles_the_debt(): void
    {
        $invoice = $this->debtFromARefundedOverpayment();

        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_again', 30.00, 'usd');

        $this->assertSame(-30.0, $this->credit(), 'the credited part is gone: the second refund is the invoice\'s');
        $this->assertSame(-30.0, $this->notes($invoice));
        $this->assertSame(['60.00', '0.00'], [(string) $this->payment()->refunded_amount, (string) $this->payment()->credited_amount]);
        $this->assertMoneyCheckClean(['credit_negative']);
    }
}
