<?php

namespace Tests\Feature;

use App\Events\Service\Updated;
use App\Events\ServiceCancellation\Created as CancellationCreated;
use App\Listeners\CancellationCreatedListener;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use MyCS\Extensions\Others\DiscordNotifications\DiscordNotifications;
use RuntimeException;
use Tests\TestCase;

/**
 * Adversarial review of PR #45 (Service\Updated carries its changes after commit).
 *
 * Tests named test_holds_* document attacked edge cases that hold. Every other test is a finding that FAILED on the PR
 * head (d801198) and is fixed in the PR. ADV-4 (a failed PayPal cancel deleted the marker) is fixed and tested in the
 * PayPal API errors PR (KR-2), where request() throws.
 */
class SubscriptionSyncAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function service(string $gateway, array $attributes = []): Service
    {
        $product = $this->createProduct();

        $service = Service::factory()->create(array_merge([
            'client_id' => User::factory()->create()->client()->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now()->addMonth(),
            'currency_code' => 'USD',
            'price' => 10.00,
            'quantity' => 1,
            'subscription_id' => 'sub_' . ($attributes['_sub'] ?? '123'),
        ], array_diff_key($attributes, ['_sub' => true])));

        $service->properties()->create([
            'key' => $gateway === 'stripe' ? 'has_stripe_subscription' : 'has_paypal_subscription',
            'name' => 'Subscription',
            'value' => '1',
        ]);

        return $service->fresh();
    }

    /** Stripe response; $basil = the shape of API version 2025-03-31.basil+ (current_period_end on the item). */
    private function fakeStripe(bool $basil = false): void
    {
        $periodEnd = now()->addMonth()->timestamp;
        $item = ['id' => 'si_1', 'price' => ['product' => 'prod_1']];
        $body = ['id' => 'sub_123', 'schedule' => null, 'items' => ['data' => [$item]]];
        if ($basil) {
            $body['items']['data'][0]['current_period_end'] = $periodEnd;
        } else {
            $body['current_period_end'] = $periodEnd;
        }

        Http::fake(['https://api.stripe.com/*' => Http::response($body)]);
    }

    private function bootStripe(bool $basil = false): void
    {
        $this->fakeStripe($basil);
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
    }

    private function bootPayPal(int $cancelStatus = 204): void
    {
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/*/cancel' => Http::response(
                $cancelStatus === 204 ? [] : ['name' => 'INTERNAL_SERVER_ERROR'], $cancelStatus),
            'https://api-m.sandbox.paypal.com/*' => Http::response([], 204),
        ]);

        (new PayPal(['test_mode' => true, 'client_id' => 'id', 'client_secret' => 'secret']))->boot();
    }

    private function stripeDeletes(string $sub = 'sub_123'): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === 'https://api.stripe.com/v1/subscriptions/' . $sub)->count();
    }

    private function paypalCancels(): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/v1/billing/subscriptions/sub_123/cancel'))->count();
    }

    private function priceUpdate(): ?Request
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.stripe.com/v1/subscription_items/si_1')->first()[0] ?? null;
    }

    // ---------------------------------------------------------------- holds

    public function test_holds_rolled_back_cancellation_calls_no_gateway(): void
    {
        $service = $this->service('stripe');
        $this->bootStripe();

        try {
            DB::transaction(function () use ($service) {
                $service->update(['status' => Service::STATUS_CANCELLED]);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        Http::assertNothingSent();
    }

    public function test_holds_savepoint_rollback_inside_committed_transaction_calls_no_gateway(): void
    {
        $service = $this->service('stripe');
        $this->bootStripe();

        DB::transaction(function () use ($service) {
            try {
                DB::transaction(function () use ($service) {
                    DB::transaction(fn () => $service->update(['status' => Service::STATUS_CANCELLED]));
                    throw new RuntimeException('rollback to savepoint');
                });
            } catch (RuntimeException) {
            }
            Service::whereKey($service->id)->first()->update(['suspend_hold_until' => now()->addDay()]);
        });

        $this->assertSame(0, $this->stripeDeletes());
    }

    public function test_holds_cancellation_outside_any_transaction_cancels_once(): void
    {
        $service = $this->service('stripe');
        $this->bootStripe();

        $service->update(['status' => Service::STATUS_CANCELLED]);

        $this->assertSame(1, $this->stripeDeletes());
        $this->assertNull($service->fresh()->subscription_id);
    }

    public function test_holds_staff_cancellation_via_apply_staff_changes_cancels_once(): void
    {
        $service = $this->service('stripe');
        $this->bootStripe();

        DB::transaction(fn () => $service->applyStaffChanges(['status' => Service::STATUS_CANCELLED]));

        // The cancel listener's own update(['subscription_id' => null]) re-dispatches Updated: no second DELETE.
        $this->assertSame(1, $this->stripeDeletes());
    }

    public function test_holds_immediate_cancellation_deletes_the_stripe_subscription_once(): void
    {
        $service = $this->service('stripe');
        $this->bootStripe();
        Queue::fake();

        $cancellation = ServiceCancellation::create(['service_id' => $service->id, 'reason' => 'x', 'type' => 'immediate']);
        (new CancellationCreatedListener)->handle(new CancellationCreated($cancellation->fresh()));

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(1, $this->stripeDeletes());
    }

    public function test_holds_cron_terminating_two_services_cancels_both(): void
    {
        $a = $this->service('stripe', ['_sub' => 'a']);
        $b = $this->service('stripe', ['_sub' => 'b']);
        $this->bootStripe();
        foreach ([$a, $b] as $s) {
            $s->forceFill(['status' => 'suspended', 'dunning_suspended_at' => now()->subDays(20), 'expires_at' => now()->subDays(30)])->saveQuietly();
        }
        config(['settings.cronjob_order_terminate' => 14]);
        Queue::fake();

        $this->artisan('app:cron-job');

        $this->assertSame(1, $this->stripeDeletes('sub_a'));
        $this->assertSame(1, $this->stripeDeletes('sub_b'));
    }

    public function test_holds_event_survives_serialization_with_its_changes(): void
    {
        $service = $this->service('stripe');
        $event = new Updated($service, ['status' => 'cancelled'], ['status' => 'active']);

        $copy = unserialize(serialize($event));

        $this->assertSame(['status' => 'cancelled'], $copy->changes);
        $this->assertSame(['status' => 'active'], $copy->original);
        $this->assertTrue($copy->changed('status'));
    }

    public function test_holds_discord_shows_old_and_new_value_after_commit(): void
    {
        $service = $this->service('stripe');
        Http::fake(['https://discord.test/*' => Http::response([])]);
        (new DiscordNotifications(['webhook_url' => 'https://discord.test/hook', 'events' => ['Service Updated'], 'ping_type' => 'none']))->boot();

        DB::transaction(function () use ($service) {
            $service->update(['status' => Service::STATUS_CANCELLED]);
            $service->update(['price' => 12.00]);
        });

        $values = Http::recorded()->map(fn ($pair) => collect($pair[0]['embeds'][0]['fields'])->pluck('value')->all())->flatten()->all();
        $this->assertContains('active -> cancelled', $values);
        // The new value is the raw attribute as set (12.0 -> "12"): cosmetic, and the same as before the PR.
        // The old value is raw too: "10.00" on MariaDB, "10" on SQLite (no decimal type).
        $this->assertNotEmpty(preg_grep('/^10(\.00)? -> 12$/', $values), implode(', ', $values));
    }

    public function test_holds_stripe_expires_at_sync_with_pre_basil_response_shape(): void
    {
        $service = $this->service('stripe');
        $this->bootStripe(basil: false);

        DB::transaction(fn () => $service->update(['expires_at' => now()->addMonths(3)]));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.stripe.com/v1/subscriptions/sub_123' && isset($r['trial_end']));
    }

    // ---------------------------------------------------------------- findings (failed on d801198, fixed)

    /**
     * ADV-1: the subscription is created at price * quantity (Stripe::setupSubscription, quantity 1), but a price change
     * pushes price * 100, so a service with quantity 3 is billed one unit from then on. This path was dead inside
     * transactions before the PR; the PR brings it back for staff edits, upgrades and the API.
     */
    public function test_stripe_price_update_keeps_the_quantity(): void
    {
        $service = $this->service('stripe', ['quantity' => 3]);
        $this->bootStripe();

        DB::transaction(fn () => $service->update(['price' => 12.00]));

        $request = $this->priceUpdate();
        $this->assertNotNull($request);
        $this->assertEquals(3600, $request['price_data']['unit_amount'], 'quantity 3 x 12.00 must be billed 36.00');
    }

    /**
     * Holds (in practice): unit_amount = price * 100 is a float (19.99 -> 1998.9999999999998), but the form encoding
     * prints it with PHP's precision (14 digits), so Stripe receives "1999". Still a float in a money path
     * (CLAUDE.md): an integer conversion (bcmul / round) would not depend on an ini setting.
     */
    public function test_holds_stripe_price_update_sends_an_integer_amount_in_cents(): void
    {
        $service = $this->service('stripe');
        $this->bootStripe();

        DB::transaction(fn () => $service->update(['price' => 19.99]));

        $request = $this->priceUpdate();
        $this->assertNotNull($request);
        $this->assertSame('1999', (string) $request['price_data']['unit_amount']);
    }

    /**
     * ADV-2: Stripe API 2025-03-31.basil (this gateway pins 2025-07-30.basil) moved current_period_end from the
     * Subscription to its items. Stripe::updateSubscription reads $subscription->current_period_end: an
     * undefined property (ErrorException), swallowed by the listener, so an expires_at change (renewal, staff
     * moving the due date) is never synced, which is one of the three cases the PR says it fixes.
     */
    public function test_stripe_expires_at_sync_with_basil_response_shape(): void
    {
        $service = $this->service('stripe');
        $this->bootStripe(basil: true);

        DB::transaction(fn () => $service->update(['expires_at' => now()->addMonths(3)]));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.stripe.com/v1/subscriptions/sub_123' && isset($r['trial_end']));
    }

    /**
     * ADV-3: the after-commit callbacks of one transaction run in a row; one that throws drops the rest. The cron
     * terminates every overdue service in ONE transaction, so a throwing Service\Updated listener on the first
     * (here Discord with settings.debug on and Discord unreachable; any listener that throws does the same)
     * leaves the second's Stripe subscription billing. Its status is already cancelled, so no later cron run
     * picks it up again.
     */
    public function test_one_throwing_listener_does_not_skip_the_other_services_cancellation(): void
    {
        $a = $this->service('stripe', ['_sub' => 'a']);
        $b = $this->service('stripe', ['_sub' => 'b']);
        $this->fakeStripe();
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
        Http::fake(['https://discord.test/*' => Http::failedConnection()]);
        config(['settings.debug' => true]);
        (new DiscordNotifications(['webhook_url' => 'https://discord.test/hook', 'events' => ['Service Updated'], 'ping_type' => 'none']))->boot();

        foreach ([$a, $b] as $s) {
            $s->forceFill(['status' => 'suspended', 'dunning_suspended_at' => now()->subDays(20), 'expires_at' => now()->subDays(30)])->saveQuietly();
        }
        config(['settings.cronjob_order_terminate' => 14]);
        Queue::fake();

        try {
            $this->artisan('app:cron-job');
        } catch (\Throwable) {
            // the cron reports its failures; what matters is what reached Stripe
        }

        $this->assertSame(Service::STATUS_TERMINATED, $b->fresh()->status);
        $this->assertSame(1, $this->stripeDeletes('sub_b'), 'service b is cancelled but its Stripe subscription keeps billing');
    }

    /**
     * ADV-5: cancelled -> active -> cancelled on the same model in one transaction queues two Updated events whose
     * changes both say status=cancelled. Stripe skips the second (subscription_id cleared); PayPal does not: its
     * listener reads the cached properties relation, which still holds the deleted marker, and cancels twice.
     */
    public function test_paypal_is_cancelled_once_per_commit(): void
    {
        $service = $this->service('paypal');
        $this->bootPayPal();

        DB::transaction(function () use ($service) {
            $service->update(['status' => Service::STATUS_CANCELLED]);
            $service->update(['status' => Service::STATUS_ACTIVE]);
            $service->update(['status' => Service::STATUS_CANCELLED]);
        });

        $this->assertSame(1, $this->paypalCancels());
    }

    /**
     * ADV-6: "still cancelled at commit" is checked on the in-memory model of the save that cancelled, not on the
     * row. Reactivated in the same transaction through another instance (applyStaffChanges always saves a fresh
     * locked copy), the service is active at commit, but its Stripe subscription is deleted.
     */
    public function test_reactivated_through_another_instance_keeps_the_subscription(): void
    {
        $service = $this->service('stripe');
        $this->bootStripe();

        DB::transaction(function () use ($service) {
            $service->applyStaffChanges(['status' => Service::STATUS_CANCELLED]);
            $service->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);
        });

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertSame(0, $this->stripeDeletes(), 'the service is active, its subscription must stay');
    }
}
