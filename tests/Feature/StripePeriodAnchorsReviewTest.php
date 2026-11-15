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
 * Adversarial review of the local-noon anchor (c07c307: Stripe::anchorFor / billsOnTime, stripe:period-anchors).
 *
 * Stripe counts periods from its anchor in UTC calendar months, keeping the anchor's UTC day (clamped to short months)
 * and UTC time. Tests named test_holds_* are attacks that hold (they pass); every other test is a finding that FAILS on
 * cead3d0.
 */
class StripePeriodAnchorsReviewTest extends TestCase
{
    use RefreshDatabase;

    private string $zone;

    /** What GET /v1/subscriptions/* answers. */
    private array $stripe = ['period_end' => 0, 'schedule' => null, 'status' => 'active', 'missing' => []];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->zone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->zone);
        parent::tearDown();
    }

    private function at(string $zone, string $now): void
    {
        date_default_timezone_set($zone);
        Carbon::setTestNow(Carbon::parse($now, $zone));
    }

    private function service(string $expires, string $subscription = 'sub_123'): Service
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'subscription_id' => $subscription,
        ]);
        $service->properties()->create(['key' => 'has_stripe_subscription', 'name' => 'Subscription', 'value' => '1']);

        return $service->fresh();
    }

    private function fakeStripe(int $periodEnd, ?string $schedule = null, string $status = 'active'): void
    {
        $this->stripe = ['period_end' => $periodEnd, 'schedule' => $schedule, 'status' => $status, 'missing' => []];
        Http::fake(['https://api.stripe.com/*' => function (Request $request) {
            $id = basename((string) parse_url($request->url(), PHP_URL_PATH));
            if (in_array($id, $this->stripe['missing'], true)) {
                return Http::response(['error' => ['code' => 'resource_missing', 'message' => 'No such subscription']], 404);
            }

            return Http::response([
                'id' => $id, 'schedule' => $this->stripe['schedule'], 'status' => $this->stripe['status'],
                'items' => ['data' => [['id' => 'si_1', 'price' => ['product' => 'prod_1'], 'current_period_end' => $this->stripe['period_end']]]],
            ]);
        }]);
    }

    private function bootStripe(int $periodEnd, ?string $schedule = null): void
    {
        $this->fakeStripe($periodEnd, $schedule);
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
    }

    private function gateway(): void
    {
        $gateway = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test']);
    }

    /** @return list<string> */
    private function writes(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() !== 'GET')
            ->map(fn (array $pair) => $pair[0]->method() . ' ' . parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();
    }

    /** @return list<int> */
    private function trialEnds(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/v1/subscriptions/'))
            ->map(fn (array $pair) => (int) $pair[0]['trial_end'])->values()->all();
    }

    /** Stripe's period end $months after an anchor (UTC months, the anchor's UTC day kept, clamped). */
    private static function stripeAfter(int $anchor, int $months): int
    {
        return Carbon::createFromTimestamp($anchor, 'UTC')->addMonthsNoOverflow($months)->timestamp;
    }

    // ---------------------------------------------------------------- findings (fail on cead3d0)

    /**
     * REV-ANCHOR-1. In UTC+13/+14 (Pacific/Auckland in summer, Pacific/Apia, Pacific/Tongatapu, Pacific/Kiritimati) local
     * noon is 22:00/23:00 UTC of the PREVIOUS day, so the anchor's UTC day is not the due day and month clamping is back:
     * an extension to Dec 1 anchors Stripe on Nov 30 (UTC), whose next period ends Dec 30 UTC = Dec 31 local, while the
     * service renews to Jan 1. Stripe charges the customer a day before the due day, and the renewal moves it again.
     */
    public function test_auckland_summer_noon_anchor_bills_the_next_period_on_its_due_day(): void
    {
        $this->at('Pacific/Auckland', '2026-11-15 12:00:00');
        $service = $this->service('2026-11-16');
        $this->bootStripe(Carbon::parse('2026-11-16 12:00:00', 'Pacific/Auckland')->timestamp);

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-01'])); // staff give two weeks
        $this->assertCount(1, $trialEnd = $this->trialEnds());

        $next = self::stripeAfter($trialEnd[0], 1);
        $this->assertTrue(Stripe::billsOnTime($next, $service->fresh()->forceFill(['expires_at' => '2027-01-01'])),
            'Stripe bills ' . Carbon::createFromTimestamp($next, 'Pacific/Auckland')->format('Y-m-d H:i') . ' for a service due 2027-01-01');
    }

    /** REV-ANCHOR-1, a zone with no DST at all (UTC+14): the same, every time a 1st-of-month due follows a 30-day month. */
    public function test_kiritimati_noon_anchor_bills_the_next_period_on_its_due_day(): void
    {
        $this->at('Pacific/Kiritimati', '2026-11-15 12:00:00');
        $service = $this->service('2026-11-16');
        $this->bootStripe(Carbon::parse('2026-11-16 12:00:00', 'Pacific/Kiritimati')->timestamp);

        DB::transaction(fn () => $service->update(['expires_at' => '2026-12-01']));
        $this->assertCount(1, $trialEnd = $this->trialEnds());

        $next = self::stripeAfter($trialEnd[0], 1);
        $this->assertTrue(Stripe::billsOnTime($next, $service->fresh()->forceFill(['expires_at' => '2027-01-01'])),
            'Stripe bills ' . Carbon::createFromTimestamp($next, 'Pacific/Kiritimati')->format('Y-m-d H:i') . ' for a service due 2027-01-01');
    }

    /**
     * REV-ANCHOR-2. A subscription already canceled at Stripe (still retrievable, its period end in the past) is listed as
     * «Stripe bills ... before the due day», and --apply posts trial_end to it (Stripe refuses: exit 1 on every run). The
     * command never reads `status`: what it should say is that Stripe no longer bills it at all, for staff.
     */
    public function test_the_repair_does_not_take_a_canceled_subscription_for_one_billing_early(): void
    {
        $this->at('Europe/Athens', '2026-11-15 12:00:00');
        $this->gateway();
        $this->service('2026-12-16');
        $this->fakeStripe(Carbon::parse('2026-11-01 00:00:00', 'Europe/Athens')->timestamp, status: 'canceled');

        $this->artisan('stripe:period-anchors --apply')->doesntExpectOutputToContain('Stripe bills 2026-11-01');

        $this->assertSame([], $this->writes(), 'nothing posted to a canceled subscription');
    }

    // ---------------------------------------------------------------- attacks that hold

    /**
     * A service on the 31st moved while on Feb 28 (Stripe's anchor day becomes 28): one more move on the renewal to
     * Mar 31, then a year of renewals (month ends, both DST changes) writes nothing. No loop, never shortened.
     */
    public function test_holds_a_31st_service_moved_on_feb_28_settles_after_one_more_move(): void
    {
        $this->at('America/Los_Angeles', '2027-01-31 13:00:00');
        $service = $this->service('2027-01-31');
        $this->bootStripe(Carbon::parse('2027-02-28 00:00:00', 'America/Los_Angeles')->timestamp); // midnight anchor

        DB::transaction(fn () => $service->update(['expires_at' => '2027-02-28']));
        $this->assertSame([Carbon::parse('2027-02-28 12:00:00', 'America/Los_Angeles')->timestamp], $this->trialEnds());

        $this->stripe['period_end'] = self::stripeAfter($this->trialEnds()[0], 1); // Mar 28 noon: anchor day 28
        DB::transaction(fn () => $service->fresh()->update(['expires_at' => '2027-03-31']));
        $this->assertCount(2, $this->trialEnds());
        $this->assertGreaterThan($this->stripe['period_end'], $this->trialEnds()[1], 'never shorter');

        $anchor = $this->trialEnds()[1];
        for ($m = 1; $m <= 12; $m++) {
            $this->stripe['period_end'] = self::stripeAfter($anchor, $m);
            $due = Carbon::parse('2027-03-31', 'America/Los_Angeles')->addMonthsNoOverflow($m)->toDateString(); // billing day 31
            $before = count($this->writes());
            DB::transaction(fn () => $service->fresh()->update(['expires_at' => $due]));
            $this->assertSame([], array_slice($this->writes(), $before), 'renewal to ' . $due);
        }
    }

    /** Anchor at noon in summer, an hour off in winter (11:00), then staff extend exactly one day: that is moved. */
    public function test_holds_a_one_day_extension_after_dst_is_moved_and_a_dst_hour_is_not(): void
    {
        $this->at('Europe/Athens', '2026-10-20 12:00:00');
        $summerNoon = Carbon::parse('2026-10-25 12:00:00', 'Europe/Athens')->timestamp; // before the 04:00 change
        $service = $this->service('2026-10-25');
        $this->bootStripe(self::stripeAfter($summerNoon, 1)); // Nov 25 09:00 UTC = 11:00 EET

        DB::transaction(fn () => $service->update(['expires_at' => '2026-11-25']));
        $this->assertSame([], $this->writes(), 'an hour off is on time');

        DB::transaction(fn () => $service->fresh()->update(['expires_at' => '2026-11-26']));
        $this->assertSame([Carbon::parse('2026-11-26 12:00:00', 'Europe/Athens')->timestamp], $this->trialEnds());
    }

    /** A subscription gone at Stripe (404): reported, the rest still moved, exit code failure; no write for the missing one. */
    public function test_holds_the_repair_goes_on_past_a_missing_subscription_and_fails_the_run(): void
    {
        $this->at('Europe/Athens', '2026-11-15 12:00:00');
        $this->gateway();
        $gone = $this->service('2026-12-16', 'sub_gone');
        $this->service('2026-12-16', 'sub_ok');
        $this->fakeStripe(Carbon::parse('2026-12-16 00:00:00', 'Europe/Athens')->timestamp);
        $this->stripe['missing'] = ['sub_gone'];

        $this->artisan('stripe:period-anchors --apply')
            ->expectsOutputToContain("#{$gone->id} sub_gone: not read")
            ->expectsOutputToContain('sub_ok: Stripe bills 2026-12-16 00:00, due 2026-12-16: moved to 2026-12-16 12:00')
            ->assertFailed();

        $this->assertSame(['POST /v1/subscriptions/sub_ok'], $this->writes());
    }

    /** Without --apply nothing is written, a schedule-managed subscription included (no release, no price, no trial). */
    public function test_holds_the_repair_without_apply_never_writes_with_a_schedule(): void
    {
        $this->at('Europe/Athens', '2026-11-15 12:00:00');
        $this->gateway();
        $this->service('2026-12-16');
        $this->service('2026-12-16', 'sub_other');
        $this->fakeStripe(Carbon::parse('2026-12-15 23:00:00', 'Europe/Athens')->timestamp, schedule: 'sub_sched_1');

        $this->artisan('stripe:period-anchors')->expectsOutputToContain('2 bill(s) before the due day')->assertSuccessful();

        $this->assertSame([], $this->writes());
    }
}
