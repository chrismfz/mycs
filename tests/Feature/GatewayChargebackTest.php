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
use App\Services\Invoice\RecordPaymentService;
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
 * PR 2c-2b (docs/payments.md, decision 2026-10-14): a chargeback is recorded and the invoice is owed again (services
 * untouched, staff decide); a dispute won undoes it and the invoice is paid again.
 */
class GatewayChargebackTest extends TestCase
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

        return Service::factory()->create(['user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addDays(20), 'currency_code' => 'USD', 'price' => 100.00]);
    }

    private function paidInvoice(string $gateway, string $transactionId, ?Service $service = null): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->subDays(3)]);
        $invoice->items()->create(array_filter(['description' => 'Hosting', 'quantity' => 1, 'price' => 100,
            'reference_type' => $service ? Service::class : null, 'reference_id' => $service?->id,
            'period_start' => $service ? now()->subDays(10)->toDateString() : null, 'period_end' => $service ? now()->addDays(20)->toDateString() : null]));
        ExtensionHelper::addPayment($invoice->id, $gateway, 100, transactionId: $transactionId, currency: 'usd');
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

    private function staffMails(string $subject): int
    {
        return EmailLog::query()->where('subject', $subject)->count();
    }

    public function test_an_opened_dispute_only_tells_staff_once(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_open');

        $this->stripeWebhook('charge.dispute.created', 'dp_open', 'pi_open', 10000)->assertOk();
        $this->stripeWebhook('charge.dispute.created', 'dp_open', 'pi_open', 10000)->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('0.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(1, $this->staffMails('Payment disputed at the gateway'));
    }

    public function test_a_chargeback_makes_the_invoice_owed_again_and_leaves_the_service_alone(): void
    {
        $this->stripe();
        $service = $this->service();
        $invoice = $this->paidInvoice('Stripe', 'pi_cb', $service);

        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_cb', 'pi_cb', 10000)->assertOk();
        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_cb', 'pi_cb', 10000)->assertOk(); // retried

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertEquals(100, (float) $invoice->total, 'it keeps its total: not a refund');
        $this->assertEquals(100, (float) $invoice->remaining);
        $this->assertSame(0, $invoice->adjustmentNotes()->count());
        $this->assertSame('100.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(1, MoneyEvent::query()->where('kind', MoneyEventKind::Refund->value)->where('cause', MoneyTrail::CAUSE_CHARGEBACK)->count());
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'services are staff\'s to decide');
        $this->assertSame(1, $this->staffMails('Chargeback needs staff attention'));
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    public function test_a_won_dispute_undoes_the_chargeback_and_the_invoice_is_paid_again(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_won');
        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_won', 'pi_won', 10000)->assertOk();

        $this->stripeWebhook('charge.dispute.funds_reinstated', 'dp_won', 'pi_won', 10000)->assertOk();
        $this->stripeWebhook('charge.dispute.funds_reinstated', 'dp_won', 'pi_won', 10000)->assertOk(); // retried

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('0.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(GatewayRefund::ORIGIN_CHARGEBACK_WON, GatewayRefund::query()->sole()->origin);
        $this->assertSame(1, MoneyEvent::query()->where('kind', MoneyEventKind::Reversal->value)->where('cause', MoneyTrail::CAUSE_CHARGEBACK_WON)->count());
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    public function test_a_customer_who_paid_again_before_the_dispute_was_won_gets_the_second_payment_as_credit(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_twice');
        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_twice', 'pi_twice', 10000)->assertOk();
        (new RecordPaymentService)->handle($invoice->fresh(), null, 100, transactionId: 'bank-again');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $this->stripeWebhook('charge.dispute.funds_reinstated', 'dp_twice', 'pi_twice', 10000)->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertEquals(100, (float) Credit::query()->where('user_id', $this->user->id)->value('amount'), 'paid twice: 100.00 over');
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    public function test_a_partial_chargeback_leaves_that_much_to_pay(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_part');

        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_part', 'pi_part', 4000)->assertOk();

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertEquals(40, (float) $invoice->fresh()->remaining);
    }

    public function test_a_chargeback_after_a_refund_records_what_was_left_and_tells_staff(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_after');
        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 30.00, notify: false);

        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_after', 'pi_after', 10000)->assertOk();

        $payment = $invoice->transactions()->sole();
        $this->assertSame('100.00', (string) $payment->refunded_amount, '30.00 refunded + 70.00 chargeback');
        $this->assertSame('70.00', (string) GatewayRefund::query()->where('origin', GatewayRefund::ORIGIN_CHARGEBACK)->sole()->recorded_amount);
        $this->assertStringContainsString('30.00 of it was no longer on the payment', EmailLog::query()->where('subject', 'Chargeback needs staff attention')->sole()->body);
    }

    public function test_a_chargeback_for_a_payment_we_do_not_know_is_ignored(): void
    {
        $this->stripe();

        $this->stripeWebhook('charge.dispute.funds_withdrawn', 'dp_other', 'pi_not_ours', 1000)->assertOk();

        $this->assertSame(0, GatewayRefund::query()->count());
    }

    public function test_a_paypal_reversal_and_a_dispute_won_in_our_favour(): void
    {
        $this->paypal();
        $invoice = $this->paidInvoice('PayPal', 'CAP-CB');

        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.REVERSED', 'resource' => [
            'id' => 'REV-1', 'status' => 'COMPLETED', 'amount' => ['value' => '-100.00', 'currency_code' => 'USD'],
            'links' => [['rel' => 'up', 'href' => self::PAYPAL_API . '/v2/payments/captures/CAP-CB']],
        ]])->assertOk();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        $resolved = ['event_type' => 'CUSTOMER.DISPUTE.RESOLVED', 'resource' => [
            'dispute_id' => 'PP-D-1', 'dispute_amount' => ['value' => '100.00', 'currency_code' => 'USD'],
            'disputed_transactions' => [['seller_transaction_id' => 'CAP-CB']],
            'dispute_outcome' => ['outcome_code' => 'RESOLVED_SELLER_FAVOUR'],
        ]];
        $this->postJson('/extensions/paypal/webhook', $resolved)->assertOk();
        $this->postJson('/extensions/paypal/webhook', $resolved)->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('0.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    public function test_a_paypal_dispute_lost_changes_nothing_more(): void
    {
        $this->paypal();
        $invoice = $this->paidInvoice('PayPal', 'CAP-LOST');
        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.REVERSED', 'resource' => [
            'id' => 'REV-2', 'status' => 'COMPLETED', 'amount' => ['value' => '100.00', 'currency_code' => 'USD'],
            'links' => [['rel' => 'up', 'href' => self::PAYPAL_API . '/v2/payments/captures/CAP-LOST']],
        ]])->assertOk();

        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'CUSTOMER.DISPUTE.RESOLVED', 'resource' => [
            'dispute_id' => 'PP-D-2', 'dispute_amount' => ['value' => '100.00', 'currency_code' => 'USD'],
            'disputed_transactions' => [['seller_transaction_id' => 'CAP-LOST']],
            'dispute_outcome' => ['outcome_code' => 'RESOLVED_BUYER_FAVOUR'],
        ]])->assertOk();

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame(GatewayRefund::ORIGIN_CHARGEBACK, GatewayRefund::query()->sole()->origin);
    }
}
