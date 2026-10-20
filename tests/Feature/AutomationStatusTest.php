<?php

namespace Tests\Feature;

use App\Admin\Pages\CronStats;
use App\Admin\Widgets\CronStat\HealthChecks;
use App\Models\CronStat;
use App\Models\Setting;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $this->ran('last_cron_run', now()->toDateTimeString());
        DB::table('failed_jobs')->delete();
        $this->assertNotSame(__('Failing'), CronStats::getNavigationBadge());
    }

    public function test_staff_without_the_permission_do_not_see_it(): void
    {
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny'])->create();

        $this->actingAs($staff)->get(CronStats::getUrl())->assertForbidden();
    }
}
