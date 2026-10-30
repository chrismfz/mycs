<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Adversarial review of #553 (a chargeback takes back first the credit its payment left; a won dispute gives each part
 * back; a chargeback stops the account's automatic charging). test_bug_* fail on the PR's head and name a hole;
 * test_holds_* are guarantees attacked that held. MoneyCheck after each step.
 */
class ChargebackOfOverpaymentAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const STAFF = 'Chargeback needs staff attention';

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
        $this->user->client()->update(['auto_charge' => true]);
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
    }

    private function invoice(float $price, array $line = []): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => now()->addWeek()]);
        $invoice->items()->create($line + ['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function overpaid(string $gateway = 'Stripe', string $reference = 'pi_over'): Invoice
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, $gateway, 130.00, transactionId: $reference, currency: 'usd');
        $this->assertSame(30.0, $this->credit());

        return $invoice->fresh();
    }

    private function credit(): float
    {
        return (float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    /** @return array{0: string, 1: string} refunded, credited */
    private function payment(string $reference = 'pi_over'): array
    {
        $payment = InvoiceTransaction::query()->where('transaction_id', $reference)->sole();

        return [(string) $payment->refunded_amount, (string) $payment->credited_amount];
    }

    private function autoCharge(): bool
    {
        return (bool) $this->user->client()->fresh()->auto_charge;
    }

    private function staffBodies(): string
    {
        return EmailLog::query()->where('subject', self::STAFF)->pluck('body')->implode("\n");
    }

    private function assertMoneyCheckClean(string $step = ''): void
    {
        $findings = (new MoneyCheck)->run();
        $this->assertSame([], $findings, $step . ' ' . json_encode($findings));
    }

    // ---- sequences that held

    public function test_holds_chargeback_win_then_a_second_chargeback(): void
    {
        $invoice = $this->overpaid();
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
        $this->assertMoneyCheckClean('after dp_1');
        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
        $this->assertSame([30.0, Invoice::STATUS_PAID, ['0.00', '30.00']], [$this->credit(), $invoice->fresh()->status, $this->payment()]);
        $this->assertMoneyCheckClean('after win');

        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_2', 130.00, 'usd'));

        $this->assertSame([0.0, Invoice::STATUS_UNPAID, ['130.00', '0.00']], [$this->credit(), $invoice->fresh()->status, $this->payment()]);
        $this->assertMoneyCheckClean('after dp_2');
        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_2', 130.00, 'usd');
        $this->assertSame([30.0, Invoice::STATUS_PAID, ['0.00', '30.00']], [$this->credit(), $invoice->fresh()->status, $this->payment()]);
        $this->assertMoneyCheckClean('after second win');
    }

    public function test_holds_partial_chargeback_of_the_overpayment_then_the_rest(): void
    {
        $invoice = $this->overpaid();

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_30', 30.00, 'usd');
        $this->assertSame([0.0, Invoice::STATUS_PAID, ['30.00', '0.00']], [$this->credit(), $invoice->fresh()->status, $this->payment()],
            'the 30 came from the credit, the invoice stays paid');
        $this->assertMoneyCheckClean('after 30');

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_100', 100.00, 'usd');
        $this->assertSame([0.0, Invoice::STATUS_UNPAID, ['130.00', '0.00']], [$this->credit(), $invoice->fresh()->status, $this->payment()]);
        $this->assertMoneyCheckClean('after 100');

        // Won in the other order: each gives its own part back.
        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_100', 100.00, 'usd');
        $this->assertSame([0.0, Invoice::STATUS_PAID, ['30.00', '0.00']], [$this->credit(), $invoice->fresh()->status, $this->payment()]);
        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_30', 30.00, 'usd');
        $this->assertSame([30.0, Invoice::STATUS_PAID, ['0.00', '30.00']], [$this->credit(), $invoice->fresh()->status, $this->payment()]);
        $this->assertMoneyCheckClean('after both wins');
    }

    public function test_holds_partial_chargeback_then_a_full_one_of_the_whole_payment(): void
    {
        $invoice = $this->overpaid();
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_30', 30.00, 'usd');

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_all', 130.00, 'usd');

        $this->assertSame([0.0, Invoice::STATUS_UNPAID, ['130.00', '0.00']], [$this->credit(), $invoice->fresh()->status, $this->payment()],
            'never more than the payment');
        $this->assertStringContainsString('30.00 of it was no longer on the payment', $this->staffBodies());
        $this->assertMoneyCheckClean();
    }

    public function test_holds_dashboard_refund_of_the_overpayment_then_a_chargeback(): void
    {
        $invoice = $this->overpaid();
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd');
        $this->assertSame([0.0, ['30.00', '0.00']], [$this->credit(), $this->payment()]);

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 100.00, 'usd');

        $this->assertSame([0.0, Invoice::STATUS_UNPAID, ['130.00', '0.00']], [$this->credit(), $invoice->fresh()->status, $this->payment()]);
        $this->assertMoneyCheckClean();
        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 100.00, 'usd');
        $this->assertSame([0.0, Invoice::STATUS_PAID, ['30.00', '0.00']], [$this->credit(), $invoice->fresh()->status, $this->payment()],
            'the dashboard refund stays refunded');
        $this->assertMoneyCheckClean('after win');
    }

    public function test_holds_chargeback_then_a_dashboard_refund_records_nothing_more(): void
    {
        $this->overpaid();
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $this->assertFalse(ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_30', 30.00, 'usd'));

        $this->assertSame([0.0, ['130.00', '0.00']], [$this->credit(), $this->payment()]);
        $this->assertMoneyCheckClean();
    }

    public function test_holds_chargeback_after_a_refund_to_credit(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, transactionId: 'pi_over', currency: 'usd');
        ExtensionHelper::refundToCredit(InvoiceTransaction::query()->where('transaction_id', 'pi_over')->sole(), 40.00);
        $this->assertSame(40.0, $this->credit());
        $this->assertMoneyCheckClean('after refund to credit');

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 100.00, 'usd');

        $this->assertSame([0.0, ['100.00', '0.00']], [$this->credit(), $this->payment()], 'the 40 credit it left is taken back, 60 from the invoice');
        $this->assertNotSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertMoneyCheckClean('after chargeback');

        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 100.00, 'usd');
        $this->assertSame([40.0, ['0.00', '40.00']], [$this->credit(), $this->payment()]);
        $this->assertMoneyCheckClean('after win');
    }

    public function test_holds_won_dispute_after_the_restored_credit_was_spent_then_a_second_chargeback(): void
    {
        $invoice = $this->overpaid();
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
        $this->assertNotNull((new RecordPaymentService)->payWithCredit($this->invoice(30)->id)); // the restored 30 spent
        $this->assertSame(0.0, $this->credit());

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_2', 130.00, 'usd');
        $this->assertSame([0.0, Invoice::STATUS_UNPAID, ['130.00', '0.00']], [$this->credit(), $invoice->fresh()->status, $this->payment()], 'never a debt');
        $this->assertMoneyCheckClean('after dp_2');

        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_2', 130.00, 'usd');
        $this->assertSame([0.0, Invoice::STATUS_PAID, ['0.00', '30.00']], [$this->credit(), $invoice->fresh()->status, $this->payment()],
            'spent: no credit made again');
        $this->assertMoneyCheckClean('after win 2');
    }

    public function test_holds_won_dispute_when_the_invoice_was_paid_again_meanwhile(): void
    {
        $invoice = $this->overpaid();
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, transactionId: 'pi_again', currency: 'usd');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $this->assertSame(130.0, $this->credit(), 'all 130 of the first payment comes back as credit: 30 its overpayment, 100 paid twice');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertMoneyCheckClean();
    }

    public function test_holds_won_dispute_when_the_invoice_was_cancelled_meanwhile(): void
    {
        $invoice = $this->overpaid();
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
        $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED]);

        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $this->assertSame(130.0, $this->credit());
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertMoneyCheckClean();
    }

    public function test_holds_overpaid_credit_purchase_charged_back_and_won(): void
    {
        $invoice = $this->invoice(100, ['reference_type' => Credit::class, 'description' => 'Add Funds']);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');
        $this->assertSame([130.0, Invoice::STATUS_PAID], [$this->credit(), $invoice->fresh()->status]);

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
        $this->assertSame([0.0, ['130.00', '0.00']], [$this->credit(), $this->payment()]);
        $this->assertMoneyCheckClean('after chargeback');

        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
        $this->assertSame([130.0, Invoice::STATUS_PAID, ['0.00', '30.00']], [$this->credit(), $invoice->fresh()->status, $this->payment()]);
        $this->assertMoneyCheckClean('after win');
    }

    public function test_holds_waiting_chargeback_applied_when_the_payment_lands(): void
    {
        $invoice = $this->invoice(100);
        $this->assertFalse(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd'));

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');

        $this->assertSame([0.0, Invoice::STATUS_UNPAID, ['130.00', '0.00']], [$this->credit(), $invoice->fresh()->status, $this->payment()]);
        $this->assertSame('30.00', (string) GatewayRefund::query()->where('reference', 'dp_1')->sole()->credit_taken);
        $this->assertFalse($this->autoCharge());
        $this->assertMoneyCheckClean();
    }

    public function test_holds_waiting_chargeback_after_auto_apply_spent_the_overpayment(): void
    {
        config(['settings.credits_auto_use' => true]);
        $other = $this->invoice(30);
        $invoice = $this->invoice(100);
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 130.00, transactionId: 'pi_over', currency: 'usd');

        $this->assertSame(Invoice::STATUS_PAID, $other->fresh()->status, 'precondition: auto-apply spent the 30');
        $this->assertSame([0.0, Invoice::STATUS_UNPAID, ['130.00', '0.00']], [$this->credit(), $invoice->fresh()->status, $this->payment()], 'never a debt');
        $this->assertMoneyCheckClean();
    }

    public function test_holds_paypal_reversal_without_a_chargeback_reference(): void
    {
        $invoice = $this->overpaid('PayPal', 'PP-PAY-1');
        ExtensionHelper::chargebackAtGateway('PayPal', 'PP-PAY-1', 'PP-REV-1', 130.00, 'usd');
        $this->assertSame([0.0, Invoice::STATUS_UNPAID], [$this->credit(), $invoice->fresh()->status]);

        $this->assertTrue(ExtensionHelper::chargebackReversedAtGateway('PayPal', 'PP-PAY-1', null, 130.00, 'usd', 'PP-D-1'));

        $this->assertSame([30.0, Invoice::STATUS_PAID, ['0.00', '30.00']], [$this->credit(), $invoice->fresh()->status, $this->payment('PP-PAY-1')]);
        $this->assertFalse(ExtensionHelper::chargebackReversedAtGateway('PayPal', 'PP-PAY-1', null, 130.00, 'usd', 'PP-D-1'), 'a replay changes nothing');
        $this->assertSame(30.0, $this->credit());
        $this->assertMoneyCheckClean();
    }

    public function test_holds_customer_may_turn_auto_charge_on_during_the_dispute_and_a_win_leaves_it(): void
    {
        $this->overpaid();
        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
        $this->assertFalse($this->autoCharge());

        $this->user->client()->update(['auto_charge' => true]); // Payment methods (the owner's rule allows it)
        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');
        $this->assertTrue($this->autoCharge(), 'the win does not touch it');

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_2', 130.00, 'usd');
        $this->assertFalse($this->autoCharge(), 'a new chargeback stops it again');
    }

    // ---- ADV-553-1: a chargeback in another currency records nothing, and leaves automatic charging on

    public function test_bug_chargeback_in_another_currency_does_not_stop_automatic_charging(): void
    {
        $this->overpaid();

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_eur', 120.00, 'eur');

        $this->assertSame(30.0, $this->credit(), 'not recorded: staff record it by hand (as before)');
        $this->assertFalse($this->autoCharge(), 'the card disputed a charge all the same: it must not be charged again by itself');
    }

    // ---- ADV-553-2: a chargeback with nothing left to record (refunded already) leaves automatic charging on

    public function test_bug_chargeback_that_records_nothing_does_not_stop_automatic_charging(): void
    {
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, transactionId: 'pi_over', currency: 'usd');
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_over', 're_all', 100.00, 'usd');

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 100.00, 'usd'); // the bank takes it back again

        $this->assertMoneyCheckClean();
        $this->assertFalse($this->autoCharge(), 'the customer disputed the card charge: the stop is about the card, not about what was recorded');
    }

    // ---- ADV-553-3: a chargeback whose payment's overpayment paid other invoices (allocations) tells staff it was "refunds"

    public function test_bug_staff_mail_calls_the_allocated_part_refunds(): void
    {
        $invoice = $this->overpaid();
        $other = $this->invoice(30);
        $this->assertNotNull((new RecordPaymentService)->allocateFromTransaction(InvoiceTransaction::query()->where('transaction_id', 'pi_over')->sole(), $other));
        $this->assertSame(Invoice::STATUS_PAID, $other->fresh()->status, 'precondition: the 30 over paid the other invoice');

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 130.00, 'usd');

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertMoneyCheckClean();
        // Before the PR: "(credit or refunds)". Now: "(refunds)" only, while the 30 paid invoice #other.
        $this->assertStringNotContainsString('was no longer on the payment (refunds)', $this->staffBodies(),
            'the 30 it lacks paid another invoice of the customer, not a refund: staff are pointed at the wrong thing');
    }

    // ---- ADV-553-4: a gateway subscription keeps charging the disputed card; auto_charge only gates MyCS's own cron

    public function test_bug_a_gateway_subscription_keeps_charging_the_disputed_card_and_staff_are_not_told(): void
    {
        $product = $this->createProduct();
        Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10.00, 'subscription_id' => 'sub_1']);
        $invoice = $this->invoice(100);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100.00, transactionId: 'pi_over', currency: 'usd');

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_over', 'dp_1', 100.00, 'usd');

        $this->assertFalse($this->autoCharge());
        // AutoCharge::due() skips a subscribed service: the gateway charges the card itself. Turning auto_charge off does
        // not stop it, so "a card that disputed a charge is not charged again by itself" does not hold for it.
        $this->assertStringContainsString('subscription', $this->staffBodies(),
            'the account has a gateway subscription that will charge the disputed card again: staff must be told (or it cancelled)');
    }
}
