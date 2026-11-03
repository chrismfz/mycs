<?php

namespace Tests\Feature;

use App\Models\BillingAgreement;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\SubscriptionEnded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Adversarial review of PR #607 (Stripe: a subscription that ended at Stripe no longer holds its services' invoices).
 * ADV-607-n tests fail on the PR head (real defects); the «held» ones are attacks that did not break it.
 */
class Adv607StripeSubscriptionEndedTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    /** What Stripe answers to GET /subscriptions/{id}: a status, or [HTTP code, error code]. */
    private string|array $status = 'canceled';

    /** What GET /webhook_endpoints answers (the endpoints already on the account). */
    private array $endpoints = [];

    private User $user;

    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->storeSettings(['system_email_address' => 'staff@example.test', 'mail_disable' => false, 'autocharge_days_before' => 0]);
        $this->gateway = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $this->gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test']);
        $this->gateway->settings()->create(['key' => 'stripe_webhook_secret', 'value' => self::SECRET]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/*' => fn (Request $request) => match (true) {
                $request->method() === 'DELETE' => Http::response(['id' => basename($request->url()), 'status' => 'canceled']),
                is_array($this->status) => Http::response(['error' => ['code' => $this->status[1]]], $this->status[0]),
                default => Http::response(['id' => basename($request->url()), 'status' => $this->status]),
            },
            'https://api.stripe.com/v1/invoice_payments*' => Http::response(['data' => [['payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_late']]]]),
            'https://api.stripe.com/v1/webhook_endpoints*' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response(['data' => $this->endpoints]) : Http::response(['id' => 'we_new', 'secret' => 'whsec_new']),
        ]);
        $this->user = User::factory()->create();
        BillingAgreement::query()->create(['client_id' => $this->user->client()->id, 'gateway_id' => $this->gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function subscribed(string $subscription = 'sub_1', ?User $user = null): Service
    {
        $client = ($user ?? $this->user)->client();
        $service = Service::factory()->create(['client_id' => $client->id, 'status' => Service::STATUS_ACTIVE,
            'currency_code' => 'USD', 'price' => 10, 'expires_at' => '2026-11-10', 'subscription_id' => $subscription]);
        $service->properties()->create(['key' => 'has_stripe_subscription', 'name' => 'Stripe', 'value' => '1']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return $service->fresh();
    }

    /** @param  array<string, mixed>  $object */
    private function event(string $type, array $object, ?string $id = null): TestResponse
    {
        $time = time();
        $payload = (string) json_encode(['id' => $id ?? 'evt_' . $type, 'type' => $type, 'data' => ['object' => $object]]);

        return $this->call('POST', '/extensions/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $payload, self::SECRET)], $payload);
    }

    private function asked(): int
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), '/v1/subscriptions/'))->count();
    }

    private function cancelledAtStripe(string $id): bool
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/v1/subscriptions/' . $id))->isNotEmpty();
    }

    /** @return array<string, array{string, string}> */
    public static function revivable(): array
    {
        return [
            'unpaid after the retries' => ['customer.subscription.updated', 'unpaid'],
            'paused' => ['customer.subscription.paused', 'paused'],
        ];
    }

    /**
     * ADV-607-1 — `unpaid` and `paused` are not final at Stripe: the subscription still exists (an unpaid one keeps
     * making invoices and turns `active` again as soon as the customer pays its open invoice from Stripe's own e-mail
     * link; a paused one is resumed). Cleared here, the service no longer carries it, so ending the service (terminate,
     * a cancellation request, delete) no longer cancels it at Stripe (ownsSubscription is false), and a move to another
     * customer is no longer refused. Before the PR the id stayed and the termination DELETEd it. Revived later, it bills
     * a terminated (or deleted, or moved) service every cycle.
     */
    #[DataProvider('revivable')]
    public function test_adv_607_1_a_subscription_cleared_as_unpaid_or_paused_is_still_cancelled_at_stripe_when_the_service_ends(string $type, string $status): void
    {
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
        $service = $this->subscribed();
        $this->status = $status;
        $this->event($type, ['id' => 'sub_1', 'object' => 'subscription', 'status' => $status])->assertOk();
        $this->assertNull($service->fresh()->subscription_id, 'precondition: cleared by the PR');

        $service->fresh()->markTerminated();

        $this->assertTrue($this->cancelledAtStripe('sub_1'),
            'the service was terminated, but its ' . $status . ' Stripe subscription was never cancelled: it can bill again');
    }

    /**
     * ADV-607-2 — A subscription's payment (`invoice.payment_succeeded`) that no service carries or kept is dropped in
     * silence: 200, nothing recorded, staff not told. PayPal's side reports it (GatewayReference::foreign, ADV-605) and
     * docs/payments.md says Stripe does «the same». Reachable through ADV-607-1: unpaid → cleared → the service is
     * deleted or moved (forget) → the customer pays the open Stripe invoice → the money is taken and nobody knows.
     */
    public function test_adv_607_2_a_payment_of_a_subscription_no_service_knows_is_told_to_staff(): void
    {
        $service = $this->subscribed();
        $this->status = 'unpaid';
        $this->event('customer.subscription.updated', ['id' => 'sub_1', 'object' => 'subscription', 'status' => 'unpaid'])->assertOk();
        SubscriptionEnded::forget($service->fresh()); // as MoveToClient does, now allowed: no subscription id on it

        $this->event('invoice.payment_succeeded', ['id' => 'in_9', 'object' => 'invoice', 'amount_paid' => 1000, 'currency' => 'usd',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']]])->assertOk();

        $this->assertSame(0, InvoiceTransaction::query()->count(), 'never recorded on a service it no longer pays');
        $this->assertSame(1, EmailLog::query()->where('subject', 'Gateway event for a payment not made here')->count(),
            'a Stripe subscription payment with no service was dropped without telling staff');
    }

    /**
     * ADV-607-3 — The endpoint the extension already made (every live install) never gets the four
     * `customer.subscription.*` events: Save returns early (its fingerprint still matches) and the gateway page says
     * «Saved ... Stripe sends its events» as if all were fine. So `customer.subscription.deleted` (the PR's main case)
     * never arrives in production unless someone edits the dashboard by hand, and nothing on the page says so.
     */
    public function test_adv_607_3_saving_the_gateway_brings_an_existing_endpoint_the_subscription_events(): void
    {
        $url = route('extensions.gateways.stripe.webhook');
        $this->gateway->settings()->create(['key' => 'stripe_webhook_for',
            'value' => substr(hash('sha256', 'sk_test|' . $url . '|' . self::SECRET), 0, 16)]);
        $this->gateway->settings()->create(['key' => 'stripe_webhook_id', 'value' => 'we_old']);
        $this->endpoints = [['id' => 'we_old', 'url' => $url, 'enabled_events' => ['payment_intent.succeeded', 'subscription_schedule.canceled', 'invoice.payment_succeeded']]];
        $this->assertTrue((new Stripe(['stripe_secret_key' => 'sk_test']))->webhookStatus($this->gateway)['ok'], 'precondition: the old endpoint is shown as fine');

        (new Stripe(['stripe_secret_key' => 'sk_test']))->updated($this->gateway);

        $this->assertTrue(Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/webhook_endpoints')
            && in_array('customer.subscription.deleted', (array) ($request->data()['enabled_events'] ?? []), true))->isNotEmpty(),
            'Save left the existing endpoint without customer.subscription.deleted');
    }

    /** Held: a cancelled schedule that never started (no subscription) changes nothing and asks nothing. */
    public function test_held_a_cancelled_schedule_without_subscription_changes_nothing(): void
    {
        $service = $this->subscribed();

        $this->event('subscription_schedule.canceled', ['id' => 'sub_sched_1', 'object' => 'subscription_schedule', 'subscription' => null])->assertOk();
        $this->event('subscription_schedule.canceled', ['id' => 'sub_sched_2', 'object' => 'subscription_schedule'], 'evt_2')->assertOk();

        $this->assertSame('sub_1', $service->fresh()->subscription_id);
        $this->assertSame(0, $this->asked());
    }

    /** @return array<string, array{int, string}> */
    public static function failures(): array
    {
        return [
            '503' => [503, 'api_error'],
            '404 not resource_missing' => [404, 'url_invalid'],
            '401' => [401, 'api_key_expired'],
        ];
    }

    /** Held: any failure of the status GET other than 404 resource_missing keeps the id and answers 500 (retried). */
    #[DataProvider('failures')]
    public function test_held_a_failed_status_check_keeps_the_id_and_is_retried(int $code, string $error): void
    {
        $service = $this->subscribed();
        $this->status = [$code, $error];

        $this->event('customer.subscription.deleted', ['id' => 'sub_1', 'status' => 'canceled'])->assertStatus(500);

        $this->assertSame('sub_1', $service->fresh()->subscription_id);
        $this->assertFalse($service->fresh()->properties()->where('key', SubscriptionEnded::keptKey('sub_1'))->exists());
    }

    /** Held: an old «unpaid» event sent again after the subscription was paid (and set back by staff) never clears it. */
    public function test_held_a_late_unpaid_event_after_it_bills_again_keeps_the_id(): void
    {
        $service = $this->subscribed();
        $this->status = 'active';

        $this->event('customer.subscription.updated', ['id' => 'sub_1', 'status' => 'unpaid'], 'evt_old')->assertOk();
        $this->event('customer.subscription.updated', ['id' => 'sub_1', 'status' => 'unpaid'], 'evt_old')->assertOk();

        $this->assertSame('sub_1', $service->fresh()->subscription_id);
    }

    /** Held: staff cancelled it (DELETE): its own late `updated`/`deleted` events never mail «bills again». */
    public function test_held_late_events_of_a_subscription_staff_cancelled_tell_nobody(): void
    {
        $service = $this->subscribed();
        $this->assertTrue((new Stripe(['stripe_secret_key' => 'sk_test']))->cancelSubscription($service));
        $this->status = 'canceled';

        $this->event('customer.subscription.updated', ['id' => 'sub_1', 'status' => 'active'], 'evt_a')->assertOk();
        $this->event('customer.subscription.deleted', ['id' => 'sub_1', 'status' => 'canceled'], 'evt_b')->assertOk();

        $this->assertNull($service->fresh()->subscription_id);
        $this->assertSame(0, EmailLog::query()->where('subject', 'Stripe subscription active again')->count());
    }

    /** Held: one id (staff-entered) on two customers' services: both cleared, each keeps it, no other service touched. */
    public function test_held_one_id_on_two_services_clears_both_and_nothing_else(): void
    {
        $first = $this->subscribed();
        $second = $this->subscribed('sub_1', User::factory()->create());
        $other = $this->subscribed('sub_2');

        $this->event('customer.subscription.deleted', ['id' => 'sub_1', 'status' => 'canceled'])->assertOk();

        $this->assertNull($first->fresh()->subscription_id);
        $this->assertNull($second->fresh()->subscription_id);
        $this->assertSame('sub_2', $other->fresh()->subscription_id);
        $this->assertSame(1, $this->asked());
    }

    /** Held: an id that is a prefix of another's, or differs in case (SQLite: exact), never clears the other. */
    public function test_held_a_similar_id_never_clears_another(): void
    {
        $service = $this->subscribed('sub_ABC1');

        $this->event('customer.subscription.deleted', ['id' => 'sub_ABC', 'status' => 'canceled'])->assertOk();
        $this->event('customer.subscription.deleted', ['id' => 'sub_ABC1 ', 'status' => 'canceled'], 'evt_2')->assertOk();
        $this->event('customer.subscription.deleted', ['id' => 'sub_ABC1%', 'status' => 'canceled'], 'evt_3')->assertOk();

        $this->assertSame('sub_ABC1', $service->fresh()->subscription_id);
        $this->assertSame(0, $this->asked());
    }
}
