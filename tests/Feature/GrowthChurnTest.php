<?php

namespace Tests\Feature;

use App\Admin\Pages\GrowthAndChurn;
use App\Models\Category;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\User;
use App\Services\Import\WhmcsEndDates;
use App\Services\Reports\GrowthChurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\TestCase;

/**
 * Growth and churn (owner 2026-11-17, docs/growth-churn.md): the recurring income a month that came and went, month by
 * month; when a service ended (`ended_at`), set by MyCS and brought from WHMCS.
 */
class GrowthChurnTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-17 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR']);
        $this->client = User::factory()->create()->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name = 'Hosting', ?Category $category = null): Product
    {
        $category ??= Category::query()->firstOrCreate(['slug' => 'hosting'], ['name' => 'Hosting']);

        return Product::factory()->create(['name' => $name, 'category_id' => $category->id]);
    }

    private function plan(Product $product, string $unit = 'month', int $period = 1, string $type = 'recurring'): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'p',
            'billing_unit' => $unit, 'billing_period' => $period, 'type' => $type]);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 1, 'setup_fee' => 0, 'currency_code' => 'EUR']);

        return $plan;
    }

    private function service(Product $product, string $price, string $started, ?Plan $plan = null, array $extra = []): Service
    {
        return Service::factory()->create($extra + ['client_id' => $this->client->id, 'product_id' => $product->id,
            'plan_id' => ($plan ?? $this->plan($product))->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR',
            'price' => $price, 'quantity' => 1, 'expires_at' => '2026-12-01', 'activated_at' => $started]);
    }

    /** Ends it at that time, as the cron or staff would: its status turns, the observer stamps the time. */
    private function endAt(Service $service, string $at, string $status = Service::STATUS_TERMINATED): void
    {
        Carbon::setTestNow($at);
        $service->refresh()->update(['status' => $status]);
        Carbon::setTestNow('2026-11-17 10:00:00');
    }

    /** A change of package applied at that time, from what it had before. */
    private function upgraded(Service $service, string $at, string $fromPrice, ?Product $fromProduct = null, ?Plan $fromPlan = null): void
    {
        DB::table('service_upgrades')->insert(['service_id' => $service->id, 'product_id' => $service->product_id, 'plan_id' => $service->plan_id,
            'status' => ServiceUpgrade::STATUS_COMPLETED, 'from_product_id' => ($fromProduct ?? $service->product)->id,
            'from_plan_id' => ($fromPlan ?? $service->plan)->id, 'from_price' => $fromPrice, 'applied_at' => $at,
            'created_at' => $at, 'updated_at' => $at]);
    }

    /** @return array<string, mixed> the month's row (1–12) */
    private static function month(array $report, int $month): array
    {
        return $report['months'][$month - 1];
    }

    public function test_the_end_is_stamped_when_a_service_ends_kept_between_ended_statuses_and_cleared_when_it_runs_again(): void
    {
        $service = $this->service($this->product(), '10.00', '2026-01-10 09:00:00');
        $this->assertNull($service->ended_at);

        $this->endAt($service, '2026-05-03 08:00:00');
        $this->assertSame('2026-05-03 08:00:00', $service->refresh()->ended_at->format('Y-m-d H:i:s'));
        $this->endAt($service, '2026-05-09 08:00:00', Service::STATUS_CANCELLED);
        $this->assertSame('2026-05-03 08:00:00', $service->refresh()->ended_at->format('Y-m-d H:i:s'), 'still the first end');

        $service->update(['status' => Service::STATUS_ACTIVE]);
        $this->assertNull($service->refresh()->ended_at, 'staff brought it back');
        $service->update(['status' => Service::STATUS_TERMINATED, 'ended_at' => '2026-04-30 00:00:00']);
        $this->assertSame('2026-04-30', $service->refresh()->ended_at->toDateString(), 'a time given with the change is kept');
    }

    public function test_new_services_count_at_their_monthly_value_by_cycle_and_free_or_one_time_not_at_all(): void
    {
        $product = $this->product();
        $this->service($product, '10.00', '2026-03-05 09:00:00');
        $this->service($product, '120.00', '2026-03-20 09:00:00', $this->plan($product, 'year'));
        $this->service($product, '30.00', '2026-03-21 09:00:00', $this->plan($product, 'month', 3), ['quantity' => 2]);
        $this->service($product, '50.00', '2026-03-22 09:00:00', $this->plan($product, 'month', 1, 'one-time'));
        $this->service($product, '0.00', '2026-03-23 09:00:00', $this->plan($product, 'month', 1, 'free'));
        $this->service($product, '10.00', '2026-04-01 09:00:00', null, ['status' => Service::STATUS_PENDING, 'activated_at' => null]);

        $report = GrowthChurn::year('EUR', 2026);
        $march = self::month($report, 3);
        // 10 + 120/12 + 2 × 30/3 = 40 a month, three services; the one-time, the free and the one never started: none.
        $this->assertSame(4000, $march['new']);
        $this->assertSame(3, $march['new_count']);
        $this->assertSame(0, $march['start']);
        $this->assertSame(4000, $march['end_mrr']);
        $this->assertSame(0, self::month($report, 4)['new']);
        $this->assertSame(4000, $report['mrr_now']);
        $this->assertSame(0, $report['mrr_year_ago']);
    }

    public function test_upgrades_and_downgrades_move_the_month_and_a_change_of_product_moves_its_value_between_products(): void
    {
        $small = $this->product('Small');
        $big = $this->product('Big');
        $up = $this->service($big, '25.00', '2026-02-01 09:00:00');
        $this->upgraded($up, '2026-06-10 12:00:00', '10.00', $small, $this->plan($small));
        $down = $this->service($small, '5.00', '2026-02-02 09:00:00');
        $this->upgraded($down, '2026-06-11 12:00:00', '8.00');

        $report = GrowthChurn::year('EUR', 2026);
        $this->assertSame(1800, self::month($report, 2)['new'], 'each started at its price before the change');
        $june = self::month($report, 6);
        $this->assertSame(1500, $june['up']);
        $this->assertSame(1, $june['up_count']);
        $this->assertSame(-300, $june['down']);
        $this->assertSame(1200, $june['net']);
        $this->assertSame(1800, $june['start']);
        $this->assertSame(3000, $june['end_mrr']);

        $products = collect($report['products'])->keyBy('name');
        $this->assertEquals(['new' => 0, 'moved' => 2500, 'lost' => 0, 'net' => 2500, 'now' => 2500],
            collect($products['Big'])->only(['new', 'moved', 'lost', 'net', 'now'])->all());
        // Small: both started there (10 + 8), lost 10 to Big and 3 to the downgrade.
        $this->assertEquals(['new' => 1800, 'moved' => -1300, 'lost' => 0, 'net' => 500, 'now' => 500],
            collect($products['Small'])->only(['new', 'moved', 'lost', 'net', 'now'])->all());
    }

    public function test_losses_are_cancelled_with_a_request_or_terminated_with_none_and_why_they_left(): void
    {
        $product = $this->product();
        $asked = $this->service($product, '10.00', '2025-05-01 09:00:00');
        ServiceCancellation::withoutEvents(fn () => ServiceCancellation::create(['service_id' => $asked->id, 'reason' => '  Too   expensive ', 'type' => 'end_of_period']));
        $this->endAt($asked, '2026-09-01 00:10:00', Service::STATUS_CANCELLED);
        $also = $this->service($product, '6.00', '2025-05-02 09:00:00');
        ServiceCancellation::withoutEvents(fn () => ServiceCancellation::create(['service_id' => $also->id, 'reason' => 'too expensive', 'type' => 'end_of_period']));
        $this->endAt($also, '2026-09-02 00:10:00', Service::STATUS_CANCELLED);
        $unpaid = $this->service($product, '20.00', '2025-05-03 09:00:00');
        $this->endAt($unpaid, '2026-09-03 00:10:00');

        $report = GrowthChurn::year('EUR', 2026);
        $september = self::month($report, 9);
        $this->assertSame(1600, $september['cancelled']);
        $this->assertSame(2, $september['cancelled_count']);
        $this->assertSame(2000, $september['terminated']);
        $this->assertSame(-3600, $september['net']);
        $this->assertSame(3600, $september['start']);
        $this->assertSame(0, $september['end_mrr']);

        $this->assertSame([[GrowthChurn::TERMINATED, 1, 2000], ['text:too expensive', 2, 1600]],
            array_map(fn ($row) => [$row['key'], $row['count'], $row['cents']], $report['reasons']));
        $this->assertSame('Too expensive', GrowthAndChurn::reasonLabel($report['reasons'][1]));
        // A year before it ran whole: 36 a month then, nothing now.
        $this->assertSame(3600, $report['mrr_year_ago']);
        $this->assertSame(0, $report['mrr_now']);
        $this->assertSame(0, $report['totals']['new']);
        $this->assertSame(3600, $report['last_totals']['new'], 'they came in 2025');
    }

    public function test_a_service_ended_with_no_known_date_is_left_out_and_counted_apart(): void
    {
        $service = $this->service($this->product(), '10.00', '2025-01-01 09:00:00');
        // As an import of WHMCS without its termination date: ended, no time.
        DB::table('services')->where('id', $service->id)->update(['status' => Service::STATUS_TERMINATED, 'ended_at' => null]);

        $report = GrowthChurn::year('EUR', 2026);
        $this->assertSame(['count' => 1, 'cents' => 1000], $report['unknown']);
        $this->assertSame(0, $report['mrr_year_ago']);
        $this->assertSame(0, $report['last_totals']['new']);
    }

    public function test_a_month_always_adds_up_whatever_starts_changes_and_ends_in_it(): void
    {
        $this->seedRandom(20261117);
        $products = [$this->product('A'), $this->product('B'), $this->product('C')];
        $plans = array_map(fn (Product $product) => [$this->plan($product), $this->plan($product, 'year'), $this->plan($product, 'month', 3)], $products);
        for ($i = 0; $i < 60; $i++) {
            $p = $this->rand(0, 2);
            $start = Carbon::create(2025, 1, 1)->addMinutes($this->rand(0, 680 * 24 * 60));
            $service = $this->service($products[$p], $this->rand(0, 9000) / 100 . '', $start->toDateTimeString(), $plans[$p][$this->rand(0, 2)],
                ['quantity' => $this->rand(1, 3)]);
            $at = $start->copy();
            for ($u = $this->rand(0, 3); $u > 0; $u--) {
                $at = $at->copy()->addMinutes($this->rand(0, 120 * 24 * 60));
                if ($at->greaterThan('2026-11-17 09:00:00')) {
                    break;
                }
                $q = $this->rand(0, 2);
                $this->upgraded($service, $at->toDateTimeString(), $this->rand(0, 9000) / 100 . '', $products[$q], $plans[$q][$this->rand(0, 2)]);
            }
            if ($this->rand(0, 2) === 0) {
                $end = $at->copy()->addMinutes($this->rand(0, 200 * 24 * 60));
                if ($end->lessThan('2026-11-17 09:00:00')) {
                    $this->endAt($service, $end->toDateTimeString(), $this->rand(0, 1) ? Service::STATUS_TERMINATED : Service::STATUS_CANCELLED);
                }
            }
        }

        foreach ([2025, 2026] as $year) {
            $report = GrowthChurn::year('EUR', $year);
            $previous = null;
            foreach ($report['months'] as $row) {
                $this->assertSame(0, $row['adjust'], "{$year}-{$row['month']->month}: start + movements = end");
                if ($previous !== null) {
                    $this->assertSame($previous['end_mrr'], $row['start']);
                }
                $previous = $row;
            }
            $this->assertSame($report['mrr_now'], $previous['end_mrr']);
            $this->assertSame(array_sum(array_column($report['products'], 'net')), $report['totals']['net'], 'the products add up to the months');
        }
    }

    public function test_whmcs_end_dates_fill_only_the_ended_without_one_addons_from_their_own_or_their_parent(): void
    {
        $product = $this->product();
        $ended = $this->service($product, '10.00', '2024-01-01 09:00:00', null, ['id' => 501]);
        $mine = $this->service($product, '10.00', '2024-01-01 09:00:00', null, ['id' => 502]);
        $running = $this->service($product, '10.00', '2024-01-01 09:00:00', null, ['id' => 503]);
        $noDate = $this->service($product, '10.00', '2024-01-01 09:00:00', null, ['id' => 504]);
        $addon = $this->service($product, '2.00', '2024-01-01 09:00:00', null, ['id' => 505, 'parent_service_id' => 501, 'whmcs_addon_id' => 71]);
        $addonOwn = $this->service($product, '2.00', '2024-01-01 09:00:00', null, ['id' => 506, 'parent_service_id' => 504, 'whmcs_addon_id' => 72]);
        DB::table('services')->whereIn('id', [501, 504, 505, 506])->update(['status' => Service::STATUS_TERMINATED, 'ended_at' => null]);
        DB::table('services')->where('id', 502)->update(['status' => Service::STATUS_CANCELLED, 'ended_at' => '2026-02-02 10:00:00']);

        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, termination_date TEXT)');
        $whmcs->exec("INSERT INTO tblhosting VALUES (501, '2025-07-14'), (502, '2025-01-01'), (503, '2025-03-03'), (504, '0000-00-00')");
        $whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, termination_date TEXT)');
        $whmcs->exec("INSERT INTO tblhostingaddons VALUES (71, '0000-00-00'), (72, '2025-08-01')");

        $this->assertSame(['services' => 1, 'addons' => 1, 'from_parent' => 1, 'unknown' => 1], (new WhmcsEndDates($whmcs))->run());
        $this->assertSame('2025-07-14 00:00:00', $ended->refresh()->ended_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-02', $mine->refresh()->ended_at->toDateString(), 'MyCS\'s own end never moves');
        $this->assertNull($running->refresh()->ended_at, 'it runs');
        $this->assertNull($noDate->refresh()->ended_at, 'WHMCS kept none');
        $this->assertSame('2025-07-14', $addon->refresh()->ended_at->toDateString(), 'with its parent');
        $this->assertSame('2025-08-01', $addonOwn->refresh()->ended_at->toDateString());
        $this->assertSame(['services' => 0, 'addons' => 0, 'from_parent' => 0, 'unknown' => 1], (new WhmcsEndDates($whmcs))->run(), 're-runnable');

        // An older WHMCS with no such column: nothing, no error.
        $this->assertSame(1, (new WhmcsEndDates(new PDO('sqlite::memory:')))->run()['unknown']);
    }

    public function test_the_page_shows_the_months_and_exports(): void
    {
        $this->actingAs(Staff::factory()->withPermissions(['admin.clients.viewAny'])->create())->get(GrowthAndChurn::getUrl())->assertForbidden();
        $staff = Staff::factory()->withPermissions(['admin.reports.view'])->create();
        $product = $this->product('VPS S');
        $this->service($product, '30.00', '2026-04-04 09:00:00');
        $gone = $this->service($product, '12.00', '2026-02-02 09:00:00');
        $this->endAt($gone, '2026-08-08 08:00:00');

        $this->actingAs($staff)->get(GrowthAndChurn::getUrl())->assertOk()->assertSee(__('Growth and churn'));
        $this->livewireAs($staff)->test(GrowthAndChurn::class)
            ->assertSee('VPS S')
            ->assertSee(__('Terminated, no cancellation request (mostly unpaid)'))
            ->set('year', 1999)->assertSet('year', 2026)
            ->callAction('csv')->assertFileDownloaded('growth-and-churn-EUR-2026-2026-11-17.csv');
    }
}
