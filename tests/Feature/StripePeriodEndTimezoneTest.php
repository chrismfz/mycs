<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * Stripe::updateSubscription compares the subscription's current_period_end with the service's expires_at (a date, in
 * the app's timezone). Carbon 3's createFromTimestamp() is in UTC: on an instance in Europe/Athens a period that ends
 * at local midnight (setupSubscription starts the schedule at now()->startOfDay()) is the day before in UTC, so a
 * renewal that only caught up with Stripe looked like an extension: the schedule was released, the price re-posted and
 * the trial end set again.
 */
class StripePeriodEndTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private string $zone;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Athens');
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'Europe/Athens'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->zone);
        parent::tearDown();
    }

    private function service(string $expires): Service
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'subscription_id' => 'sub_123',
        ]);
        $service->properties()->create(['key' => 'has_stripe_subscription', 'name' => 'Subscription', 'value' => '1']);

        return $service->fresh();
    }

    /** The subscription as Stripe answers it, its period ending at $periodEnd (a time in Athens), with or without a schedule. */
    private function bootStripe(string $periodEnd, ?string $schedule = null): void
    {
        Http::fake(['https://api.stripe.com/*' => Http::response([
            'id' => 'sub_123', 'schedule' => $schedule,
            'items' => ['data' => [['id' => 'si_1', 'price' => ['product' => 'prod_1'], 'current_period_end' => Carbon::parse($periodEnd, 'Europe/Athens')->timestamp]]],
        ])]);
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
    }

    /** @return list<string> what was written at Stripe (method and path) */
    private function writes(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() !== 'GET')
            ->map(fn (array $pair) => $pair[0]->method() . ' ' . parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();
    }

    public function test_a_renewal_that_meets_the_period_end_at_local_midnight_writes_nothing(): void
    {
        $service = $this->service('2026-11-16');
        $this->bootStripe('2026-12-16 00:00:00', schedule: 'sub_sched_1');

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-16']));

        $this->assertSame([], $this->writes(), 'Stripe already ends the period that day: no release, no price, no trial end');
    }

    /** Summer time (+3): a period ending at 00:30 local is 21:30 UTC the day before. */
    public function test_a_period_end_just_after_local_midnight_in_summer_time_is_that_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Europe/Athens'));
        $service = $this->service('2026-06-16');
        $this->bootStripe('2026-07-16 00:30:00');

        DB::transaction(fn () => $service->update(['expires_at' => '2026-07-16']));

        $this->assertSame([], $this->writes());
    }

    public function test_an_expiry_moved_a_day_past_the_period_end_still_moves_the_trial_end(): void
    {
        $service = $this->service('2026-11-16');
        $this->bootStripe('2026-12-16 00:00:00');

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-17']));

        $this->assertSame(['POST /v1/subscriptions/sub_123'], $this->writes());
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/subscriptions/sub_123')
            && (int) $r['trial_end'] === Carbon::parse('2026-12-17 00:00:00', 'Europe/Athens')->timestamp);
    }

    /** A period ending late in the evening (23:30 local) is that day, not the next: an expiry the next day is an extension. */
    public function test_a_period_end_late_in_the_local_evening_is_that_day(): void
    {
        $service = $this->service('2026-11-16');
        $this->bootStripe('2026-12-15 23:30:00');

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-16']));

        $this->assertSame(['POST /v1/subscriptions/sub_123'], $this->writes());
    }

    public function test_an_expiry_moved_earlier_never_shortens_the_subscription(): void
    {
        $service = $this->service('2026-12-16');
        $this->bootStripe('2026-12-16 00:00:00');

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-15']));

        $this->assertSame([], $this->writes());
    }
}
