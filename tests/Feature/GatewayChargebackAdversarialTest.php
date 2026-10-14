<?php

namespace Tests\Feature;

use App\Enums\MoneyEventKind;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\Invoice;
use App\Models\MoneyEvent;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Paymenter\Extensions\Gateways\PayPal\PayPal;
use Paymenter\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * Adversarial review of PR 2c-2b (chargebacks). test_bug_* failed on 5b48297 (defects F1-F8, fixed in the PR);
 * test_guard_* passed then and document what held up (PayPal two reversals adjusted to the F3 fix: staff decide).
 */
class GatewayChargebackAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    private const PAYPAL_API = 'https://api-m.sandbox.paypal.com';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
    }

    // ----------------------------------------------------------------- helpers

    private function stripe(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        (new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET]))->boot();
        Http::fake();
    }

    private function paypal(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        foreach (['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        Http::fake([
            self::PAYPAL_API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::PAYPAL_API . '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);
        /** @var PayPal $paypal */
        $paypal = ExtensionHelper::getExtension('gateway', 'PayPal', $gateway->fresh()->settings);
        $paypal->boot();
    }

    private function service(): Service
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 100.00]);

        return Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addDays(20), 'currency_code' => 'USD', 'price' => 100.00]);
    }

    private function openInvoice(?Service $service = null, string $type = 'line', float $price = 100): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->subDays(3)]);
        if ($type === 'credit') {
            $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => $price]);
        } else {
            $invoice->items()->create(array_filter(['description' => 'Hosting', 'quantity' => 1, 'price' => $price,
                'reference_type' => $service ? Service::class : null, 'reference_id' => $service?->id,
                'period_start' => $service ? now()->subDays(10)->toDateString() : null, 'period_end' => $service ? now()->addDays(20)->toDateString() : null]));
        }

        return $invoice->fresh();
    }

    private function paidInvoice(string $gateway, string $transactionId, ?Service $service = null, string $type = 'line', float $price = 100): Invoice
    {
        $invoice = $this->openInvoice($service, $type, $price);
        ExtensionHelper::addPayment($invoice->id, $gateway, $price, transactionId: $transactionId, currency: 'usd');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        return $invoice->fresh();
    }

    private function stripeWebhook(string $type, string $dispute, string $paymentIntent, int $cents)
    {
        $payload = json_encode(['type' => $type, 'data' => ['object' => [
            'id' => $dispute, 'object' => 'dispute', 'amount' => $cents, 'currency' => 'usd', 'payment_intent' => $paymentIntent, 'status' => 'needs_response',
        ]]]);
        $time = time();

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=" . hash_hmac('sha256', $time . '.' . $payload, self::SECRET),
        ], $payload);
    }

    private function paypalReversed(string $id, string $capture, string $value)
    {
        return $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.REVERSED', 'resource' => [
            'id' => $id, 'status' => 'COMPLETED', 'amount' => ['value' => $value, 'currency_code' => 'USD'],
            'links' => [['rel' => 'up', 'href' => self::PAYPAL_API . '/v2/payments/captures/' . $capture]],
        ]]);
    }

    private function paypalResolved(string $dispute, string $capture, string $value, string $outcome = 'RESOLVED_SELLER_FAVOUR')
    {
        return $this->postJson('/extensions/paypal/webhook', ['event_type' => 'CUSTOMER.DISPUTE.RESOLVED', 'resource' => [
            'dispute_id' => $dispute, 'dispute_amount' => ['value' => $value, 'currency_code' => 'USD'],
            'disputed_transactions' => [['seller_transaction_id' => $capture]],
            'dispute_outcome' => ['outcome_code' => $outcome],
        ]]);
    }

    private function credit(): float
    {
        return (float) (Credit::query()->where('client_id', $this->user->client()->id)->where('currency_code', 'USD')->value('amount') ?? 0);
    }

    private function assertMoneyCheckClean(): void
    {
        $findings = (new MoneyCheck)->run();
        $this->assertSame([], $findings, json_encode($findings));
    }

    // ------------------------------------------------------------------- bugs

    /**
     * F1. Chargeback -> the invoice is unpaid and holds no money -> it is cancelled (CancelOpenInvoices: the cron's
     * overdue path, or a cancellation) -> the dispute is won: the money comes back onto a CANCELLED invoice. Nothing
     * settles it or turns it into credit; MoneyCheck flags cancelled_holds_money. Customer's 100 is stuck.
     */
    public function test_bug_dispute_won_after_the_reopened_invoice_was_cancelled_leaves_money_on_a_cancelled_invoice(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_cancel');
        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_cancel', 'pi_cancel', 10000)->assertOk();
        $this->assertSame([], CancelOpenInvoices::handle([$invoice->fresh()], 'Overdue'));
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);

        $this->stripeWebhook('charge.dispute.funds_reinstated', 'dp_cancel', 'pi_cancel', 10000)->assertOk();

        // The money is the customer's: either the invoice is paid again (reopened) or the 100 becomes account credit.
        $invoice->refresh();
        $this->assertTrue($invoice->status === Invoice::STATUS_PAID || abs($this->credit() - 100) < 0.001,
            "invoice {$invoice->status}, credit {$this->credit()}: the reinstated 100.00 went nowhere");
        $this->assertMoneyCheckClean();
    }

    /**
     * F2. Chargeback -> the customer pays again (bank) -> staff refund that second payment (the customer asks) -> the
     * invoice is «refunded» (final). The dispute is won: payment #1 holds 100 again on a refunded invoice with a total
     * of 0. MoneyCheck: paid_balance (paid over 100) and cancelled_holds_money.
     */
    public function test_bug_dispute_won_after_the_repayment_was_refunded_leaves_money_on_a_refunded_invoice(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_rr');
        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_rr', 'pi_rr', 10000)->assertOk();
        $second = (new RecordPaymentService)->handle($invoice->fresh(), null, 100, transactionId: 'bank-rr');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        ExtensionHelper::refundLocally($second->fresh(), 100, notify: false);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);

        $this->stripeWebhook('charge.dispute.funds_reinstated', 'dp_rr', 'pi_rr', 10000)->assertOk();

        $this->assertEqualsWithDelta(100, $this->credit(), 0.001, 'the 100.00 given back by the gateway is the customer\'s: credit');
        $this->assertMoneyCheckClean();
    }

    /**
     * F3. PayPal, several disputes on one capture. Dispute A (50) is a chargeback (REVERSED 50) and is LOST. Dispute B
     * (50) is opened later on the same capture, PayPal only holds the funds (no REVERSED), and is resolved in our
     * favour. chargebackReversedAtGateway matches «the oldest chargeback of that amount on the payment» = A's, and
     * undoes it: the invoice is paid again and the payment holds 100 although PayPal took 50 for good. Money from
     * nothing.
     */
    public function test_bug_paypal_dispute_won_reinstates_another_disputes_lost_chargeback(): void
    {
        $this->paypal();
        $invoice = $this->paidInvoice('PayPal', 'CAP-MULTI');
        $this->paypalReversed('REV-A', 'CAP-MULTI', '-50.00')->assertOk();
        $this->paypalResolved('PP-A', 'CAP-MULTI', '50.00', 'RESOLVED_BUYER_FAVOUR')->assertOk(); // A lost
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        $this->paypalResolved('PP-B', 'CAP-MULTI', '50.00')->assertOk(); // B won, its money was never taken

        $this->assertSame(GatewayRefund::ORIGIN_CHARGEBACK, GatewayRefund::query()->where('reference', 'REV-A')->value('origin'), 'A was lost: its chargeback stands');
        $this->assertSame('50.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, '50.00 is still owed');
    }

    /**
     * F4. Out of order (Stripe does not guarantee event order; a funds_withdrawn that failed is retried for days):
     * funds_reinstated arrives first and is dropped as chargeback_unknown, then funds_withdrawn records the chargeback.
     * The dispute was won and the money is back at Stripe, but the invoice stays unpaid for ever and dunning runs.
     */
    public function test_bug_stripe_reinstated_before_withdrawn_leaves_a_won_dispute_charged_back(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_ooo');

        $this->stripeWebhook('charge.dispute.funds_reinstated', 'dp_ooo', 'pi_ooo', 10000)->assertOk();
        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_ooo', 'pi_ooo', 10000)->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'won: the money is back at Stripe');
        $this->assertSame('0.00', (string) $invoice->transactions()->sole()->refunded_amount);
    }

    /**
     * F5. PayPal, same ordering problem, worse: the Cache key «paypal-dispute-won:<id>» is taken BEFORE the reversal is
     * attempted, so even a redelivery of RESOLVED (PayPal resend, staff «Resend» in the dashboard) after the REVERSED
     * arrived does nothing.
     */
    public function test_bug_paypal_resolved_before_reversed_is_never_applied_even_when_resent(): void
    {
        $this->paypal();
        $invoice = $this->paidInvoice('PayPal', 'CAP-OOO');

        $this->paypalResolved('PP-OOO', 'CAP-OOO', '100.00')->assertOk();
        $this->paypalReversed('REV-OOO', 'CAP-OOO', '-100.00')->assertOk();
        $this->paypalResolved('PP-OOO', 'CAP-OOO', '100.00')->assertOk(); // resent

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('0.00', (string) $invoice->transactions()->sole()->refunded_amount);
    }

    /**
     * F6. Credit purchase charged back: the credit it bought is taken back (as designed). The customer pays the
     * reopened invoice again: it is paid, but GrantBoughtCreditService skips the line (applied_at is still set), so
     * the customer paid 100 net and holds 0 credit. Nobody is told.
     */
    public function test_bug_credit_purchase_paid_again_after_a_chargeback_gives_no_credit(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_cp', type: 'credit');
        $this->assertEqualsWithDelta(100, $this->credit(), 0.001);
        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_cp', 'pi_cp', 10000)->assertOk();
        $this->assertEqualsWithDelta(0, $this->credit(), 0.001);

        (new RecordPaymentService)->handle($invoice->fresh(), null, 100, transactionId: 'bank-cp');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(100, $this->credit(), 0.001, 'paid 100 (net) for 100 of credit: has none');
    }

    /**
     * F7. A chargeback for a payment that is still processing here (SEPA/ACH: payment_intent.processing, succeeded days
     * later; or the succeeded webhook still being retried) is dropped (cached «unknown» for 120 days). When the payment
     * succeeds the invoice becomes paid and the chargeback is never recorded. Refunds have a «waiting» row for exactly
     * this (2c-2a F3); chargebacks do not.
     */
    public function test_bug_chargeback_before_the_payment_succeeded_here_is_lost(): void
    {
        $this->stripe();
        $invoice = $this->openInvoice();
        ExtensionHelper::addProcessingPayment($invoice->id, 'Stripe', 100, transactionId: 'pi_slow', currency: 'usd');

        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_slow', 'pi_slow', 10000)->assertOk();
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 100, transactionId: 'pi_slow', currency: 'usd');

        $this->assertSame('100.00', (string) $invoice->transactions()->sole()->refunded_amount, 'the gateway took it back');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /**
     * F8. Credit purchase charged back, the customer pays PART of it again, the dispute is won: the invoice is over its
     * total (100 + 30 on a 100 invoice), settle() refuses a credit purchase over its total, the invoice stays «unpaid»
     * with nothing to pay (MoneyCheck open_settled) and the 30 is nowhere.
     */
    public function test_bug_credit_purchase_partly_paid_again_then_won_stays_unpaid_with_nothing_owed(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_cp2', type: 'credit');
        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_cp2', 'pi_cp2', 10000)->assertOk();
        (new RecordPaymentService)->handle($invoice->fresh(), null, 30, transactionId: 'bank-cp2');

        $this->stripeWebhook('charge.dispute.funds_reinstated', 'dp_cp2', 'pi_cp2', 10000)->assertOk();

        $this->assertNotSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'nothing is owed on it');
        $this->assertMoneyCheckClean();
    }

    // ----------------------------------------------------------------- guards

    /** Duplicates of every event change nothing more (Stripe). */
    public function test_guard_duplicate_withdrawn_and_reinstated_apply_once(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_dup');
        for ($i = 0; $i < 3; $i++) {
            $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_dup', 'pi_dup', 10000)->assertOk();
        }
        $this->assertSame('100.00', (string) $invoice->transactions()->sole()->refunded_amount);
        for ($i = 0; $i < 3; $i++) {
            $this->stripeWebhook('charge.dispute.funds_reinstated', 'dp_dup', 'pi_dup', 10000)->assertOk();
        }
        // withdrawn again after the reversal (a late retry): the row is chargeback_won, still a replay
        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_dup', 'pi_dup', 10000)->assertOk();

        $this->assertSame('0.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(1, MoneyEvent::query()->where('cause', MoneyTrail::CAUSE_CHARGEBACK_WON)->count());
        $this->assertEqualsWithDelta(0, $this->credit(), 0.001);
        $this->assertMoneyCheckClean();
    }

    /** A reinstated for a dispute never withdrawn (an inquiry) records nothing; refunded_amount never goes negative. */
    public function test_guard_reinstated_without_withdrawn_creates_no_money(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_inq');

        $this->stripeWebhook('charge.dispute.funds_reinstated', 'dp_inq', 'pi_inq', 10000)->assertOk();

        $this->assertSame('0.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertEqualsWithDelta(0, $this->credit(), 0.001);
        $this->assertMoneyCheckClean();
    }

    /** Renewal: chargeback, paid again, won. The service is renewed once; the second payment becomes credit. */
    public function test_guard_service_renewed_once_through_chargeback_repayment_and_win(): void
    {
        $this->stripe();
        $service = $this->service();
        $invoice = $this->paidInvoice('Stripe', 'pi_ren', $service);
        $expires = (string) $service->fresh()->expires_at;

        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_ren', 'pi_ren', 10000)->assertOk();
        (new RecordPaymentService)->handle($invoice->fresh(), null, 100, transactionId: 'bank-ren');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame($expires, (string) $service->fresh()->expires_at, 'paid again: not renewed twice');

        $this->stripeWebhook('charge.dispute.funds_reinstated', 'dp_ren', 'pi_ren', 10000)->assertOk();

        $this->assertSame($expires, (string) $service->fresh()->expires_at);
        $this->assertEqualsWithDelta(100, $this->credit(), 0.001);
        $this->assertMoneyCheckClean();
    }

    /** CreditAutoApply pays the reopened invoice from credit, then the dispute is won: the credit comes back once. */
    public function test_guard_reopened_invoice_paid_from_credit_then_won_gives_the_credit_back_once(): void
    {
        config(['settings.credits_auto_use' => true]);
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_auto');
        $deposit = $this->paidInvoice('Stripe', 'pi_deposit', type: 'credit');
        $this->assertEqualsWithDelta(100, $this->credit(), 0.001);

        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_auto', 'pi_auto', 10000)->assertOk();
        (new CreditAutoApply)->forCustomer($this->user->id, 'USD');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(0, $this->credit(), 0.001);

        $this->stripeWebhook('charge.dispute.funds_reinstated', 'dp_auto', 'pi_auto', 10000)->assertOk();
        $this->stripeWebhook('charge.dispute.funds_reinstated', 'dp_auto', 'pi_auto', 10000)->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(100, $this->credit(), 0.001);
        $this->assertSame(Invoice::STATUS_PAID, $deposit->fresh()->status);
        $this->assertMoneyCheckClean();
    }

    /** Partly paid again then won: settle() gives the part over as credit. */
    public function test_guard_partly_paid_again_then_won_credits_the_part_over(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_half');
        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_half', 'pi_half', 10000)->assertOk();
        (new RecordPaymentService)->handle($invoice->fresh(), null, 40, transactionId: 'bank-half');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        $this->stripeWebhook('charge.dispute.funds_reinstated', 'dp_half', 'pi_half', 10000)->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(40, $this->credit(), 0.001);
        $this->assertMoneyCheckClean();
    }

    /** 2c-2a interplay: a dashboard refund then a chargeback of the rest, then won: back to the refund only. */
    public function test_guard_dashboard_refund_then_chargeback_then_won(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_mix');
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_mix', 're_mix', 30.00, 'usd');
        $this->assertSame('30.00', (string) $invoice->transactions()->sole()->refunded_amount);

        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_mix', 'pi_mix', 7000)->assertOk();
        $this->assertSame('100.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertEqualsWithDelta(70, (float) $invoice->fresh()->remaining, 0.001);
        // A late dashboard refund on a fully charged-back payment records nothing more
        ExtensionHelper::refundedAtGateway('Stripe', 'pi_mix', 're_mix2', 10.00, 'usd');
        $this->assertSame('100.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertMoneyCheckClean();

        $this->stripeWebhook('charge.dispute.funds_reinstated', 'dp_mix', 'pi_mix', 7000)->assertOk();

        $this->assertSame('30.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertMoneyCheckClean();
    }

    /** Refunded to credit, then a chargeback: nothing left on the payment, nothing recorded, nothing negative. */
    public function test_guard_chargeback_after_refund_to_credit_records_nothing(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_rtc');
        ExtensionHelper::refundToCredit($invoice->transactions()->sole(), 100, notify: false);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);

        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_rtc', 'pi_rtc', 10000)->assertOk();

        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->assertSame('0.00', (string) GatewayRefund::query()->where('reference', 'dp_rtc')->value('recorded_amount'));
        $this->assertSame(1, EmailLog::query()->where('subject', 'Chargeback needs staff attention')->count());
        $this->assertMoneyCheckClean();
    }

    /** PayPal: two reversals of one capture, both recorded once; one dispute won undoes one of them only. */
    public function test_guard_paypal_two_reversals_one_won(): void
    {
        $this->paypal();
        $invoice = $this->paidInvoice('PayPal', 'CAP-TWO');
        $this->paypalReversed('REV-1', 'CAP-TWO', '-30.00')->assertOk();
        $this->paypalReversed('REV-1', 'CAP-TWO', '-30.00')->assertOk();
        $this->paypalReversed('REV-2', 'CAP-TWO', '-30.00')->assertOk();
        $this->assertSame('60.00', (string) $invoice->transactions()->sole()->refunded_amount);

        $this->paypalResolved('PP-W', 'CAP-TWO', '30.00')->assertOk();
        $this->paypalResolved('PP-W', 'CAP-TWO', '30.00')->assertOk();

        // After the fix of F3: PayPal does not say which reversal the won dispute took, and two stand: none is undone
        // on a guess (it could be another dispute's, lost); staff are told, once.
        $this->assertSame('60.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(0, GatewayRefund::query()->where('origin', GatewayRefund::ORIGIN_CHARGEBACK_WON)->count());
        $this->assertSame(1, EmailLog::query()->where('subject', 'Chargeback needs staff attention')->where('body', 'like', '%PP-W%')->count());
        $this->assertMoneyCheckClean();
    }

    /** A chargeback in another currency is not recorded; its «win» moves no money either. */
    public function test_guard_currency_mismatch_chargeback_and_win_move_nothing(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_eur');
        $payload = fn (string $type) => json_encode(['type' => $type, 'data' => ['object' => [
            'id' => 'dp_eur', 'object' => 'dispute', 'amount' => 10000, 'currency' => 'eur', 'payment_intent' => 'pi_eur', 'status' => 'needs_response',
        ]]]);
        foreach (['charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated'] as $type) {
            $body = $payload($type);
            $time = time();
            $this->call('POST', '/extensions/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$time},v1=" . hash_hmac('sha256', $time . '.' . $body, self::SECRET)], $body)->assertOk();
        }

        $this->assertSame('0.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(0, MoneyEvent::query()->where('kind', MoneyEventKind::Refund->value)->count());
        $this->assertMoneyCheckClean();
    }

    /** Dispute opened: one staff email per dispute, no money moved (PayPal). */
    public function test_guard_paypal_dispute_created_only_mails_once(): void
    {
        $this->paypal();
        $invoice = $this->paidInvoice('PayPal', 'CAP-OPEN');
        $created = ['event_type' => 'CUSTOMER.DISPUTE.CREATED', 'resource' => [
            'dispute_id' => 'PP-OPEN', 'dispute_amount' => ['value' => '100.00', 'currency_code' => 'USD'],
            'disputed_transactions' => [['seller_transaction_id' => 'CAP-OPEN']],
        ]];
        $this->postJson('/extensions/paypal/webhook', $created)->assertOk();
        $this->postJson('/extensions/paypal/webhook', $created)->assertOk();

        $this->assertSame(1, EmailLog::query()->where('subject', 'Payment disputed at the gateway')->count());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('0.00', (string) $invoice->transactions()->sole()->refunded_amount);
    }
}
