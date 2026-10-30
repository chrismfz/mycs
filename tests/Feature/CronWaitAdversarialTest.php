<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Models\CronStat;
use App\Models\Setting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Adversarial review of the deploy's wait for a cron run (app:cron-wait, deploy/update.sh, sweep 9γ).
 *
 * test_bug_*: a scenario where a cron run still ends up running over the deploy (or the deploy has no way out).
 * test_holds_*: guarantees checked and found to hold.
 *
 * The update.sh tests run a copy of the script in a throwaway directory (as DeployProductionGuardTest does) with a fake
 * git and a fake `php artisan` that logs each call, so the order of the waits, `down`, `up` and the queue stop is read
 * from the log. Nothing real is deployed.
 */
class CronWaitAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->app->maintenanceMode()->active()) {
            $this->app->maintenanceMode()->deactivate();
        }
        if ($this->dir !== null) {
            File::deleteDirectory($this->dir);
        }
        Sleep::fake(false);
        parent::tearDown();
    }

    private function dailyRunHappened(): bool
    {
        return Setting::query()->where('key', 'last_cron_run')->where('settingable_type', CronStat::class)->exists();
    }

    // ---------------------------------------------------------------------------------------------------------------
    // CronJob vs maintenance mode
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * ADV-CW-1. schedule:run reads «is the app down?» once, in dueEvents(), then runs its due events one after another
     * (the heartbeat first, then CronJob, each a new `php artisan` process). A deploy whose `down` lands between the two
     * finds the lock free at its second wait (the cron's process has not booted yet), goes on to the migrations, and the
     * cron then takes the lock and runs its whole day in maintenance, over the migrating database. CronJob itself never
     * looks at maintenance mode: the run below, started while the app is down, does its day.
     */
    public function test_bug_a_cron_run_the_scheduler_launched_just_before_down_runs_its_day_in_maintenance(): void
    {
        // The deploy: down, then the second wait finds no run (the cron's process is still booting).
        $this->app->maintenanceMode()->activate([]);
        $this->artisan('app:cron-wait', ['--timeout' => 0])->assertExitCode(0);

        // The cron's process, launched by the scheduler that decided before `down`, now takes the lock.
        Artisan::call('app:cron-job');

        $this->assertFalse($this->dailyRunHappened(), 'a cron run that gets the lock while the app is down must not do its day: the deploy is migrating under it');
    }

    /**
     * ADV-CW-2. app:cron-wait takes the lock and releases it at once: it lets in whoever is blocked on it. A daily run
     * that found the hourly run holding the lock waits for it (CronJob::$lockWait, 600 s, block() polling every 250 ms).
     * The deploy's second wait waits on the same hourly run; when it ends, if the deploy wins the poll it releases at
     * once and goes on, and the daily run takes the lock the next poll, a fraction of a second later, and runs its
     * whole day during the snapshot, checkout and migrations. A check of maintenance mode at the start of CronJob would
     * not catch this run (it started before `down`): it has to come after the lock is taken, on both paths.
     */
    public function test_bug_a_daily_run_blocked_on_the_hourly_one_slips_in_after_the_deploys_wait(): void
    {
        $hourly = Cache::lock('app:cron-job', 7200);
        $this->assertTrue($hourly->get(), 'precondition: the hourly run holds the lock');
        CronJob::$lockWait = 600;

        $deployWaitExit = null;
        Sleep::fake();
        Sleep::whenFakingSleep(function () use ($hourly, &$deployWaitExit): void {
            if ($deployWaitExit !== null) {
                return;
            }
            // While the daily run sleeps between polls: the deploy has gone down, the hourly run ends, and the deploy's
            // second wait wins the poll (taken and released at once) and returns 0: the deploy goes on.
            $this->app->maintenanceMode()->activate([]);
            $hourly->release();
            $deployWaitExit = Artisan::call('app:cron-wait', ['--timeout' => 0]);
        });

        // The daily run, started before the deploy (not in maintenance then), blocked on the hourly run.
        Artisan::call('app:cron-job');

        $this->assertSame(0, $deployWaitExit, 'precondition: the deploy\'s wait let it through');
        $this->assertFalse($this->dailyRunHappened(), 'the daily run that was waiting took the lock after the deploy\'s wait and ran its day in maintenance');
    }

    /**
     * ADV-CW-3. A run killed mid-way (OOM, reboot, a fatal error: `finally` does not run) leaves the lock for up to two
     * hours (7200 s TTL; the file store keeps it across a reboot). Every deploy in those two hours stops after 15 min,
     * update.sh says «A cron run is still in progress» (there is none), and neither the command, the script nor
     * docs/install.md says how to tell a stale lock from a live run or how to clear it (cache:clear does not reach a
     * Redis lock_connection; with the file store it would wipe the whole cache). An urgent fix waits two hours.
     */
    public function test_bug_a_crashed_runs_lock_blocks_every_deploy_with_no_way_out_named(): void
    {
        // The lock of a run that was killed: nobody will release it.
        $this->assertTrue(Cache::lock('app:cron-job', 7200)->get());

        $exit = Artisan::call('app:cron-wait', ['--timeout' => 0]);
        $said = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertMatchesRegularExpression(
            '/forceRelease|--release|--force|stale/i',
            $said,
            'app:cron-wait names no way to clear a stale cron lock (nor do update.sh or install.md): every deploy is stuck up to 2 h',
        );
    }

    // ---------------------------------------------------------------------------------------------------------------
    // app:cron-wait itself
    // ---------------------------------------------------------------------------------------------------------------

    /** Owner tokens: a wait that finds the cron running never releases the cron's lock, on time-out or otherwise. */
    public function test_holds_the_wait_never_releases_the_crons_own_lock(): void
    {
        $run = Cache::lock('app:cron-job', 7200);
        $this->assertTrue($run->get());

        $this->artisan('app:cron-wait', ['--timeout' => 0])->assertExitCode(1);
        $this->artisan('app:cron-wait', ['--timeout' => 1])->assertExitCode(1);

        $this->assertFalse(Cache::lock('app:cron-job', 10)->get(), 'the cron\'s lock is still its own');
        $this->assertTrue($run->release(), 'and the cron releases it itself');
    }

    /** The wait and the cron use the same store and key: what CronJob takes is what the wait sees, and vice versa. */
    public function test_holds_the_wait_and_the_cron_share_the_lock(): void
    {
        $this->assertTrue(Cache::lock('app:cron-job', 7200)->get(), 'a run in progress');

        // A daily run then finds it held (and, with $lockWait 0, gives up its day and says so).
        $this->assertSame(1, Artisan::call('app:cron-job'));
        $this->assertFalse($this->dailyRunHappened());
        $this->assertSame(1, Artisan::call('app:cron-wait', ['--timeout' => 0]));
    }

    /** The wait returns at once when nothing runs and leaves no lock behind (the next cron is not refused). */
    public function test_holds_after_a_free_wait_the_hourly_run_is_not_turned_away(): void
    {
        $this->artisan('app:cron-wait', ['--timeout' => 0])->assertExitCode(0);
        $this->artisan('app:cron-wait', ['--timeout' => -5])->assertExitCode(0);

        $this->assertTrue(Cache::lock('app:cron-job', 7200)->get(), 'free again');
    }

    /** The scheduler starts neither CronJob event in maintenance (no evenInMaintenanceMode on them). */
    public function test_holds_the_scheduler_starts_no_cron_run_in_maintenance(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'app:cron-job'));
        $this->assertCount(2, $events, 'the daily and the hourly run');

        foreach ($events as $event) {
            $this->assertFalse($event->runsInMaintenanceMode(), $event->command);
        }
        $this->app->maintenanceMode()->activate([]);
        foreach ($events as $event) {
            $this->assertFalse($event->isDue($this->app), $event->command . ' is never due while the app is down');
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // deploy/update.sh, in a throwaway directory
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Runs update.sh to its checkout (a fake git stops it there with 98) with a fake artisan. $waits: the exit codes of
     * the successive app:cron-wait calls. Returns [exit code, output, artisan/queue log lines].
     *
     * @param  list<int>  $waits
     * @return array{int, string, list<string>}
     */
    private function deploy(array $waits, bool $hasCommand = true, bool $alreadyDown = false): array
    {
        $this->dir = sys_get_temp_dir() . '/mycs-cronwait-adv-' . uniqid();
        $d = $this->dir;
        File::makeDirectory($d . '/deploy', 0700, true);
        File::makeDirectory($d . '/bin');
        File::makeDirectory($d . '/storage/framework', 0700, true);
        File::copy(base_path('deploy/update.sh'), $d . '/deploy/update.sh');
        File::put($d . '/artisan', '');
        File::put($d . '/.env', "APP_ENV=production\nAPP_DEBUG=false\n");
        symlink(base_path('vendor'), $d . '/vendor');
        File::put($d . '/waits', implode("\n", $waits) . "\n");
        if ($alreadyDown) {
            File::put($d . '/storage/framework/down', '{}');
        }

        File::put($d . '/bin/git', <<<'SH'
            #!/bin/bash
            case "$1" in
              status|fetch|update-index|read-tree|ls-files) exit 0 ;;
              symbolic-ref) echo main ;;
              rev-parse)
                if [[ "$*" == *HEAD* ]]; then echo aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa; else echo bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb; fi ;;
              merge-base) exit 1 ;;
              checkout) echo CHECKOUT >> "$LOG"; exit 98 ;;
            esac
            exit 0
            SH);
        $list = $hasCommand ? "app:cron-job\napp:cron-wait\n" : "app:cron-job\n";
        File::put($d . '/bin/php', <<<SH
            #!/bin/bash
            if [[ "\$1" != artisan ]]; then exec {$this->php()} "\$@"; fi
            shift
            echo "ART \$*" >> "\$LOG"
            case "\$1" in
              list) printf '{$this->escape($list)}' ;;
              app:cron-wait) n=\$(head -1 waits); sed -i 1d waits; exit "\${n:-0}" ;;
              down) echo '{}' > storage/framework/down ;;
              up) rm -f storage/framework/down ;;
            esac
            exit 0
            SH);
        chmod($d . '/bin/git', 0700);
        chmod($d . '/bin/php', 0700);

        $log = $d . '/log';
        exec('cd ' . escapeshellarg($d) . ' && PATH=' . escapeshellarg($d . '/bin:' . getenv('PATH'))
            . ' env -u COMPOSER_DEV -u ALLOW_DEV_IN_PRODUCTION -u APP_DIR -u MYCS_UPDATE_COPY -u APP_ENV -u APP_DEBUG -u CRON_WAIT_SECONDS'
            . ' LOG=' . escapeshellarg($log) . ' PHP=php SKIP_SNAPSHOT=1'
            . ' QUEUE_STOP_CMD=' . escapeshellarg('echo QSTOP >> "$LOG"') . ' QUEUE_START_CMD=' . escapeshellarg('echo QSTART >> "$LOG"')
            . ' bash deploy/update.sh 2>&1 < /dev/null', $out, $code);

        $lines = File::exists($log) ? array_values(array_filter(explode("\n", (string) File::get($log)))) : [];

        return [$code, implode("\n", $out), $lines];
    }

    private function php(): string
    {
        return escapeshellarg(PHP_BINARY);
    }

    private function escape(string $s): string
    {
        return str_replace("\n", '\n', $s);
    }

    /** @param list<string> $log */
    private function steps(array $log): array
    {
        return array_values(array_filter(array_map(fn ($l) => match (true) {
            str_starts_with($l, 'ART app:cron-wait') => 'wait',
            str_starts_with($l, 'ART down') => 'down',
            str_starts_with($l, 'ART up') => 'up',
            $l === 'QSTOP', $l === 'QSTART', $l === 'CHECKOUT' => $l,
            default => null,
        }, $log)));
    }

    public function test_holds_the_deploy_waits_before_down_and_after_then_stops_the_queue(): void
    {
        [$code, $out, $log] = $this->deploy([0, 0]);

        $this->assertSame(98, $code, $out);
        $this->assertSame(['wait', 'down', 'wait', 'QSTOP', 'CHECKOUT', 'QSTART'], $this->steps($log), $out);
        $this->assertContains('ART app:cron-wait --timeout=900', $log);
    }

    public function test_holds_a_failed_first_wait_changes_nothing(): void
    {
        [$code, $out, $log] = $this->deploy([1]);

        $this->assertSame(1, $code, $out);
        $this->assertSame(['wait'], $this->steps($log), $out);
        $this->assertStringContainsString('nothing changed', $out);
        $this->assertStringNotContainsString('Update FAILED', $out, 'no ERR trap fired');
    }

    /** The queue worker is stopped only after the second wait: a failed one brings the site up and the worker never stopped. */
    public function test_holds_a_failed_second_wait_brings_the_site_up_and_never_touches_the_queue(): void
    {
        [$code, $out, $log] = $this->deploy([0, 1]);

        $this->assertSame(1, $code, $out);
        $this->assertSame(['wait', 'down', 'wait', 'up'], $this->steps($log), $out);
        $this->assertFileDoesNotExist($this->dir . '/storage/framework/down');
        $this->assertStringNotContainsString('Update FAILED', $out, 'no ERR trap fired');
    }

    /** First deploy of the command: the old release has no app:cron-wait, the waits are skipped, the deploy goes on. */
    public function test_holds_a_release_without_the_command_skips_the_waits(): void
    {
        [$code, $out, $log] = $this->deploy([], hasCommand: false);

        $this->assertSame(98, $code, $out);
        $this->assertSame(['down', 'QSTOP', 'CHECKOUT', 'QSTART'], $this->steps($log), $out);
    }

    /**
     * ADV-CW-4. A deploy that failed half-way leaves the app in maintenance on a half-installed release and says «Fix the
     * error, then re-run». If the re-run's second wait fails, the script runs `artisan up` and says «the app is up
     * again»: it brings up the broken release it found down. It should leave maintenance as it found it.
     */
    public function test_bug_a_failed_second_wait_brings_up_a_site_that_was_already_down(): void
    {
        [$code, $out, $log] = $this->deploy([0, 1], alreadyDown: true);

        $this->assertSame(1, $code, $out);
        $this->assertFileExists($this->dir . '/storage/framework/down', 'the app was down before the deploy (a failed one): it stays down');
        $this->assertNotContains('up', $this->steps($log), $out);
    }
}
