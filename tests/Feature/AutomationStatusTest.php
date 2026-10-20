<?php

namespace Tests\Feature;

use App\Admin\Pages\CronStats;
use App\Admin\Resources\FailedJobResource;
use App\Admin\Widgets\CronStat\HealthChecks;
use App\Models\CronStat;
use App\Models\Setting;
use App\Models\Staff;
use App\Services\Ops\Health;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Automation Status (owner 2026-10-20, as WHMCS's): the whole state and each of Health's checks on the cron page, where
 * to look, and a menu badge when something is not ok; read at most once a minute.
 */
class AutomationStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function ran(string $key, string $when): void
    {
        Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => CronStat::class], ['value' => $when]);
    }

    public function test_the_page_shows_the_whole_state_and_each_check(): void
    {
        $this->ran('last_scheduler_run', now()->toDateTimeString());
        $this->ran('last_cron_run', now()->subHour()->toDateTimeString());
        $staff = Staff::factory()->withPermissions(['admin.cron_stats.view', 'admin.failed_jobs.viewAny'])->create();

        $this->actingAs($staff)->get(CronStats::getUrl())->assertOk()->assertSee('Automation Status');

        $this->livewireAs($staff)->test(HealthChecks::class)
            ->assertSee(__('health.scheduler'))->assertSee(__('health.queue'))->assertSee(__('health.licenses'))
            ->assertSee('Last run');
    }

    public function test_a_failing_check_is_said_in_the_menu_and_the_badge_is_read_once_a_minute(): void
    {
        $this->ran('last_scheduler_run', now()->subHour()->toDateTimeString()); // the scheduler stopped: fail
        $this->assertSame(__('Failing'), CronStats::getNavigationBadge());
        $this->assertSame('danger', CronStats::getNavigationBadgeColor());

        $this->ran('last_scheduler_run', now()->toDateTimeString());
        DB::enableQueryLog();
        CronStats::getNavigationBadge();
        $this->assertSame([], DB::getQueryLog(), 'the checks ran again within the minute');

        $this->travel(61)->seconds();
        $this->ran('last_scheduler_run', now()->toDateTimeString());
        $this->ran('last_cron_run', now()->toDateTimeString());
        $now = Health::report()['status'];
        $this->assertSame($now === Health::OK ? null : HealthChecks::label($now), CronStats::getNavigationBadge());
        $this->assertSame(Health::OK, Health::cached()['checks']['scheduler']['status'], 'read again after the minute');
    }

    public function test_the_page_reads_now_not_the_badges_minute(): void
    {
        $this->ran('last_scheduler_run', now()->toDateTimeString());
        Cache::put('ops.health', ['at' => now()->getTimestamp(), 'report' => ['status' => Health::FAIL,
            'checks' => ['scheduler' => ['status' => Health::FAIL, 'detail' => 'A stale minute']]]], 600);
        $staff = Staff::factory()->withPermissions(['admin.cron_stats.view'])->create();

        $this->livewireAs($staff)->test(HealthChecks::class)->assertDontSee('A stale minute')->assertSee('Last run');
        $this->assertSame(Health::OK, Health::cached()['checks']['scheduler']['status'], 'the badge kept the stale minute');
    }

    public function test_a_link_only_for_staff_who_may_open_it(): void
    {
        $url = FailedJobResource::getUrl('index');
        $without = Staff::factory()->withPermissions(['admin.cron_stats.view'])->create();
        $with = Staff::factory()->withPermissions(['admin.cron_stats.view', 'admin.failed_jobs.viewAny'])->create();

        $this->livewireAs($without)->test(HealthChecks::class)->assertDontSee($url);
        $this->livewireAs($with)->test(HealthChecks::class)->assertSee($url);
    }

    public function test_a_cache_that_fails_never_takes_a_page_down(): void
    {
        Cache::shouldReceive('get')->andThrow(new RuntimeException('cache unavailable'));

        $this->assertNull(CronStats::getNavigationBadge());
        $this->assertSame('success', CronStats::getNavigationBadgeColor());
    }

    public function test_labels_and_the_greek_title(): void
    {
        $this->assertSame([__('Attention'), 'warning'], [HealthChecks::label(Health::WARN), HealthChecks::color(Health::WARN)]);
        app()->setLocale('el');
        $this->assertSame('Κατάσταση αυτοματισμών', CronStats::getNavigationLabel());
        $this->assertSame('Εντάξει', HealthChecks::label(Health::OK));
    }

    public function test_staff_without_the_permission_do_not_see_it(): void
    {
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny'])->create();

        $this->actingAs($staff)->get(CronStats::getUrl())->assertForbidden();
    }
}
