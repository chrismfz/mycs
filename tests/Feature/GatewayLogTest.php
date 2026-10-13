<?php

namespace Tests\Feature;

use App\Admin\Resources\PaymentGatewayEventResource;
use App\Admin\Resources\PaymentGatewayEventResource\Pages\ListPaymentGatewayEvents;
use App\Helpers\ExtensionHelper;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Paymenter\Extensions\Gateways\PayPal\PayPal;
use Paymenter\Extensions\Gateways\Stripe\Stripe;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * MT-2a (docs/money-trail.md §4): every request to a gateway's routes leaves one row in the gateway log, with what came
 * of the money, field names only. A failed request mails staff, once an hour per endpoint.
 */
class GatewayLogTest extends TestCase
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
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->user = User::factory()->create();
    }

    private function invoice(float $price = 100): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_PENDING, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    private function stripe(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        foreach (['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET] as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        (new Stripe(['stripe_secret_key' => 'sk_test', 'stripe_webhook_secret' => self::SECRET]))->boot();
    }

    /** @param  array<string, mixed>  $event */
    private function stripeWebhook(array $event, bool $signed = true)
    {
        $payload = json_encode($event);
        $time = time();
        $signature = $signed ? hash_hmac('sha256', $time . '.' . $payload, self::SECRET) : 'forged';

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}",
        ], $payload);
    }

    /** @return array<string, mixed> */
    private function succeeded(Invoice $invoice, int $cents, string $id, string $currency = 'usd'): array
    {
        return ['type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => $id, 'amount' => $cents, 'currency' => $currency, 'metadata' => ['invoice_id' => (string) $invoice->id],
        ]]];
    }

    public function test_a_signed_stripe_payment_is_logged_as_recorded_with_its_money(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100);

        $this->stripeWebhook($this->succeeded($invoice, 10000, 'pi_log_1'))->assertOk();

        $row = PaymentGatewayEvent::query()->sole();
        $this->assertSame(['stripe', 'webhook', 'POST', 'payment_intent.succeeded'], [$row->gateway, $row->endpoint, $row->method, $row->event_type]);
        $this->assertTrue($row->signature_verified);
        $this->assertSame(PaymentGatewayEvent::OUTCOME_RECORDED, $row->outcome);
        $this->assertSame(['100.00', 'USD', 'pi_log_1', $invoice->id], [$row->amount, $row->currency_code, $row->external_id, $row->invoice_id]);
        $this->assertSame($invoice->transactions()->sole()->id, $row->invoice_transaction_id);
        $this->assertSame(200, $row->http_status);
        $this->assertSame(['type', 'data'], $row->fields);
    }

    public function test_a_replay_is_ignored_and_a_currency_mismatch_rejected(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100);
        $this->stripeWebhook($this->succeeded($invoice, 10000, 'pi_log_2'))->assertOk();

        $this->stripeWebhook($this->succeeded($invoice, 10000, 'pi_log_2'))->assertOk();
        $this->stripeWebhook($this->succeeded($this->invoice(50), 5000, 'pi_log_3', 'eur'))->assertOk();

        $rows = PaymentGatewayEvent::query()->orderBy('id')->get();
        $this->assertSame([['recorded', null], ['ignored', 'replay'], ['rejected', 'currency_mismatch']],
            $rows->map(fn (PaymentGatewayEvent $row) => [$row->outcome, $row->reason])->all());
        $this->assertSame('EUR', $rows[2]->currency_code);
        $this->assertSame(1, $invoice->transactions()->count());
    }

    public function test_a_forged_signature_is_rejected_and_nothing_is_kept_but_names(): void
    {
        $this->stripe();
        $invoice = $this->invoice(100);
        $event = $this->succeeded($invoice, 10000, 'pi_forged') + ['card_number' => '4111111111111111'];

        $this->stripeWebhook($event, signed: false)->assertStatus(400);

        $row = PaymentGatewayEvent::query()->sole();
        $this->assertFalse($row->signature_verified);
        $this->assertSame([PaymentGatewayEvent::OUTCOME_REJECTED, 'http_400', 400], [$row->outcome, $row->reason, $row->http_status]);
        $this->assertContains('card_number', $row->fields);
        $this->assertStringNotContainsString('4111111111111111', json_encode($row->getAttributes()));
        $this->assertSame(0, $invoice->transactions()->count());
    }

    public function test_an_event_that_records_nothing_is_ignored(): void
    {
        $this->stripe();

        $this->stripeWebhook(['type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_1']]])->assertOk();

        $row = PaymentGatewayEvent::query()->sole();
        $this->assertSame([PaymentGatewayEvent::OUTCOME_IGNORED, 'nothing_recorded', 'customer.created'], [$row->outcome, $row->reason, $row->event_type]);
    }

    public function test_a_failing_gateway_request_is_an_error_and_staff_hear_once_an_hour(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        foreach (['test_mode' => '1', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'wh'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
        Http::fake([
            self::PAYPAL_API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::PAYPAL_API . '/v1/notifications/verify-webhook-signature' => Http::response(['name' => 'SERVICE_UNAVAILABLE'], 503),
        ]);
        /** @var PayPal $paypal */
        $paypal = ExtensionHelper::getExtension('gateway', 'PayPal', $gateway->fresh()->settings);
        $paypal->boot();
        $payload = ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => []];

        $this->postJson('/extensions/paypal/webhook', $payload)->assertStatus(500);
        $this->postJson('/extensions/paypal/webhook', $payload)->assertStatus(500);

        $rows = PaymentGatewayEvent::query()->orderBy('id')->get();
        $this->assertSame([['paypal', 'webhook', 'error', 'http_500'], ['paypal', 'webhook', 'error', 'http_500']],
            $rows->map(fn (PaymentGatewayEvent $row) => [$row->gateway, $row->endpoint, $row->outcome, $row->reason])->all());
        $this->assertSame('PAYMENT.CAPTURE.COMPLETED', $rows[0]->event_type);
        $this->assertSame(1, EmailLog::query()->where('subject', 'Gateway request failed')->count(), 'cooldown: one mail an hour');
    }

    public function test_payments_outside_a_gateway_request_and_other_routes_leave_no_row(): void
    {
        $this->stripe();
        ExtensionHelper::addPayment($this->invoice(100)->id, 'Stripe', 100.00, null, 'pi_direct', currency: 'USD');
        $this->getJson('/api/v1/admin/invoices')->assertUnauthorized(); // any other route

        $this->assertSame(0, PaymentGatewayEvent::query()->count());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_gateway_log_is_read_only_and_needs_its_permission(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $row = PaymentGatewayEvent::query()->create(['gateway' => 'stripe', 'endpoint' => 'webhook', 'method' => 'POST', 'outcome' => 'recorded']);
        $clerk = User::factory()->create(['role_id' => Role::create(['name' => 'Clerk' . uniqid(), 'permissions' => ['admin.invoices.viewAny']])->id]);
        $owner = User::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        $this->actingAs($clerk);
        $this->assertFalse(PaymentGatewayEventResource::canAccess());

        $this->actingAs($owner);
        $this->assertFalse($owner->can('update', $row));
        $this->assertFalse($owner->can('delete', $row));
        Livewire::actingAs($owner)->test(ListPaymentGatewayEvents::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$row]);
    }
}
