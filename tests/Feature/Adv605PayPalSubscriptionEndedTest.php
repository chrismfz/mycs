<?php

namespace Tests\Feature;

use App\Admin\Actions\CancelSubscriptionAction;
use App\Models\BillingAgreement;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
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
 * Adversarial review of #605 (a PayPal subscription that ended at PayPal no longer holds its services' invoices).
 * Tests whose docblock starts with ADV-605-n fail on the PR head: each is a defect found. The others are attacks the
 * code withstood, kept so that it keeps withstanding them.
 */
class Adv605PayPalSubscriptionEndedTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api-m.sandbox.paypal.com';

    private const IPN = '/modules/gateways/callback/paypal.php';

    /** The status PayPal gives the subscription when asked; an int is an HTTP error. */
    private string|int $status = 'CANCELLED';

    /** A whole body PayPal answers the status GET with instead (200). */
    /** @var array<string, mixed>|null */
    private ?array $body = null;

    /** What PayPal answers a legacy IPN's postback. */
    private string $ipnAnswer = 'VERIFIED';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->storeSettings(['system_email_address' => 'staff@example.test', 'mail_disable' => false, 'autocharge_days_before' => 0]);
        config(['settings.credits_enabled' => true]);
        $gateway = Gateway::query()->create(['extension' => 'PayPal', 'name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);
        foreach (['client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'WH-1', 'test_mode' => '1'] as $key => $value) {
            $gateway->settings()->create(['key' => $key, 'value' => $value]);
        }
        Http::preventStrayRequests();
        Http::fake([
            self::API . '/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            self::API . '/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            self::API . '/v1/billing/subscriptions/*' => fn (Request $request) => match (true) {
                $this->body !== null => Http::response($this->body),
                is_int($this->status) => Http::response(['name' => 'ERROR', 'message' => 'error'], $this->status),
                default => Http::response(['id' => basename($request->url()), 'status' => $this->status]),
            },
            'https://ipnpb.paypal.com/*' => fn () => Http::response($this->ipnAnswer),
            'https://ipnpb.sandbox.paypal.com/*' => fn () => Http::response($this->ipnAnswer),
        ]);
        $this->user = User::factory()->create();
        BillingAgreement::query()->create(['client_id' => $this->user->client()->id, 'gateway_id' => $gateway->id,
            'name' => 'PayPal', 'external_reference' => 'TOKEN-1', 'type' => 'paypal']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An active service the subscription pays, with its open renewal invoice due today. */
    private function subscribed(string $subscription = 'I-SUB1', ?User $owner = null): Service
    {
        $owner ??= $this->user;
        $service = Service::factory()->create(['client_id' => $owner->client()->id, 'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD', 'price' => 10, 'expires_at' => '2026-11-10', 'subscription_id' => $subscription]);
        $service->properties()->create(['key' => 'has_paypal_subscription', 'name' => 'PayPal', 'value' => '1']);
        $invoice = Invoice::factory()->create(['client_id' => $owner->client()->id, 'status' => Invoice::STATUS_UNPAID,
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
        return $this->postJson('/extensions/paypal/webhook', ['id' => 'WH-EV-' . $type . '-' . $id, 'event_type' => $type,
            'resource' => ['id' => $id, 'status' => 'CANCELLED']]);
    }

    /** @param  array<string, string>  $fields */
    private function ipn(array $fields): TestResponse
    {
        $this->storeSettings(['paypal_legacy_receivers' => 'billing@example.test']);

        return $this->call('POST', self::IPN, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            http_build_query($fields + ['receiver_email' => 'billing@example.test']));
    }

    private function sale(string $saleId, string $subscription = 'I-SUB1', string $amount = '10.00'): void
    {
        (new PayPal([]))->recordSubscriptionSale(['id' => $saleId, 'billing_agreement_id' => $subscription,
            'amount' => ['total' => $amount, 'currency' => 'USD']]);
    }

    private function asked(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), '/v1/billing/subscriptions/')
            && $request->method() === 'GET')->count();
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
    }

    // ---------------------------------------------------------------- findings

    /**
     * ADV-605-1. The kept `ended_subscription_id` is not the customer's: the service carries it wherever it goes. Once the
     * subscription ended, the service has no subscription id, so MoveToClient no longer refuses to move it; the late sale
     * of the FIRST customer's PayPal subscription then pays the NEW customer's invoice (or becomes their credit).
     */
    public function test_adv_605_1_a_late_sale_never_pays_the_invoice_of_the_customer_the_service_was_moved_to(): void
    {
        $service = $this->subscribed();
        $this->event('BILLING.SUBSCRIPTION.CANCELLED')->assertOk();
        $buyer = User::factory()->create();
        (new MoveToClient)->service($service, $buyer->client(), $this->staff());
        $this->assertSame($buyer->client()->id, (int) $this->invoiceOf($service->fresh())->client_id, 'precondition: its open invoice moved with it');

        $this->sale('SALE-LATE');

        $this->assertSame(0, InvoiceTransaction::query()->whereHas('invoice', fn ($invoice) => $invoice->where('client_id', $buyer->client()->id))->count(),
            'the first customer\'s subscription money is recorded on the new customer\'s invoice');
        $this->assertSame(0.0, (float) $buyer->client()->credits()->sum('amount'), 'nor given to the new customer as credit');
    }

    /** @return array<string, array{int}> */
    public static function passingErrors(): array
    {
        return ['rate limited' => [429], 'not authorised' => [403]];
    }

    /**
     * ADV-605-2. Every PayPal error below 500 on the status GET is read as «PayPal does not know it»: the webhook is
     * answered 200, so PayPal never sends it again, and only a line in the payments log says so. A 429 (rate limit) or a
     * 403 (the app's permission) is not «unknown»: the subscription stays on the services forever and nothing pays their
     * invoices again, which is the very defect the PR fixes (L3 of #602).
     */
    #[DataProvider('passingErrors')]
    public function test_adv_605_2_a_status_check_that_fails_for_a_passing_reason_is_retried_or_told_to_staff(int $error): void
    {
        $service = $this->subscribed();
        $this->status = $error;
        $mails = EmailLog::query()->count();

        $response = $this->event('BILLING.SUBSCRIPTION.CANCELLED');

        $this->assertSame('I-SUB1', $service->fresh()->subscription_id);
        $this->assertTrue($response->status() >= 500 || EmailLog::query()->count() > $mails,
            "PayPal answered {$error}: the cancellation is dropped for good (HTTP {$response->status()}, no mail to staff)");
    }

    /**
     * ADV-605-3. The legacy IPN clears without asking PayPal, also on a suspension, which is not an end (a suspended
     * profile or subscription is activated again). An IPN is sent again for days (and this controller itself answers 503
     * while the receivers are unset), so a stale suspension clears a subscription PayPal says bills now: the webhook path
     * refuses exactly that, and the vault then charges the invoices the subscription also pays.
     */
    public function test_adv_605_3_a_legacy_suspension_of_a_subscription_paypal_says_is_active_clears_nothing(): void
    {
        $service = $this->subscribed();
        $this->status = 'ACTIVE';

        $this->ipn(['txn_type' => 'recurring_payment_suspended', 'recurring_payment_id' => 'I-SUB1'])->assertOk();

        $this->assertSame('I-SUB1', $service->fresh()->subscription_id, 'cleared on a stale suspension while PayPal says ACTIVE');
    }

    /**
     * ADV-605-4. The legacy IPN clears the id but passes no markers: the service keeps `has_paypal_subscription` with no
     * subscription. ExtensionHelper::autoChargeNote then tells staff after a chargeback that it still charges by itself,
     * and a price change makes the Updated listener PATCH `/v1/billing/subscriptions/` with no id.
     */
    public function test_adv_605_4_a_legacy_cancellation_takes_the_paypal_marker_with_the_id(): void
    {
        $service = $this->subscribed('I-LEGACY1');

        $this->ipn(['txn_type' => 'subscr_cancel', 'subscr_id' => 'I-LEGACY1'])->assertOk();

        $this->assertNull($service->fresh()->subscription_id, 'precondition: cleared');
        $this->assertFalse($service->properties()->where('key', 'has_paypal_subscription')->exists(),
            'the marker of a subscription that ended stays');
    }

    /**
     * ADV-605-5. One `ended_subscription_id` per service: the second subscription that ends on a service overwrites the
     * first. A late sale of the first then finds no service and is mailed to staff as «a payment this site did not make»,
     * although it is this customer's money for this service.
     */
    public function test_adv_605_5_a_late_sale_of_the_first_of_two_ended_subscriptions_is_still_recorded(): void
    {
        $service = $this->subscribed('I-SUB1');
        $this->event('BILLING.SUBSCRIPTION.CANCELLED', 'I-SUB1')->assertOk();
        // Staff set the customer's new subscription; it ends too, before the first one's last sale comes.
        $service->fresh()->update(['subscription_id' => 'I-SUB2']);
        $this->event('BILLING.SUBSCRIPTION.CANCELLED', 'I-SUB2')->assertOk();
        $this->assertNull($service->fresh()->subscription_id, 'precondition: both ended');

        $this->sale('SALE-LATE-1', 'I-SUB1');

        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'SALE-LATE-1')->count(),
            'the late sale of the first subscription is lost to «not made here»');
    }

    /**
     * ADV-605-6. Only an end at PayPal keeps the id for a late sale. Staff «Cancel Subscription» (and Stripe) clear it
     * without keeping it, so the sale PayPal made just before the cancel, whose webhook comes after, is mailed as «not
     * made here» and recorded nowhere.
     */
    public function test_adv_605_6_a_sale_that_comes_after_staff_cancelled_the_subscription_is_still_recorded(): void
    {
        $service = $this->subscribed();
        $this->assertNull(CancelSubscriptionAction::cancel($service->fresh()), 'precondition: cancelled by staff');
        $this->assertNull($service->fresh()->subscription_id);

        $this->sale('SALE-INFLIGHT');

        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'SALE-INFLIGHT')->count(),
            'the in-flight sale of a subscription staff cancelled is lost to «not made here»');
    }

    // ---------------------------------------------------------------- held

    /** Suspended, active again, set back by staff, then the old suspension sent again: PayPal says ACTIVE, nothing cleared. */
    public function test_an_old_suspension_after_staff_set_the_subscription_back_clears_nothing(): void
    {
        $service = $this->subscribed();
        $this->status = 'SUSPENDED';
        $this->event('BILLING.SUBSCRIPTION.SUSPENDED')->assertOk();
        $this->status = 'ACTIVE';
        $this->event('BILLING.SUBSCRIPTION.ACTIVATED')->assertOk();
        $service->fresh()->update(['subscription_id' => 'I-SUB1']);

        $this->postJson('/extensions/paypal/webhook', ['id' => 'WH-EV-OLD', 'event_type' => 'BILLING.SUBSCRIPTION.SUSPENDED',
            'resource' => ['id' => 'I-SUB1', 'status' => 'SUSPENDED']])->assertOk();

        $this->assertSame('I-SUB1', $service->fresh()->subscription_id);
    }

    /** A cancellation after an activation (out of order): it is only cleared because PayPal says CANCELLED now. */
    public function test_a_cancellation_after_an_activation_clears_only_when_paypal_confirms(): void
    {
        $service = $this->subscribed();
        $this->status = 'ACTIVE';
        $this->event('BILLING.SUBSCRIPTION.ACTIVATED')->assertOk();
        $this->event('BILLING.SUBSCRIPTION.CANCELLED')->assertOk();
        $this->assertSame('I-SUB1', $service->fresh()->subscription_id);

        $this->status = 'CANCELLED';
        $this->postJson('/extensions/paypal/webhook', ['id' => 'WH-EV-AGAIN', 'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED',
            'resource' => ['id' => 'I-SUB1']])->assertOk();

        $this->assertNull($service->fresh()->subscription_id);
    }

    /** An id differing only in case (MariaDB's collation would match it) is never taken, and PayPal is not asked. */
    public function test_an_id_differing_in_case_or_padded_changes_nothing(): void
    {
        $service = $this->subscribed();

        $this->event('BILLING.SUBSCRIPTION.CANCELLED', 'i-sub1')->assertOk();
        $this->event('BILLING.SUBSCRIPTION.CANCELLED', 'I-sub1')->assertOk();
        // The legacy IPN passes its id as it came (trimmed): MariaDB's ci / PAD SPACE match is checked again in PHP.
        $this->assertSame([], SubscriptionEnded::clear('PayPal', 'i-sub1', 'test'));
        $this->assertSame([], SubscriptionEnded::clear('PayPal', 'I-SUB1 ', 'test'));

        $this->assertSame('I-SUB1', $service->fresh()->subscription_id);
        $this->assertSame(0, $this->asked());
    }

    /** A late sale for an invoice paid another way meanwhile: recorded once, as the same customer's credit. */
    public function test_a_late_sale_after_the_invoice_was_paid_another_way_becomes_the_customers_credit(): void
    {
        $service = $this->subscribed();
        $this->event('BILLING.SUBSCRIPTION.CANCELLED')->assertOk();
        Invoice::query()->whereKey($this->invoiceOf($service)->id)->update(['status' => Invoice::STATUS_PAID]);

        $this->sale('SALE-LATE');
        $this->sale('SALE-LATE'); // sent again

        $this->assertSame(1, InvoiceTransaction::query()->where('transaction_id', 'SALE-LATE')->count());
        $this->assertSame(10.0, (float) $this->user->client()->credits()->where('currency_code', 'USD')->sum('amount'));
    }

    /** One id on two customers' services (a staff mistake): the late sale never pays the other customer's invoice. */
    public function test_a_late_sale_of_an_id_on_two_customers_never_pays_the_other_customer(): void
    {
        $mine = $this->subscribed();
        $other = User::factory()->create();
        $theirs = $this->subscribed('I-SUB1', $other);
        $this->event('BILLING.SUBSCRIPTION.CANCELLED')->assertOk();
        $this->assertNull($theirs->fresh()->subscription_id, 'precondition: cleared from both');

        $this->sale('SALE-LATE', 'I-SUB1', '20.00');

        $this->assertSame(Invoice::STATUS_PAID, $this->invoiceOf($mine)->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $this->invoiceOf($theirs)->status);
        $this->assertSame(0.0, (float) $other->client()->credits()->sum('amount'));
    }

    /** The sandbox's IPN (VERIFIED to anyone) never clears a service in production. */
    public function test_a_sandbox_legacy_cancellation_in_production_clears_nothing(): void
    {
        $service = $this->subscribed('I-LEGACY1');
        $this->app['env'] = 'production';

        $this->ipn(['txn_type' => 'subscr_cancel', 'subscr_id' => 'I-LEGACY1', 'test_ipn' => '1'])->assertStatus(400);

        $this->assertSame('I-LEGACY1', $service->fresh()->subscription_id);
    }

    /** A forged legacy IPN (PayPal answers INVALID) clears nothing. */
    public function test_a_forged_legacy_cancellation_clears_nothing(): void
    {
        $this->ipnAnswer = 'INVALID';
        $service = $this->subscribed('I-LEGACY1');

        $this->ipn(['txn_type' => 'subscr_eot', 'subscr_id' => 'I-LEGACY1'])->assertStatus(400);

        $this->assertSame('I-LEGACY1', $service->fresh()->subscription_id);
    }

    /** A legacy notice that is no ending (a modification, a signup) with the id clears nothing. */
    public function test_a_legacy_notice_that_is_no_ending_clears_nothing(): void
    {
        $service = $this->subscribed('I-LEGACY1');

        $this->ipn(['txn_type' => 'subscr_modify', 'subscr_id' => 'I-LEGACY1'])->assertOk();
        $this->ipn(['txn_type' => 'subscr_signup', 'subscr_id' => 'I-LEGACY1'])->assertOk();
        $this->ipn(['txn_type' => 'subscr_failed', 'subscr_id' => 'I-LEGACY1'])->assertOk();

        $this->assertSame('I-LEGACY1', $service->fresh()->subscription_id);
    }

    /** A status GET answered 200 with no usable status (an empty body, a status of another type) clears nothing. */
    public function test_an_odd_status_answer_clears_nothing(): void
    {
        $service = $this->subscribed();
        $this->body = ['status' => ['CANCELLED']];

        $this->event('BILLING.SUBSCRIPTION.CANCELLED')->assertOk();

        $this->assertSame('I-SUB1', $service->fresh()->subscription_id);
    }
}
