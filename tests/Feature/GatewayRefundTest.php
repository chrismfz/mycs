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
use App\Models\Notification;
use App\Models\PaymentGatewayEvent;
use App\Models\User;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Paymenter\Extensions\Gateways\PayPal\PayPal;
use Paymenter\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * PR 2c-2a (docs/payments.md): a refund made in the gateway's dashboard is recorded from its webhook, once per refund
 * id; MyCS's own refund, echoed by the webhook, is not recorded twice; the customer is told of every refund.
 */
class GatewayRefundTest extends TestCase
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
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.credits_enabled' => true]);
        $this->user = User::factory()->create();
    }

    private function stripe(array $fakes = []): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        (new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET]))->boot();
        Http::fake($fakes);
    }

    private function paypal(array $fakes): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        foreach (['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $all = [self::PAYPAL_API . '/v1/oauth2/token' => Http::response(['access_token' => 'token'])];
        foreach ($fakes as $path => $response) {
            $all[self::PAYPAL_API . $path] = $response;
        }
        Http::fake($all);
        /** @var PayPal $paypal */
        $paypal = ExtensionHelper::getExtension('gateway', 'PayPal', $gateway->fresh()->settings);
        $paypal->boot();
    }

    private function paidInvoice(string $gateway, string $transactionId, float $price = 100): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);
        ExtensionHelper::addPayment($invoice->id, $gateway, $price, transactionId: $transactionId, currency: 'usd');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        return $invoice->fresh();
    }

    private function stripeWebhook(array $event)
    {
        $payload = json_encode($event);
        $time = time();
        $signature = hash_hmac('sha256', $time . '.' . $payload, self::SECRET);

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}",
        ], $payload);
    }

    private function stripeRefund(string $id, string $paymentIntent, int $cents, string $status = 'succeeded', string $type = 'refund.created'): array
    {
        return ['type' => $type, 'data' => ['object' => [
            'id' => $id, 'object' => 'refund', 'amount' => $cents, 'currency' => 'usd', 'payment_intent' => $paymentIntent, 'status' => $status,
        ]]];
    }

    private function refundNotices(): int
    {
        return Notification::query()->where('user_id', $this->user->id)->where('title', 'like', 'Refund for invoice%')->count();
    }

    public function test_a_refund_made_in_stripes_dashboard_is_recorded_once(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_dash');
        $payment = $invoice->transactions()->sole();

        $this->stripeWebhook($this->stripeRefund('re_dash', 'pi_dash', 3000))->assertOk();
        $this->stripeWebhook($this->stripeRefund('re_dash', 'pi_dash', 3000, type: 'refund.updated'))->assertOk(); // the same refund again

        $this->assertSame('30.00', (string) $payment->fresh()->refunded_amount);
        $this->assertSame(1, MoneyEvent::query()->where('kind', MoneyEventKind::Refund->value)->count());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, '70.00 is still on it');
        $this->assertSame(['30.00', '30.00', GatewayRefund::ORIGIN_GATEWAY], [
            (string) GatewayRefund::query()->sole()->amount, (string) GatewayRefund::query()->sole()->recorded_amount, GatewayRefund::query()->sole()->origin,
        ]);
        $this->assertSame(1, $this->refundNotices(), 'the customer is told once');
        $this->assertSame([['recorded', 'refund'], ['ignored', 'replay']],
            PaymentGatewayEvent::query()->where('event_type', 'like', 'refund.%')->orderBy('id')->get()->map(fn ($row) => [$row->outcome, $row->reason])->all());
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    public function test_a_full_dashboard_refund_makes_the_invoice_refunded(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_full');

        $this->stripeWebhook($this->stripeRefund('re_full', 'pi_full', 10000))->assertOk();

        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
    }

    public function test_mycs_own_stripe_refund_echoed_by_the_webhook_is_not_recorded_twice(): void
    {
        $this->stripe(['https://api.stripe.com/v1/refunds' => Http::response(['id' => 're_ours', 'object' => 'refund', 'status' => 'succeeded'])]);
        $invoice = $this->paidInvoice('Stripe', 'pi_ours');
        $payment = $invoice->transactions()->sole();

        ExtensionHelper::refund($payment, 40.00);
        $this->stripeWebhook($this->stripeRefund('re_ours', 'pi_ours', 4000))->assertOk();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/refunds'));
        $this->assertSame('40.00', (string) $payment->fresh()->refunded_amount);
        $this->assertSame(GatewayRefund::ORIGIN_MYCS, GatewayRefund::query()->sole()->origin);
        $this->assertSame(1, $this->refundNotices());
    }

    public function test_a_pending_stripe_refund_is_recorded_and_a_later_failure_is_flagged_to_staff(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_pend');

        $this->stripeWebhook($this->stripeRefund('re_pend', 'pi_pend', 1000, 'pending'))->assertOk();
        $this->stripeWebhook($this->stripeRefund('re_pend', 'pi_pend', 1000, 'failed', 'refund.failed'))->assertOk();

        $this->assertSame('10.00', (string) $invoice->transactions()->sole()->refunded_amount, 'not undone on its own');
        $this->assertSame(1, EmailLog::query()->where('subject', 'Gateway refund failed after it was recorded')->count());
    }

    public function test_a_dashboard_refund_of_more_than_is_left_records_what_is_left_and_tells_staff(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_more');
        $payment = $invoice->transactions()->sole();
        ExtensionHelper::refundToCredit($payment, 60.00, notify: false);

        $this->stripeWebhook($this->stripeRefund('re_more', 'pi_more', 10000))->assertOk();

        $payment->refresh();
        $this->assertSame(['40.00', '60.00'], [(string) $payment->refunded_amount, (string) $payment->credited_amount]);
        $this->assertSame(['100.00', '40.00'], [(string) GatewayRefund::query()->sole()->amount, (string) GatewayRefund::query()->sole()->recorded_amount]);
        $this->assertSame(1, EmailLog::query()->where('subject', 'Gateway refund needs staff attention')->count());
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
    }

    public function test_a_refund_for_a_payment_we_do_not_know_is_ignored(): void
    {
        $this->stripe();

        $this->stripeWebhook($this->stripeRefund('re_other', 'pi_not_ours', 500))->assertOk();

        $this->assertSame(GatewayRefund::ORIGIN_WAITING, GatewayRefund::query()->sole()->origin, 'kept until its payment, if ever');
        $this->assertSame(['ignored', 'refund_unknown_payment'], [PaymentGatewayEvent::query()->sole()->outcome, PaymentGatewayEvent::query()->sole()->reason]);
    }

    public function test_a_refund_in_another_currency_is_not_recorded(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_eur');
        $event = $this->stripeRefund('re_eur', 'pi_eur', 1000);
        $event['data']['object']['currency'] = 'eur';

        $this->stripeWebhook($event)->assertOk();

        $this->assertSame('0.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(1, EmailLog::query()->where('subject', 'Gateway refund needs staff attention')->count());
    }

    public function test_a_dashboard_refund_of_a_credit_purchase_takes_back_the_credit_it_bought(): void
    {
        $this->stripe();
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['reference_type' => Credit::class, 'description' => 'Deposit', 'quantity' => 1, 'price' => 50]);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 50, transactionId: 'pi_credit', currency: 'usd');
        $this->assertEquals(50, (float) Credit::query()->where('user_id', $this->user->id)->value('amount'));

        $this->stripeWebhook($this->stripeRefund('re_credit', 'pi_credit', 5000))->assertOk();

        $this->assertEquals(0, (float) Credit::query()->where('user_id', $this->user->id)->value('amount'));
        $this->assertSame([], (new MoneyCheck)->run(), json_encode((new MoneyCheck)->run()));
    }

    public function test_a_paypal_capture_refunded_in_its_dashboard_is_recorded_once(): void
    {
        $this->paypal(['/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'])]);
        $invoice = $this->paidInvoice('PayPal', 'CAP-1');
        $event = ['event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => [
            'id' => 'REF-1', 'status' => 'COMPLETED', 'amount' => ['value' => '25.00', 'currency_code' => 'USD'],
            'links' => [['rel' => 'self', 'href' => self::PAYPAL_API . '/v2/payments/refunds/REF-1'], ['rel' => 'up', 'href' => self::PAYPAL_API . '/v2/payments/captures/CAP-1']],
        ]];

        $this->postJson('/extensions/paypal/webhook', $event)->assertOk();
        $this->postJson('/extensions/paypal/webhook', $event)->assertOk();

        $this->assertSame('25.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(1, GatewayRefund::query()->count());
    }

    public function test_a_paypal_subscription_sale_refunded_is_recorded(): void
    {
        $this->paypal(['/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'])]);
        $invoice = $this->paidInvoice('PayPal', 'SALE-1');

        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.SALE.REFUNDED', 'resource' => [
            'id' => 'SREF-1', 'sale_id' => 'SALE-1', 'state' => 'completed', 'amount' => ['total' => '-100.00', 'currency' => 'USD'],
        ]])->assertOk();

        $this->assertSame('100.00', (string) $invoice->transactions()->sole()->refunded_amount);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
    }

    public function test_mycs_own_paypal_refund_keeps_paypals_id(): void
    {
        $this->paypal([
            '/v2/payments/captures/CAP-2/refund' => Http::response(['id' => 'REF-OURS', 'status' => 'COMPLETED']),
            '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);
        $invoice = $this->paidInvoice('PayPal', 'CAP-2');
        $payment = $invoice->transactions()->sole();

        ExtensionHelper::refund($payment, 10.00);
        $this->postJson('/extensions/paypal/webhook', ['event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => [
            'id' => 'REF-OURS', 'status' => 'COMPLETED', 'amount' => ['value' => '10.00', 'currency_code' => 'USD'],
            'links' => [['rel' => 'up', 'href' => self::PAYPAL_API . '/v2/payments/captures/CAP-2']],
        ]])->assertOk();

        $this->assertSame('10.00', (string) $payment->fresh()->refunded_amount);
        $this->assertSame(['REF-OURS', GatewayRefund::ORIGIN_MYCS], [GatewayRefund::query()->sole()->reference, GatewayRefund::query()->sole()->origin]);
    }

    public function test_staff_can_refund_without_telling_the_customer(): void
    {
        $this->stripe();
        $invoice = $this->paidInvoice('Stripe', 'pi_quiet');
        $payment = $invoice->transactions()->sole();

        ExtensionHelper::refundLocally($payment, 5.00, notify: false);
        $this->assertSame(0, $this->refundNotices());

        ExtensionHelper::refundToCredit(InvoiceTransaction::find($payment->id), 5.00);
        $this->assertSame(1, $this->refundNotices(), 'told by default');
    }
}
