<?php

namespace Tests\Feature;

use App\Admin\Pages\CronStats;
use App\Admin\Pages\Dashboard;
use App\Admin\Widgets\CronStat\CronCalendar;
use App\Admin\Widgets\CronToday;
use App\Models\ChargeAttempt;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Service\DunningForecast;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * What the cron will suspend and terminate in the days to come (owner 2026-11-06): the forecast names the day the cron
 * really does it, as the cron's own rules (DunningRules) are asked day by day.
 */
class DunningForecastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_terminate_min_suspended_days' => 1]);
        Carbon::setTestNow(Carbon::parse('2026-11-11 09:00')); // a Wednesday
    }

    private function service(int $expiredDaysAgo, array $extra = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create([
            'client_id' => $extra['client_id'] ?? User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => now()->startOfDay()->subDays($expiredDaysAgo)->addHours(12), 'currency_code' => 'USD', 'price' => 10.00,
        ] + $extra);
    }

    public function test_the_forecast_names_the_day_the_cron_suspends_and_terminates(): void
    {
        $tomorrow = $this->service(1); // past due by 2 days only after tomorrow's
        $now = $this->service(5);
        $held = $this->service(5, ['suspend_hold_until' => '9999-12-31']);
        $later = $this->service(-3); // not due yet: suspended in 6 days

        $forecast = DunningForecast::compute(Carbon::today());
        $this->assertSame([$now->id], $forecast['now']['suspend']);
        $predicted = [];
        foreach ($forecast['days'] as $day => $lists) {
            foreach ($lists['suspend'] as $id) {
                $predicted["suspend {$id}"] = $day;
            }
            foreach ($lists['terminate'] as $id) {
                $predicted["terminate {$id}"] = $day;
            }
        }
        $this->assertArrayNotHasKey("suspend {$held->id}", $predicted, 'a hold: never');

        // The cron, day by day: each suspension and termination on the day foretold.
        Queue::fake();
        $seen = ["suspend {$now->id}" => Carbon::today()->toDateString()];
        for ($d = 0; $d <= DunningForecast::DAYS; $d++) {
            $this->artisan('app:cron-job')->assertExitCode(0);
            foreach ([$tomorrow, $now, $later] as $service) {
                $status = $service->fresh()->status;
                if ($status === Service::STATUS_SUSPENDED) {
                    $seen["suspend {$service->id}"] ??= Carbon::today()->toDateString();
                }
                if ($status === Service::STATUS_TERMINATED) {
                    $seen["terminate {$service->id}"] ??= Carbon::today()->toDateString();
                }
            }
            Carbon::setTestNow(now()->addDay());
        }
        $this->assertSame('2026-11-13', $predicted["suspend {$tomorrow->id}"]);
        foreach ($predicted as $what => $day) {
            $this->assertSame($day, $seen[$what] ?? 'never', "{$what}: foretold for {$day}");
        }
        foreach ($seen as $what => $day) {
            if ($what !== "suspend {$now->id}") {
                $this->assertSame($day, $predicted[$what] ?? 'not foretold', "{$what}: done on {$day}");
            }
        }
        $this->assertSame(Service::STATUS_ACTIVE, $held->fresh()->status);
    }

    /** myip's rehearsal (2026-11-06): an addon not due itself is suspended with its parent, the day its parent is. */
    public function test_an_addon_is_on_its_parents_day(): void
    {
        $parent = $this->service(1);
        $addon = $this->service(-10, ['parent_service_id' => $parent->id, 'client_id' => $parent->client_id]);

        $forecast = DunningForecast::compute(Carbon::today());

        $this->assertSame([$parent->id, $addon->id], $forecast['days']['2026-11-13']['suspend']);
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-13 09:00'));
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_SUSPENDED, $addon->fresh()->status, 'the cron does it that day');
    }

    public function test_a_day_out_of_the_dunning_window_moves_to_its_next_open_day(): void
    {
        config(['settings.cronjob_dunning_days' => [1, 2, 3, 4, 5]]);
        Carbon::setTestNow(Carbon::parse('2026-11-13 09:00')); // a Friday
        $saturday = $this->service(2); // qualifies on Saturday the 14th

        $forecast = DunningForecast::compute(Carbon::today());

        $this->assertSame([$saturday->id], $forecast['days']['2026-11-16']['suspend'] ?? null, 'Monday, not Saturday');
        $this->assertArrayNotHasKey('2026-11-14', $forecast['days']);
    }

    public function test_the_safety_net_lists_what_waits_and_the_pages_show_it(): void
    {
        config(['settings.cronjob_suspend_enabled' => false]);
        $waiting = $this->service(5);
        $tomorrow = $this->service(2);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Staff ' . uniqid(), 'permissions' => ['*']])->id]);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_ACTIVE, $waiting->fresh()->status, 'the net: nothing suspended');

        $this->livewireAs($staff)->test(CronCalendar::class)
            ->assertSee('Safety net on')->assertSee('Waiting to suspend: held by the safety net')->assertSee('#' . $waiting->id)
            ->call('pick', '2026-11-12')->assertSee('#' . $tomorrow->id)->assertDontSee('#' . $waiting->id . ' ·')
            ->call('pick', 'nonsense')->assertSet('day', '2026-11-12')
            ->call('nextMonth')->assertSet('month', '2026-12');
        $this->livewireAs($staff)->test(CronToday::class)->assertSee('Waiting (safety net)')->assertSee('1 to suspend');
        $this->assertContains(CronToday::class, Dashboard::RIGHT);
        $this->assertNotNull(CronStats::getUrl());
    }

    /** Code review of #681: the net on, no termination foretold for what is never suspended; the switches read fresh. */
    public function test_with_suspensions_off_nothing_is_foretold_terminated_and_switching_back_is_seen_at_once(): void
    {
        config(['settings.cronjob_suspend_enabled' => false]);
        $long = $this->service(20);

        $off = DunningForecast::upcoming(Carbon::today());
        $this->assertSame([$long->id], $off['now']['suspend']);
        $this->assertSame([], array_merge(...array_values(array_column($off['days'], 'terminate'))), 'never suspended, never terminated');
        $this->assertFalse($off['suspend_on']);

        config(['settings.cronjob_suspend_enabled' => true]);
        $on = DunningForecast::upcoming(Carbon::today());
        $this->assertTrue($on['suspend_on'], 'not ten minutes of a cached «safety net on»');
        $this->assertNotSame([], array_merge(...array_values(array_column($on['days'], 'terminate'))));
    }

    /** Code review of #681: a day the cron did not run says so, even with a declined checkout that day. */
    public function test_a_day_without_a_run_says_so_on_the_dashboard(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Staff ' . uniqid(), 'permissions' => ['*']])->id]);
        $clientId = User::factory()->create()->client()->id;
        $invoice = Invoice::factory()->create(['client_id' => $clientId]);
        ChargeAttempt::query()->forceCreate(['key' => 'k' . uniqid(), 'invoice_id' => $invoice->id, 'client_id' => $clientId, 'amount' => '5.00',
            'currency_code' => 'EUR', 'source' => ChargeAttempt::SOURCE_CLIENT, 'status' => ChargeAttempt::STATUS_FAILED, 'started_at' => now()->subDay(), 'created_at' => now()->subDay()]);

        $this->livewireAs($staff)->test(CronToday::class)->assertSee('the cron did not run');
    }
}
