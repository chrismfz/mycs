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
 * Adversarial review of the Stripe period-end timezone fix (111d73b, updateSubscription's expires_at branch).
 *
 * Stripe counts a subscription's periods from its anchor (an instant) in UTC calendar months: a schedule started at
 * local midnight in Europe/Athens (setupSubscription: now()->startOfDay()) is anchored at 21:00 UTC (summer, +3) or
 * 22:00 UTC (winter, +2) of the day BEFORE the local date. Its later period ends keep that UTC time and that UTC day of
 * the month, while expires_at advances by local calendar months. The fix reads the period end in the app's timezone,
 * which only matches while the offset and the month length line up.
 *
 * Tests named test_holds_* are attacks that hold (they pass). Every other test is a finding that FAILS on 111d73b.
 *
 * The fix agreed with the owner (2026-11-15): Stripe's period is anchored at local NOON of the due day (trial_end, no
 * proration), never near a midnight. A subscription still anchored at local midnight is moved there once, on its next
 * renewal or by `stripe:period-anchors --apply`; the renewals after that write nothing. Where the review asked for
 * «writes nothing» on a midnight-anchored subscription, the test now asks for exactly that: one move to local noon
 * (never before Stripe's period end) and nothing after it (assertMovedOnceToNoon).
 */
class StripePeriodEndTimezoneAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private string $zone;

    /** What GET /v1/subscriptions/* answers: the period end and where it sits (basil: on the item). */
    private array $stripe = ['period_end' => 0, 'schedule' => null, 'basil' => true];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Athens');
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

    private function bootStripe(int $periodEnd, ?string $schedule = null, bool $basil = true): void
    {
        $this->stripe = ['period_end' => $periodEnd, 'schedule' => $schedule, 'basil' => $basil];
        Http::fake(['https://api.stripe.com/*' => function () {
            $item = ['id' => 'si_1', 'price' => ['product' => 'prod_1']];
            $body = ['id' => 'sub_123', 'status' => 'active', 'schedule' => $this->stripe['schedule'], 'items' => ['data' => [$item]]];
            if ($this->stripe['basil']) {
                $body['items']['data'][0]['current_period_end'] = $this->stripe['period_end'];
            } else {
                $body['current_period_end'] = $this->stripe['period_end'];
            }

            return Http::response($body);
        }]);
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
    }

    /** setupSubscription's start_date (line 697) for a schedule created at $localNow, in Athens. */
    private static function anchorAt(string $localNow): Carbon
    {
        Carbon::setTestNow(Carbon::parse($localNow, 'Europe/Athens'));
        $anchor = Carbon::createFromTimestamp(now()->startOfDay()->timestamp, 'UTC');
        Carbon::setTestNow();

        return $anchor;
    }

    /** Stripe's n-th period end from an anchor: UTC calendar months, the anchor's day kept (clamped to short months). */
    private static function stripePeriodEnd(Carbon $anchorUtc, int $months): int
    {
        return $anchorUtc->copy()->addMonthsNoOverflow($months)->timestamp;
    }

    /** @return list<string> what was written at Stripe (method and path) */
    private function writes(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() !== 'GET')
            ->map(fn (array $pair) => $pair[0]->method() . ' ' . parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();
    }

    private function trialEndsSent(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/subscriptions/sub_123'))
            ->map(fn (array $pair) => (int) $pair[0]['trial_end'])->values()->all();
    }

    /**
     * The subscription was moved once to local noon of $due (after Stripe's period end: never shorter) and, Stripe then
     * counting UTC months from that anchor, the renewals after it (across DST changes) write nothing.
     */
    private function assertMovedOnceToNoon(Service $service, string $due, string $zone = 'Europe/Athens', int $sentBefore = 0): void
    {
        $noon = Carbon::parse($due . ' 12:00:00', $zone)->timestamp;
        $this->assertSame([$noon], array_slice($this->trialEndsSent(), $sentBefore), 'moved once to local noon of ' . $due);
        $this->assertGreaterThan($this->stripe['period_end'], $noon, 'never shorter than Stripe\'s period');

        foreach ([1, 4, 7] as $months) {
            $this->stripe['period_end'] = Carbon::createFromTimestamp($noon, 'UTC')->addMonthsNoOverflow($months)->timestamp;
            $before = count($this->writes());
            $next = Carbon::parse($due, $zone)->addMonthsNoOverflow($months)->toDateString();
            DB::transaction(fn () => $service->fresh()->update(['expires_at' => $next]));
            $this->assertSame([], array_slice($this->writes(), $before), 'the renewal to ' . $next . ' writes nothing');
        }
    }

    // ---------------------------------------------------------------- findings (fail on 111d73b)

    /**
     * ADV-TZ-1. A schedule started in summer time (anchor 21:00 UTC) renews in winter time: Stripe's period end is
     * 21:00 UTC = 23:00 EET the day BEFORE the local expiry. A plain renewal is taken for an extension: the schedule is
     * released, the price re-posted and the trial end re-set (the very bug the fix claims to close) for every
     * subscription started April-October, on each renewal from November to March.
     */
    public function test_a_schedule_started_in_summer_renewing_in_winter_is_moved_once_and_then_stable(): void
    {
        $anchor = self::anchorAt('2026-07-15 12:00:00');              // 2026-07-14 21:00 UTC
        $periodEnd = self::stripePeriodEnd($anchor, 5);              // 2026-12-14 21:00 UTC = 23:00 Athens on 12-14
        Carbon::setTestNow(Carbon::parse('2026-12-14 23:30:00', 'Europe/Athens'));
        $service = $this->service('2026-11-15');
        $this->bootStripe($periodEnd, schedule: 'sub_sched_1');

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-15']));  // the renewal Stripe just paid

        $this->assertMovedOnceToNoon($service, '2026-12-15');
    }

    /**
     * ADV-TZ-2. No DST at all: a schedule started on the 1st (local) is anchored on the last day of the previous month in
     * UTC. Started 2026-05-01 the anchor is the 30th (21:00 UTC): after a 31-day month Stripe's period end is the 31st
     * locally while expires_at is the 1st. Renewal again read as an extension (and Stripe bills a day early).
     */
    public function test_a_schedule_started_on_the_first_renewing_after_a_31_day_month_is_moved_once_and_then_stable(): void
    {
        $anchor = self::anchorAt('2026-05-01 12:00:00');              // 2026-04-30 21:00 UTC: Stripe's day 30
        $periodEnd = self::stripePeriodEnd($anchor, 4);              // 2026-08-30 21:00 UTC = 2026-08-31 00:00 Athens
        Carbon::setTestNow(Carbon::parse('2026-07-31 00:30:00', 'Europe/Athens'));
        $service = $this->service('2026-08-01');
        $this->bootStripe($periodEnd);

        DB::transaction(fn () => $service->update(['expires_at' => '2026-09-01']));

        $this->assertMovedOnceToNoon($service, '2026-09-01');
    }

    /**
     * ADV-TZ-3. The trial_end sent for a real extension is local midnight (21:00/22:00 UTC the day before), which
     * re-anchors Stripe on the previous UTC day: the next ordinary renewal is again read as an extension. Here an
     * extension to 2026-12-01 anchors Stripe on Nov 30 22:00 UTC; its next period ends Dec 30 22:00 UTC (Dec 31 local)
     * while the service renews to 2027-01-01.
     */
    public function test_the_renewal_after_an_extension_writes_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'Europe/Athens'));
        $service = $this->service('2026-11-16');
        $this->bootStripe(Carbon::parse('2026-11-16 00:00:00', 'Europe/Athens')->timestamp);

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-01'])); // staff give two weeks
        $trialEnd = $this->trialEndsSent();
        $this->assertCount(1, $trialEnd, 'the extension moves the trial end');

        // Stripe: the trial ends at trial_end, the next period one UTC month later.
        $this->stripe['period_end'] = Carbon::createFromTimestamp($trialEnd[0], 'UTC')->addMonthsNoOverflow(1)->timestamp;
        Carbon::setTestNow(Carbon::parse('2026-12-31 01:00:00', 'Europe/Athens'));
        $before = count($this->writes());
        DB::transaction(fn () => $service->fresh()->update(['expires_at' => '2027-01-01'])); // the renewal Stripe paid

        $this->assertSame([], array_slice($this->writes(), $before), 'a plain renewal after the extension writes nothing');
    }

    // ---------------------------------------------------------------- attacks that hold

    /** An instance in UTC (no settings.timezone): a period ending at 00:00 UTC is moved once to noon UTC, then stable. */
    public function test_holds_utc_instance_renewal_at_midnight_is_moved_once_and_then_stable(): void
    {
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        $service = $this->service('2026-11-16');
        $this->bootStripe(Carbon::parse('2026-12-16 00:00:00', 'UTC')->timestamp, schedule: 'sub_sched_1');

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-16']));

        $this->assertMovedOnceToNoon($service, '2026-12-16', 'UTC');
    }

    /** UTC instance, a real extension: trial_end at noon UTC of the expiry, nothing else (no schedule). */
    public function test_holds_utc_instance_extension_sends_trial_end_at_utc_noon(): void
    {
        date_default_timezone_set('UTC');
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        $service = $this->service('2026-11-16');
        $this->bootStripe(Carbon::parse('2026-12-16 00:00:00', 'UTC')->timestamp);

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-17']));

        $this->assertSame(['POST /v1/subscriptions/sub_123'], $this->writes());
        $this->assertSame([Carbon::parse('2026-12-17 12:00:00', 'UTC')->timestamp], $this->trialEndsSent());
    }

    /** Started in winter (22:00 UTC), renewing in summer: 01:00 EEST the expiry day, moved once to its noon. */
    public function test_holds_a_schedule_started_in_winter_renewing_in_summer_is_moved_once_and_then_stable(): void
    {
        $anchor = self::anchorAt('2026-01-15 12:00:00');              // 2026-01-14 22:00 UTC
        $periodEnd = self::stripePeriodEnd($anchor, 6);              // 2026-07-14 22:00 UTC = 07-15 01:00 Athens
        Carbon::setTestNow(Carbon::parse('2026-07-15 02:00:00', 'Europe/Athens'));
        $service = $this->service('2026-06-15');
        $this->bootStripe($periodEnd, schedule: 'sub_sched_1');

        DB::transaction(fn () => $service->update(['expires_at' => '2026-07-15']));

        $this->assertMovedOnceToNoon($service, '2026-07-15');
    }

    /** Pre-basil shape (current_period_end on the subscription, none on the item) is read the same way. */
    public function test_holds_pre_basil_shape_renewal_is_read_the_same(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'Europe/Athens'));
        $service = $this->service('2026-11-16');
        $this->bootStripe(Carbon::parse('2026-12-16 00:00:00', 'Europe/Athens')->timestamp, basil: false);

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-16']));

        $this->assertMovedOnceToNoon($service, '2026-12-16');
    }

    /** Period ends at local midnight ON the DST change days (2026-03-29 and 2026-10-25): moved once to that day's noon. */
    public function test_holds_period_ends_at_local_midnight_on_dst_change_days(): void
    {
        $this->bootStripe(0); // once: booted twice, its listeners would answer each change twice
        foreach (['2026-03-29' => '2026-02-28', '2026-10-25' => '2026-09-25'] as $day => $before) {
            Carbon::setTestNow(Carbon::parse($day . ' 00:30:00', 'Europe/Athens'));
            $service = $this->service($before);
            $this->stripe['period_end'] = Carbon::parse($day . ' 00:00:00', 'Europe/Athens')->timestamp;
            $sent = count($this->trialEndsSent());

            DB::transaction(fn () => $service->update(['expires_at' => $day]));

            $this->assertMovedOnceToNoon($service, $day, sentBefore: $sent);
            $service->update(['subscription_id' => null]);
        }
    }

    /** Never shortens: whenever a trial_end is sent (even in the ADV-TZ-1 case), it is after Stripe's period end. */
    public function test_holds_a_trial_end_sent_is_never_before_the_period_end(): void
    {
        $periodEnd = self::stripePeriodEnd(self::anchorAt('2026-07-15 12:00:00'), 5); // 23:00 Athens on 12-14
        Carbon::setTestNow(Carbon::parse('2026-12-14 23:30:00', 'Europe/Athens'));
        $service = $this->service('2026-11-15');
        $this->bootStripe($periodEnd);

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-15']));

        foreach ($this->trialEndsSent() as $trialEnd) {
            $this->assertGreaterThan($periodEnd, $trialEnd);
        }
        $this->assertTrue(true);
    }

    /** Never shortens across DST: an expiry moved to the period end's local day (or earlier) writes nothing. */
    public function test_holds_an_expiry_moved_to_or_before_the_period_end_day_writes_nothing(): void
    {
        $periodEnd = self::stripePeriodEnd(self::anchorAt('2026-07-15 12:00:00'), 5); // 23:00 Athens on 12-14
        Carbon::setTestNow(Carbon::parse('2026-11-20 12:00:00', 'Europe/Athens'));
        $service = $this->service('2026-12-20');
        $this->bootStripe($periodEnd, schedule: 'sub_sched_1');

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-14']));
        DB::transaction(fn () => $service->fresh()->update(['expires_at' => '2026-12-01']));

        $this->assertSame([], $this->writes());
    }
}
