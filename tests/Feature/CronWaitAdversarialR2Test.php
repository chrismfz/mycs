<?php

namespace Tests\Feature;

use App\Models\CronStat;
use App\Models\EmailLog;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Round-2 adversarial review of #556 (app:cron-wait, CronJob::inMaintenance, deploy/update.sh).
 *
 * test_bug_*: a scenario that still goes wrong. test_holds_*: guarantees checked and found to hold.
 */
class CronWaitAdversarialR2Test extends TestCase
{
    use RefreshDatabase;

    private ?string $dir = null;

    /** @var list<resource> */
    private array $procs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) {
            proc_terminate($p, 9);
            proc_close($p);
        }
        if ($this->app->maintenanceMode()->active()) {
            $this->app->maintenanceMode()->deactivate();
        }
        if ($this->dir !== null) {
            File::deleteDirectory($this->dir);
        }
        Carbon::setTestNow();
        Cache::lock('app:cron-job')->forceRelease();
        parent::tearDown();
    }

    private function dailyRunHappened(): bool
    {
        return Setting::query()->where('key', 'last_cron_run')->where('settingable_type', CronStat::class)->exists();
    }

    private function staffToldOfTheDailyRun(): bool
    {
        return EmailLog::query()->where('subject', 'like', '%daily run%')->exists();
    }

    /** A real cron of this host's live site (same pattern as CronWait::cronAlive) would make a release-stale test moot. */
    private function aRealCronRunsOnThisHost(): bool
    {
        // The same pattern as CronWait::cronAlive (round 2 of #556): a parallel test's `php artisan -v app:cron-job` too.
        exec('pgrep -f ' . escapeshellarg('[a]rtisan( .*)? app:cron-j'), $out, $code);

        return $code !== 1;
    }

    /** A process whose command line reads $title (as `ps` and `pgrep -f` see it), alive for 30 s. */
    private function spawn(string $title): void
    {
        $code = 'cli_set_process_title(' . var_export($title, true) . '); sleep(30);';
        $p = proc_open([PHP_BINARY, '-r', $code], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($p);
        $this->procs[] = $p;
        // Wait for the title to show.
        for ($i = 0; $i < 40; $i++) {
            exec('pgrep -f ' . escapeshellarg(preg_quote($title, null)), $o, $c);
            if ($c === 0) {
                return;
            }
            usleep(50_000);
        }
        $this->fail('the fake process never showed its title');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // CronJob in maintenance
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * ADV-CW-R2-1. The PR's mail («the daily run did not start», inMaintenance) only reaches the narrow race where the
     * cron took the lock while the app was down. The common case is a deploy that spans the daily time (cronjob_time,
     * 09:00): schedule:run at that minute sees maintenance and skips the daily event without a word (no
     * evenInMaintenanceMode), the deploy brings the app up a few minutes later, and the day's invoices, charges,
     * reminders and suspensions (the hourly run waits on the daily one) are simply not done. Health only turns WARN
     * when the last run is >25 h old. Staff should be told, as for the race.
     */
    public function test_bug_a_daily_run_due_during_a_deploy_is_skipped_and_nobody_is_told(): void
    {
        Carbon::setTestNow(Carbon::today()->setTimeFromTimeString((string) config('settings.cronjob_time', '09:00')));
        $this->app->maintenanceMode()->activate([]);

        Artisan::call('schedule:run');

        $this->assertFalse($this->dailyRunHappened(), 'precondition: no daily run in maintenance');
        $this->assertTrue($this->staffToldOfTheDailyRun(), 'the daily run fell inside a deploy and was skipped silently: staff are not told to run it');
    }

    /** Console has no maintenance bypass: `down --secret` (staff previewing the release) still stops the cron. */
    public function test_holds_a_maintenance_secret_does_not_let_the_cron_run(): void
    {
        $this->artisan('down', ['--secret' => 'preview-token'])->assertExitCode(0);

        $this->assertSame(1, Artisan::call('app:cron-job'));
        $this->assertFalse($this->dailyRunHappened());
    }

    /** Staff running it by hand in maintenance: refused, says why, the staff mail sent; the lock left free. */
    public function test_holds_a_manual_run_in_maintenance_does_nothing_and_says_why(): void
    {
        $this->app->maintenanceMode()->activate([]);

        $exit = Artisan::call('app:cron-job');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('maintenance', Artisan::output());
        $this->assertFalse($this->dailyRunHappened());
        $this->assertTrue($this->staffToldOfTheDailyRun());
        $this->assertTrue(Cache::lock('app:cron-job', 10)->get(), 'the lock is released');
    }

    /** The hourly run in maintenance: nothing done, no staff mail (the next hour does it). */
    public function test_holds_the_hourly_run_in_maintenance_is_silent(): void
    {
        config(['settings.cronjob_dunning_from' => '08:00']); // not «always»: the hourly run gets to its lock
        $this->app->maintenanceMode()->activate([]);

        Artisan::call('app:cron-job', ['--dunning-only' => true]);

        $this->assertFalse($this->staffToldOfTheDailyRun());
        $this->assertTrue(Cache::lock('app:cron-job', 10)->get(), 'the lock is released');
    }

    /** tests/TestCase.php: maintenance in a test is this process's alone, no storage/framework/down left for the others. */
    public function test_holds_maintenance_in_a_test_writes_no_down_file(): void
    {
        $existed = is_file(storage_path('framework/down'));
        $this->app->maintenanceMode()->activate([]);
        $this->assertTrue(app()->isDownForMaintenance());
        if (!$existed) {
            $this->assertFileDoesNotExist(storage_path('framework/down'));
        }
        $this->app->maintenanceMode()->deactivate();
        $this->assertFalse(app()->isDownForMaintenance());
    }

    // ---------------------------------------------------------------------------------------------------------------
    // app:cron-wait --release-stale
    // ---------------------------------------------------------------------------------------------------------------

    public function test_holds_release_stale_clears_a_crashed_runs_lock(): void
    {
        if ($this->aRealCronRunsOnThisHost()) {
            $this->markTestSkipped('a real app:cron-job runs on this host right now');
        }
        $this->assertTrue(Cache::lock('app:cron-job', 7200)->get(), 'a crashed run\'s lock');

        $this->assertSame(0, Artisan::call('app:cron-wait', ['--release-stale' => true]));
        $this->assertTrue(Cache::lock('app:cron-job', 10)->get(), 'cleared');
    }

    public function test_holds_release_stale_refuses_while_a_cron_process_runs(): void
    {
        $this->assertTrue(Cache::lock('app:cron-job', 7200)->get(), 'the live run\'s lock');
        $this->spawn('php artisan app:cron-job');

        $this->assertSame(1, Artisan::call('app:cron-wait', ['--release-stale' => true]));
        $this->assertFalse(Cache::lock('app:cron-job', 10)->get(), 'the live run keeps its lock');
    }

    /** No pgrep (or it cannot run): unknown counts as alive, nothing cleared. */
    public function test_holds_release_stale_without_pgrep_clears_nothing(): void
    {
        $this->assertTrue(Cache::lock('app:cron-job', 7200)->get());
        $path = getenv('PATH');
        putenv('PATH=/nonexistent');
        try {
            $exit = Artisan::call('app:cron-wait', ['--release-stale' => true]);
        } finally {
            putenv('PATH=' . $path);
        }

        $this->assertSame(1, $exit);
        $this->assertFalse(Cache::lock('app:cron-job', 10)->get());
    }

    /**
     * ADV-CW-R2-3. cronAlive() looks for the literal «artisan app:cron-job». A run started with an option before the
     * command (`php artisan -v app:cron-job`, `php artisan --env=production app:cron-job`, a staff habit) or by an
     * abbreviation Symfony accepts (`php artisan app:cron-j`) is not seen: --release-stale clears the live run's lock,
     * and the deploy that follows migrates under it, the very thing the command exists to prevent.
     */
    public function test_bug_release_stale_misses_a_live_run_started_with_an_option_first(): void
    {
        $this->assertTrue(Cache::lock('app:cron-job', 7200)->get(), 'the live run\'s lock');
        $this->spawn('php artisan -v app:cron-job');

        Artisan::call('app:cron-wait', ['--release-stale' => true]);

        $this->assertFalse(Cache::lock('app:cron-job', 10)->get(), 'a live cron run (artisan -v app:cron-job) lost its lock to --release-stale');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // deploy/update.sh, in a throwaway directory (as CronWaitAdversarialTest)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @param  list<int>  $waits  exit codes of the successive app:cron-wait calls
     * @return array{int, string, list<string>}
     */
    private function deploy(array $waits, bool $downFails = false, string $driver = 'file', bool $alreadyDown = false): array
    {
        $this->dir = sys_get_temp_dir() . '/mycs-cronwait-adv2-' . uniqid();
        $d = $this->dir;
        File::makeDirectory($d . '/deploy', 0700, true);
        File::makeDirectory($d . '/bin');
        File::makeDirectory($d . '/storage/framework', 0700, true);
        File::copy(base_path('deploy/update.sh'), $d . '/deploy/update.sh');
        File::put($d . '/artisan', '');
        File::put($d . '/.env', "APP_ENV=production\nAPP_DEBUG=false\nAPP_MAINTENANCE_DRIVER={$driver}\n");
        symlink(base_path('vendor'), $d . '/vendor');
        File::put($d . '/waits', implode("\n", $waits) . "\n");
        // Where the fake artisan keeps «down»: the file driver's storage/framework/down, or the cache store's key.
        $marker = $driver === 'file' ? 'storage/framework/down' : 'cache-down';
        if ($alreadyDown) {
            File::put($d . '/' . $marker, '{}');
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
        $down = $downFails ? 'echo "Failed to enter maintenance mode" >&2; exit 1' : "echo '{}' > {$marker}";
        $php = escapeshellarg(PHP_BINARY);
        File::put($d . '/bin/php', <<<SH
            #!/bin/bash
            if [[ "\$1" != artisan ]]; then exec {$php} "\$@"; fi
            shift
            echo "ART \$*" >> "\$LOG"
            case "\$1" in
              list) printf 'app:cron-job\\napp:cron-wait\\napp:is-down\\n' ;;
              app:is-down) [[ -f cache-down || -f storage/framework/down ]] && exit 0 || exit 1 ;;
              app:cron-wait) n=\$(head -1 waits); sed -i 1d waits; exit "\${n:-0}" ;;
              down) {$down} ;;
              up) rm -f {$marker} ;;
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

    /**
     * ADV-CW-R2-2. `$ART down --retry=15 || true`: a `down` that fails (storage not writable, a full disk, the
     * maintenance store unreachable) is ignored. The second wait then proves nothing, since the scheduler goes on starting
     * cron runs and CronJob::inMaintenance() sees the app up, and the deploy goes on to the checkout and the migrations
     * with the site live and the cron free to start under them. Nothing has changed yet at that point: it should stop.
     */
    public function test_bug_a_down_that_failed_lets_the_deploy_migrate_under_a_live_cron(): void
    {
        [$code, $out, $log] = $this->deploy([0, 0], downFails: true);

        $this->assertNotContains('CHECKOUT', $log, "the deploy went on to the checkout without maintenance mode:\n" . $out);
    }

    /**
     * ADV-CW-R2-4. `_was_down` reads storage/framework/down, the file driver's. With APP_MAINTENANCE_DRIVER=cache (the
     * driver config/app.php offers, .env.example names) the app is down with no such file: a re-run after a failed
     * deploy whose second wait fails runs `up` and brings the half-installed release up again (ADV-CW-4 back). Production
     * uses the file driver today (low), but the script should ask the app, not a path.
     */
    public function test_bug_was_down_reads_only_the_file_drivers_path(): void
    {
        [$code, $out, $log] = $this->deploy([0, 1], driver: 'cache', alreadyDown: true);

        $this->assertSame(1, $code, $out);
        $this->assertNotContains('ART up', $log, 'the app was down before the deploy (cache driver): it stays down');
    }

    /** A failed `down` and a failed second wait: `up` on an app that was never down is harmless, nothing changed. */
    public function test_holds_a_failed_down_and_failed_second_wait_change_nothing(): void
    {
        [$code, $out, $log] = $this->deploy([0, 1], downFails: true);

        $this->assertSame(1, $code, $out);
        $this->assertNotContains('CHECKOUT', $log);
        $this->assertNotContains('QSTOP', $log);
        $this->assertStringContainsString('nothing changed', $out);
    }
}
