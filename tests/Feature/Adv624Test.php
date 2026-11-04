<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Models\CronStat;
use App\Models\EmailLog;
use App\Models\Setting;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Adversarial review of #624 (the hourly catch-up of a daily run that did not start). Each test fails on the PR head.
 */
class Adv624Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.cronjob_time' => '09:00',
            'settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::lock('app:cron-job')->forceRelease();
        parent::tearDown();
    }

    private function stat(string $key, ?string $at): void
    {
        Setting::query()->where('key', $key)->where('settingable_type', CronStat::class)->delete();
        if ($at !== null) {
            Setting::query()->create(['key' => $key, 'settingable_type' => CronStat::class, 'value' => $at, 'type' => 'string']);
        }
    }

    private function ranAt(): ?string
    {
        return Setting::query()->where('key', 'last_cron_run')->where('settingable_type', CronStat::class)->value('value');
    }

    private function lateMails(): int
    {
        return EmailLog::query()->where('subject', 'Cron Job: the daily run started late')->count();
    }

    /**
     * ADV-624-1: the catch-up checks maintenance once, before it takes the lock, and never again under it. The daily path
     * checks under the lock (inMaintenance(), ADV-CW-1/2) because a deploy runs `down` and then app:cron-wait, which takes
     * and releases the same lock: a run whose maintenance check came just before `down` but whose lock came just after
     * that second wait goes on over the migrations. Here the app goes down right after the catch-up's own check; under the
     * lock it is down, and the catch-up still runs the day's invoices and charges.
     */
    public function test_adv_624_1_catch_up_rechecks_maintenance_under_the_lock(): void
    {
        $this->stat('last_cron_run', '2026-11-03 09:00:40');
        Carbon::setTestNow('2026-11-04 10:35:00');
        $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            private int $calls = 0;

            public function activate(array $payload): void {}

            public function deactivate(): void {}

            // Up at the catch-up's first check, down from then on (the deploy's `down` and its second cron_wait landed in
            // between).
            public function active(): bool
            {
                return $this->calls++ > 0;
            }

            public function data(): array
            {
                return [];
            }
        });

        Artisan::call('app:cron-job', ['--catch-up' => true]);

        $this->assertSame('2026-11-03 09:00:40', $this->ranAt(), 'no daily run while the app is down (under the lock)');
        $this->assertFalse(CronStat::query()->where('key', 'invoices_created')->exists(), 'no renewal invoices over a migrating database');
        $this->assertSame(0, $this->lateMails());
    }

    /**
     * ADV-624-2: today's run started at 09:00 and got past its reminders (cron_dunning_ready_at 09:40, so it surely
     * started), but has not written last_cron_run: it is still in its last steps (suspensions, pruning) after two hours,
     * or it was killed there. Its lock (7200 s) lapsed at 11:00. At 11:35 the catch-up reads «no run of today ended»,
     * takes the free lock and starts a second daily run of the same day: concurrently with the first if it is alive (the
     * overlap BIL-16 forbade: orders cancelled and stock given back twice), or a full automatic re-run of a day that did
     * start, which the catch-up is not for («a daily run that did not start»).
     */
    public function test_adv_624_2_a_run_of_today_that_started_is_not_started_again_when_its_lock_lapses(): void
    {
        $this->stat('last_cron_run', '2026-11-03 09:00:40');
        Carbon::setTestNow('2026-11-04 09:00:05');
        $this->assertTrue(Cache::lock('app:cron-job', 7200)->get(), 'today\'s run takes the lock at 09:00');
        Carbon::setTestNow('2026-11-04 09:40:00');
        $this->stat(CronJob::DUNNING_READY, '2026-11-04 09:40:00');

        Carbon::setTestNow('2026-11-04 11:35:00');
        Artisan::call('app:cron-job', ['--catch-up' => true]);

        $this->assertSame('2026-11-03 09:00:40', $this->ranAt(), 'today\'s run started: not started a second time');
        $this->assertSame(0, $this->lateMails(), 'staff not told it «did not start»: it did');
    }

    /**
     * ADV-624-3: «never when a run of today already ended» does not hold once the time is moved later in the day. Today's
     * run ended at 09:00:40; at 15:00 staff move cronjob_time to 14:00, meaning tomorrow (the scheduler will not run a
     * minute already past). ranSince() compares with today's new time, 14:00, so at 15:35 the catch-up starts a second
     * daily run of the day, mails staff that today's run «did not start», and doubles the day's counts.
     */
    public function test_adv_624_3_a_day_whose_run_ended_is_not_run_again_when_the_time_moves_later(): void
    {
        $this->stat('last_cron_run', '2026-11-04 09:00:40');
        config(['settings.cronjob_time' => '14:00']);
        Carbon::setTestNow('2026-11-04 15:35:00');

        Artisan::call('app:cron-job', ['--catch-up' => true]);

        $this->assertSame('2026-11-04 09:00:40', $this->ranAt(), 'today\'s run already ended at 09:00');
        $this->assertSame(0, $this->lateMails());
    }
}
