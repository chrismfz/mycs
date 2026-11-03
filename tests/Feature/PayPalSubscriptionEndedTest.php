<?php

namespace Tests\Feature;

use App\Admin\Actions\CancelSubscriptionAction;
use App\Helpers\ExtensionHelper;
use App\Models\Audit;
use App\Models\BillingAgreement;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGatewayEvent;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Invoice\MassPay;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SubscriptionEnded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A PayPal subscription the customer cancels in PayPal (or that PayPal suspends or that expires) kept its id on the
 * services it paid: the cron left their invoices to it (AutoCharge::due), the reminders said they pay themselves and Mass
 * Pay refused them, so nothing paid them (finding L3 of the review of #602). Its webhook now clears the id, once PayPal
 * says the subscription has ended; the id is kept aside so a sale of it that comes later is still recorded.
 */
class PayPalSubscriptionEndedTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api-m.sandbox.paypal.com';

    /** The status PayPal gives the subscription when asked (GET /v1/billing/subscriptions/{id}); an int is an HTTP error. */
    private string|int $status = 'CANCELLED';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->storeSettings(['system_email_address' => 'staff@example.test', 'mail_disable' => false, 'autocharge_days_before' => 0]);
        $gateway = Gateway::query()->create(['extension' => 'PayPal', 'name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        foreach (['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
        Http::preventStrayRequests();
        Http::fake([
            self::API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API . '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            self::API . '/v1/billing/subscriptions/*' => fn (Request $request) => is_int($this->status)
                ? Http::response(['name' => 'ERROR'], $this->status)
                : Http::response(['id' => basename($request->url()), 'status' => $this->status]),
        ]);
        $this->user = User::factory()->create();
        // A saved method with «Auto-charge» on: the cron would charge the invoice, were it not left to the subscription.
        BillingAgreement::query()->create(['client_id' => $this->user->client()->id, 'gateway_id' => $gateway->id,
            'name' => 'PayPal', 'external_reference' => 'TOKEN-1', 'type' => 'paypal']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An active service the subscription pays, with its open renewal invoice due today. */
    private function subscribed(string $subscription = 'I-SUB1', bool $marked = true): Service
    {
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD', 'price' => 10, 'expires_at' => '2026-11-10', 'subscription_id' => $subscription]);
        if ($marked) {
            $service->properties()->create(['key' => 'has_paypal_subscription', 'name' => 'PayPal', 'value' => '1']);
        }
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return $service->fresh();
    }

    private function invoiceOf(Service $service): Invoice
    {
        return $service->invoices()->orderBy('invoices.id')->firstOrFail();
    }

    private function event(string $type, string $id = 'I-SUB1'): TestResponse
    {
        return $this->postJson('/extensions/paypal/webhook', ['id' => 'WH-EV-' . $type, 'event_type' => $type,
            'resource' => ['id' => $id, 'status' => 'CANCELLED']]);
    }

    private function asked(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), '/v1/billing/subscriptions/'))->count();
    }

    public function test_a_cancellation_clears_the_id_so_the_invoice_is_charged_and_mass_payable_again(): void
    {
        $a = $this->subscribed();
        $b = $this->subscribed();
        $this->assertFalse(AutoCharge::picks($this->invoiceOf($a)), 'precondition: left to the subscription');
        $this->assertNotNull(MassPay::refusal($this->invoiceOf($a)), 'precondition: refused by Mass Pay');

        $this->event('BILLING.SUBSCRIPTION.CANCELLED')->assertOk();

        foreach ([$a, $b] as $service) {
            $service->refresh();
            $this->assertNull($service->subscription_id);
            $this->assertFalse($service->properties()->where('key', 'has_paypal_subscription')->exists(), 'its marker goes with it');
            $this->assertSame('I-SUB1', $service->properties()->where('key', SubscriptionEnded::keptKey('I-SUB1'))->value('value'));
            $this->assertTrue(AutoCharge::picks($this->invoiceOf($service)), 'the cron charges it again');
            $this->assertNull(MassPay::refusal($this->invoiceOf($service)), 'Mass Pay takes it again');
            $audit = Audit::query()->where('auditable_type', $service->getMorphClass())->where('auditable_id', $service->id)
                ->where('event', 'subscription_ended_at_gateway')->sole();
            $this->assertSame('I-SUB1', $audit->old_values['subscription_id']);
        }
        $this->assertSame(1, $this->asked(), 'PayPal asked once whether it ended');
    }

    /** @return array<string, array{string, string}> */
    public static function endings(): array
    {
        return [
            'expired' => ['BILLING.SUBSCRIPTION.EXPIRED', 'EXPIRED'],
            'suspended' => ['BILLING.SUBSCRIPTION.SUSPENDED', 'SUSPENDED'],
        ];
    }

    #[DataProvider('endings')]
    public function test_an_expired_or_suspended_subscription_clears_it_too(string $event, string $status): void
    {
        $service = $this->subscribed();
        $this->status = $status;

        $this->event($event)->assertOk();

        $this->assertNull($service->fresh()->subscription_id);
    }

    public function test_an_unknown_id_changes_nothing_and_asks_nothing(): void
    {
        $service = $this->subscribed('I-MINE');

        $this->event('BILLING.SUBSCRIPTION.CANCELLED', 'I-SOMEONE-ELSE')->assertOk();
        $this->event('BILLING.SUBSCRIPTION.CANCELLED', 'I-../../v1/vault')->assertOk();
        $this->event('BILLING.SUBSCRIPTION.CANCELLED', '')->assertOk();

        $this->assertSame('I-MINE', $service->fresh()->subscription_id);
        $this->assertSame(0, $this->asked());
    }

    public function test_a_replay_changes_nothing(): void
    {
        $service = $this->subscribed();
        $this->event('BILLING.SUBSCRIPTION.CANCELLED')->assertOk();

        $this->event('BILLING.SUBSCRIPTION.CANCELLED')->assertOk();

        $this->assertNull($service->fresh()->subscription_id);
        $this->assertSame(1, Audit::query()->where('event', 'subscription_ended_at_gateway')->count());
        $this->assertSame(1, $this->asked(), 'a replay finds nothing to clear: PayPal is not asked again');
    }

    /** An old suspension sent again after the subscription was activated again: it still bills, nothing is cleared. */
    public function test_an_old_event_of_a_subscription_that_still_bills_changes_nothing(): void
    {
        $service = $this->subscribed();
        $this->status = 'ACTIVE';

        $this->event('BILLING.SUBSCRIPTION.SUSPENDED')->assertOk();

        $this->assertSame('I-SUB1', $service->fresh()->subscription_id);
        $this->assertSame('1', $service->properties()->where('key', 'has_paypal_subscription')->value('value'));
        $this->assertSame('subscription_still_active', PaymentGatewayEvent::query()->latest('id')->value('reason'));
    }

    public function test_paypal_not_answering_keeps_the_id_and_the_webhook_is_retried(): void
    {
        $service = $this->subscribed();
        $this->status = 503;

        $this->event('BILLING.SUBSCRIPTION.CANCELLED')->assertStatus(500);

        $this->assertSame('I-SUB1', $service->fresh()->subscription_id);
    }

    public function test_paypal_not_knowing_the_subscription_keeps_the_id(): void
    {
        $service = $this->subscribed();
        $this->status = 404;

        $this->event('BILLING.SUBSCRIPTION.CANCELLED')->assertOk();

        $this->assertSame('I-SUB1', $service->fresh()->subscription_id);
    }

    /** Webhooks come in any order: the last sale, sent after the cancellation, is still the services' money. */
    public function test_a_sale_that_comes_after_the_cancellation_is_still_recorded(): void
    {
        $service = $this->subscribed();
        $this->event('BILLING.SUBSCRIPTION.CANCELLED')->assertOk();

        (new PayPal([]))->recordSubscriptionSale(['id' => 'SALE-LATE', 'billing_agreement_id' => 'I-SUB1',
            'amount' => ['total' => '10.00', 'currency' => 'USD']]);

        $this->assertSame(Invoice::STATUS_PAID, $this->invoiceOf($service)->status);
        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'SALE-LATE')->count());
    }

    public function test_a_sale_of_a_subscription_no_service_had_is_told_to_staff_not_recorded(): void
    {
        $this->subscribed('I-MINE');

        (new PayPal([]))->recordSubscriptionSale(['id' => 'SALE-X', 'billing_agreement_id' => 'I-UNKNOWN',
            'amount' => ['total' => '10.00', 'currency' => 'USD']]);

        $this->assertSame(0, InvoiceTransaction::query()->count());
        $this->assertSame(1, EmailLog::query()->where('subject', 'Gateway event for a payment not made here')->count());
    }

    /** Suspended (so cleared), then activated again in PayPal: staff decide whether it pays the services again. */
    public function test_a_subscription_active_again_is_told_to_staff_and_not_set_back(): void
    {
        $service = $this->subscribed();
        $this->status = 'SUSPENDED';
        $this->event('BILLING.SUBSCRIPTION.SUSPENDED')->assertOk();
        $this->status = 'ACTIVE';

        $this->event('BILLING.SUBSCRIPTION.ACTIVATED')->assertOk();
        $this->event('BILLING.SUBSCRIPTION.ACTIVATED')->assertOk(); // sent again: one mail

        $this->assertNull($service->fresh()->subscription_id);
        $this->assertSame(1, EmailLog::query()->where('subject', 'PayPal subscription active again')->count());
    }

    public function test_an_activation_of_a_subscription_never_ended_here_tells_no_one(): void
    {
        $this->subscribed();
        $this->status = 'ACTIVE';

        $this->event('BILLING.SUBSCRIPTION.ACTIVATED')->assertOk();
        $this->event('BILLING.SUBSCRIPTION.ACTIVATED', 'I-OTHER')->assertOk();

        $this->assertSame(0, EmailLog::query()->where('subject', 'PayPal subscription active again')->count());
        $this->assertSame(0, $this->asked());
    }

    /** The WHMCS-era IPN address: a cancelled legacy subscription a service still carries is cleared too. */
    public function test_a_legacy_ipn_cancellation_clears_the_id(): void
    {
        $this->storeSettings(['paypal_legacy_receivers' => 'billing@example.test']);
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);
        $service = $this->subscribed('I-LEGACY1', marked: false);
        $other = $this->subscribed('I-LEGACY2', marked: false);

        $this->call('POST', '/modules/gateways/callback/paypal.php', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query(['txn_type' => 'recurring_payment_profile_cancel', 'recurring_payment_id' => 'I-LEGACY1', 'receiver_email' => 'billing@example.test']))
            ->assertOk();
        // A payment notice of the other is no ending: nothing changes.
        $this->call('POST', '/modules/gateways/callback/paypal.php', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query(['txn_type' => 'subscr_payment', 'payment_status' => 'Completed', 'txn_id' => 'TX1', 'subscr_id' => 'I-LEGACY2', 'receiver_email' => 'billing@example.test']))
            ->assertOk();

        $this->assertNull($service->fresh()->subscription_id);
        $this->assertSame('I-LEGACY2', $other->fresh()->subscription_id);
        $this->assertSame(0, InvoiceTransaction::query()->count());
    }

    public function test_a_legacy_ipn_of_another_account_clears_nothing(): void
    {
        $this->storeSettings(['paypal_legacy_receivers' => 'billing@example.test']);
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);
        $service = $this->subscribed('I-LEGACY1');

        $this->call('POST', '/modules/gateways/callback/paypal.php', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query(['txn_type' => 'subscr_cancel', 'subscr_id' => 'I-LEGACY1', 'receiver_email' => 'someone@example.test']))
            ->assertOk();

        $this->assertSame('I-LEGACY1', $service->fresh()->subscription_id);
    }

    /** @param  array<string, string>  $fields */
    private function legacyIpn(array $fields): TestResponse
    {
        $this->storeSettings(['paypal_legacy_receivers' => 'billing@example.test']);
        Http::fake(['https://ipnpb.paypal.com/*' => Http::response('VERIFIED')]);

        return $this->call('POST', '/modules/gateways/callback/paypal.php', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query($fields + ['receiver_email' => 'billing@example.test']));
    }

    /** Code review of #605: a suspension may be undone, and an IPN has no status to ask: staff are told, nothing cleared. */
    public function test_a_legacy_ipn_suspension_clears_nothing(): void
    {
        $service = $this->subscribed('I-LEGACY1', marked: false);

        $this->legacyIpn(['txn_type' => 'recurring_payment_suspended_due_to_max_failed_payment', 'recurring_payment_id' => 'I-LEGACY1'])->assertOk();

        $this->assertSame('I-LEGACY1', $service->fresh()->subscription_id);
    }

    /** Code review of #605: a rate limit (or any answer but «not found») is retried, never taken as «nothing to do». */
    public function test_a_rate_limited_status_check_is_retried(): void
    {
        $service = $this->subscribed();
        $this->status = 429;

        $this->event('BILLING.SUBSCRIPTION.CANCELLED')->assertStatus(500);

        $this->assertSame('I-SUB1', $service->fresh()->subscription_id);
    }

    /** Code review of #605: staff set the id back after «active again»: its marker comes back, so ending the service cancels it. */
    public function test_an_id_set_back_by_staff_gets_its_marker_again(): void
    {
        $service = $this->subscribed();
        $this->event('BILLING.SUBSCRIPTION.SUSPENDED')->assertOk();
        $this->assertFalse($service->properties()->where('key', 'has_paypal_subscription')->exists());
        // Its listeners, as the app boots an enabled gateway on each request.
        ExtensionHelper::getExtension('gateway', 'PayPal', Gateway::query()->where('extension', 'PayPal')->sole()->settings)->boot();

        $service->fresh()->update(['subscription_id' => 'I-SUB1']);

        $this->assertSame('1', $service->properties()->where('key', 'has_paypal_subscription')->value('value'));
        $other = $this->subscribed('I-OTHER', marked: false);
        $other->update(['subscription_id' => 'I-NEW']);
        $this->assertFalse($other->properties()->where('key', 'has_paypal_subscription')->exists(), 'only an id that ended here');
    }

    /** Code review of #605: staff cancel it; its last payment, already on its way, still pays the invoice. */
    public function test_a_sale_after_staff_cancelled_it_is_still_recorded(): void
    {
        $service = $this->subscribed();
        Http::fake([self::API . '/v1/billing/subscriptions/I-SUB1/cancel' => Http::response(null, 204)]);
        $this->assertNull(CancelSubscriptionAction::cancel($service));

        (new PayPal([]))->recordSubscriptionSale(['id' => 'SALE-LAST', 'billing_agreement_id' => 'I-SUB1',
            'amount' => ['total' => '10.00', 'currency' => 'USD']]);

        $this->assertSame(Invoice::STATUS_PAID, $this->invoiceOf($service)->status);
    }

    /** Code review of #605: a service moved to another customer leaves the first one's ended subscription behind. */
    public function test_a_service_moved_to_another_customer_drops_the_ended_id(): void
    {
        $service = $this->subscribed();
        $this->event('BILLING.SUBSCRIPTION.CANCELLED')->assertOk();

        app(MoveToClient::class)->service($service->fresh(), User::factory()->create()->client(), Staff::factory()->create());

        $this->assertFalse($service->properties()->where('key', SubscriptionEnded::keptKey('I-SUB1'))->exists());
        $this->assertSame(0, SubscriptionEnded::services('I-SUB1')->count());
    }
}
