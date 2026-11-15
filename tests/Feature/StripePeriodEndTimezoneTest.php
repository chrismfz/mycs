<?php

namespace Tests\Feature;

use App\Models\Gateway;
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
 * the app's timezone). Carbon 3's createFromTimestamp() is in UTC, and Stripe counts periods in UTC months from its
 * anchor: on an instance in Europe/Athens a period anchored at local midnight ends the day before in UTC, or (after a
 * DST change, a month of another length) the day before locally too, so a renewal that only caught up with Stripe
 * looked like an extension: the schedule released, the price re-posted and the trial end set again on every renewal.
 *
 * Now Stripe's period is anchored at local noon of the due day (Stripe::anchorFor): a subscription anchored anywhere
 * else is moved there once (trial_end, no proration), and renewals after that write nothing. stripe:period-anchors
 * lists the ones still to move and, with --apply, moves them.
 */
class StripePeriodEndTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private string $zone;

    private int $periodEnd = 0;

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

    /** Stripe answering the subscription with its period ending at $periodEnd (a time in Athens). */
    private function fakeStripe(string $periodEnd, ?string $schedule = null): void
    {
        $this->periodEnd = Carbon::parse($periodEnd, 'Europe/Athens')->timestamp;
        Http::fake(['https://api.stripe.com/*' => fn () => Http::response([
            'id' => 'sub_123', 'schedule' => $schedule, 'status' => 'active',
            'items' => ['data' => [['id' => 'si_1', 'price' => ['product' => 'prod_1'], 'current_period_end' => $this->periodEnd]]],
        ])]);
    }

    private function bootStripe(string $periodEnd, ?string $schedule = null): void
    {
        $this->fakeStripe($periodEnd, $schedule);
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
    }

    /** @return list<string> what was written at Stripe (method and path) */
    private function writes(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() !== 'GET')
            ->map(fn (array $pair) => $pair[0]->method() . ' ' . parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();
    }

    /** @return list<int> the trial_end values sent */
    private function trialEnds(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/subscriptions/sub_123'))
            ->map(fn (array $pair) => (int) $pair[0]['trial_end'])->values()->all();
    }

    private static function noon(string $date): int
    {
        return Carbon::parse($date . ' 12:00:00', 'Europe/Athens')->timestamp;
    }

    public function test_a_renewal_of_a_subscription_billing_at_local_noon_writes_nothing(): void
    {
        $service = $this->service('2026-11-16');
        $this->bootStripe('2026-12-16 12:00:00', schedule: 'sub_sched_1');

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-16']));

        $this->assertSame([], $this->writes(), 'Stripe already bills that day: no release, no price, no trial end');
    }

    /** Stripe keeps the anchor's hour in UTC: local noon in winter is 13:00 in summer and 11:00 back in winter. */
    public function test_an_anchor_an_hour_off_across_dst_is_still_on_time(): void
    {
        foreach (['2026-12-16 11:00:00', '2026-12-16 13:00:00'] as $periodEnd) {
            $service = $this->service('2026-12-16');
            $this->assertTrue(Stripe::billsOnTime(Carbon::parse($periodEnd, 'Europe/Athens')->timestamp, $service), $periodEnd);
        }
    }

    /** Anchored at local midnight (as before the fix): moved once to local noon of the due day, the schedule released. */
    public function test_a_renewal_of_a_subscription_anchored_at_local_midnight_moves_it_once_to_local_noon(): void
    {
        $service = $this->service('2026-11-16');
        $this->bootStripe('2026-12-16 00:00:00', schedule: 'sub_sched_1');

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-16']));

        $this->assertSame(['POST /v1/subscription_schedules/sub_sched_1/release', 'POST /v1/subscription_items/si_1', 'POST /v1/subscriptions/sub_123'], $this->writes());
        $this->assertSame([self::noon('2026-12-16')], $this->trialEnds(), 'local noon of the due day, after Stripe\'s period end');
    }

    /** Summer time (+3): a period ending at 00:30 local (21:30 UTC the day before) is moved to that day's noon. */
    public function test_a_period_end_just_after_local_midnight_in_summer_time_is_moved_to_that_days_noon(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Europe/Athens'));
        $service = $this->service('2026-06-16');
        $this->bootStripe('2026-07-16 00:30:00');

        DB::transaction(fn () => $service->update(['expires_at' => '2026-07-16']));

        $this->assertSame([self::noon('2026-07-16')], $this->trialEnds());
    }

    public function test_an_expiry_moved_a_day_past_the_period_end_moves_the_trial_end_to_its_noon(): void
    {
        $service = $this->service('2026-11-16');
        $this->bootStripe('2026-12-16 12:00:00');

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-17']));

        $this->assertSame(['POST /v1/subscriptions/sub_123'], $this->writes());
        $this->assertSame([self::noon('2026-12-17')], $this->trialEnds());
    }

    public function test_an_expiry_moved_earlier_never_shortens_the_subscription(): void
    {
        $service = $this->service('2026-12-16');
        $this->bootStripe('2026-12-16 12:00:00');

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-15']));

        $this->assertSame([], $this->writes());
    }

    private function gateway(): void
    {
        $gateway = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test']);
    }

    public function test_the_repair_lists_what_bills_early_and_writes_nothing_without_apply(): void
    {
        $this->gateway();
        $early = $this->service('2026-12-16');
        $this->fakeStripe('2026-12-16 00:00:00');

        $this->artisan('stripe:period-anchors')
            ->expectsOutputToContain("#{$early->id} sub_123: Stripe bills 2026-12-16 00:00, due 2026-12-16")
            ->expectsOutputToContain('1 bill(s) before the due day: run with --apply to move them.')
            ->assertSuccessful();

        $this->assertSame([], $this->writes(), 'detect first: nothing written');
    }

    public function test_the_repair_with_apply_moves_each_once_and_a_second_run_finds_none(): void
    {
        $this->gateway();
        $this->service('2026-12-16');
        $this->fakeStripe('2026-12-16 00:00:00');

        $this->artisan('stripe:period-anchors --apply')->expectsOutputToContain('moved to 2026-12-16 12:00')->assertSuccessful();
        $this->assertSame([self::noon('2026-12-16')], $this->trialEnds());

        $this->periodEnd = self::noon('2026-12-16'); // Stripe now bills there
        $this->artisan('stripe:period-anchors --apply')->expectsOutputToContain('Every Stripe subscription bills on its due day.')->assertSuccessful();
        $this->assertCount(1, $this->trialEnds(), 'idempotent');
    }

    public function test_the_repair_leaves_a_passed_due_day_and_another_gateways_subscription_alone(): void
    {
        $this->gateway();
        $passed = $this->service('2026-11-10');
        $paypal = $this->service('2026-12-16');
        $paypal->update(['subscription_id' => 'I-PAYPAL1']);
        $paypal->properties()->where('key', 'has_stripe_subscription')->delete();
        $this->fakeStripe('2026-11-10 00:00:00');

        $this->artisan('stripe:period-anchors --apply')
            ->expectsOutputToContain("#{$passed->id} sub_123: Stripe bills 2026-11-10 00:00, due 2026-11-10: its due day has passed, left for staff")
            ->doesntExpectOutputToContain('I-PAYPAL1')
            ->assertSuccessful();

        $this->assertSame([], $this->writes());
    }
}
