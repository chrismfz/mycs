<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Models\EmailLog;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Round-3 adversarial review of #556 (the scheduled «daily run skipped» notice, `down` stopping the deploy, `_was_down`,
 * the looser pgrep pattern).
 *
 * test_bug_*: a scenario that still goes wrong. test_holds_*: guarantees checked and found to hold.
 */
class CronWaitAdversarialR3Test extends TestCase
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

    private function skippedMails(): int
    {
        return EmailLog::query()->where('subject', 'Cron Job: the daily run did not start')->count();
    }

    private function atCronTime(): void
    {
        Carbon::setTestNow(Carbon::today()->setTimeFromTimeString((string) config('settings.cronjob_time', '09:00')));
    }

    private function notice(): CallbackEvent
    {
        $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => $e instanceof CallbackEvent && $e->description === 'Tells staff when the daily run fell in maintenance');
        $this->assertInstanceOf(CallbackEvent::class, $event, 'the scheduled notice is registered');

        return $event;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The scheduled notice (routes/console.php)
    // ---------------------------------------------------------------------------------------------------------------

    /** It is due every day at the daily run's minute, but mails only when the app is down: an ordinary day is silent. */
    public function test_holds_the_notice_is_silent_on_an_ordinary_day(): void
    {
        $this->atCronTime();
        $this->assertTrue($this->notice()->isDue($this->app));
        $this->assertTrue($this->notice()->runsInMaintenanceMode());

        $this->notice()->run($this->app);

        $this->assertSame(0, $this->skippedMails());
    }

    /** Not due at any other minute: one mail a day at most, not one a minute through a long deploy. */
    public function test_holds_the_notice_is_due_only_at_the_daily_minute(): void
    {
        $this->atCronTime();
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->app->maintenanceMode()->activate([]);

        $this->assertFalse($this->notice()->isDue($this->app));
    }

    /**
     * onOneServer on a closure needs a name: it has one (name() and description() set the same field, the description
     * wins, and the mutex is keyed on it). Its mutex differs from the daily CronJob event's and the hourly's, so neither
     * stops the other at the same minute.
     */
    public function test_holds_the_notice_has_its_own_mutex(): void
    {
        $notice = $this->notice();
        $this->assertTrue($notice->onOneServer);
        $this->assertSame('framework/schedule-' . sha1('Tells staff when the daily run fell in maintenance'), $notice->mutexName());

        $cron = collect(app(Schedule::class)->events())->filter(fn (Event $e) => str_contains((string) $e->command, 'app:cron-job'));
        $this->assertCount(2, $cron);
        foreach ($cron as $e) {
            $this->assertNotSame($notice->mutexName(), $e->mutexName());
        }
    }

    /** The deploy spans the daily minute: schedule:run skips the daily run and the notice tells staff, once. */
    public function test_holds_a_skipped_daily_run_is_told_once(): void
    {
        $this->atCronTime();
        $this->app->maintenanceMode()->activate([]);

        Artisan::call('schedule:run');

        $this->assertSame(1, $this->skippedMails());
    }

    // ---------------------------------------------------------------------------------------------------------------
    // `down`, the pgrep pattern
    // ---------------------------------------------------------------------------------------------------------------

    /** Laravel's `down` on an app already down exits 0 («options updated»): a re-run after a failed deploy is not stopped. */
    public function test_holds_down_on_an_app_already_down_exits_zero(): void
    {
        $this->app->maintenanceMode()->activate([]);

        $this->assertSame(0, Artisan::call('down', ['--retry' => 15]));
        $this->assertTrue($this->app->maintenanceMode()->active());
    }

    /**
     * The looser pattern also matches a process that is not the cron (an `app:cron-jobs`, a `tail … artisan … app:cron-j…`).
     * No command but app:cron-job starts with `app:cron-j`, and a false positive only refuses --release-stale: the safe
     * side (a lock is never cleared under a live run).
     */
    public function test_holds_a_pgrep_false_positive_only_refuses_release_stale(): void
    {
        $names = array_keys(Artisan::all());
        $this->assertSame(['app:cron-job'], array_values(array_filter($names, fn ($n) => str_starts_with($n, 'app:cron-j'))));

        $this->assertTrue(Cache::lock('app:cron-job', 7200)->get());
        $code = 'cli_set_process_title("less /var/log/artisan app:cron-jobs.log"); sleep(30);';
        $p = proc_open([PHP_BINARY, '-r', $code], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        $this->procs[] = $p;
        for ($i = 0; $i < 40; $i++) {
            exec('pgrep -f ' . escapeshellarg('cron-jobs\.log'), $o, $c);
            if ($c === 0) {
                break;
            }
            usleep(50_000);
        }

        $this->assertSame(CronJob::FAILURE, Artisan::call('app:cron-wait', ['--release-stale' => true]));
        $this->assertFalse(Cache::lock('app:cron-job', 10)->get(), 'the lock is kept');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // deploy/update.sh, in a throwaway directory (as CronWaitAdversarialR2Test)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * @param  list<int>  $waits  exit codes of the successive app:cron-wait calls
     * @return array{int, string, list<string>}
     */
    private function deploy(array $waits, string $envDriverLine, bool $cacheDown): array
    {
        $this->dir = sys_get_temp_dir() . '/mycs-cronwait-adv3-' . uniqid();
        $d = $this->dir;
        File::makeDirectory($d . '/deploy', 0700, true);
        File::makeDirectory($d . '/bin');
        File::makeDirectory($d . '/storage/framework', 0700, true);
        File::copy(base_path('deploy/update.sh'), $d . '/deploy/update.sh');
        File::put($d . '/artisan', '');
        File::put($d . '/.env', "APP_ENV=production\nAPP_DEBUG=false\n{$envDriverLine}\n");
        symlink(base_path('vendor'), $d . '/vendor');
        File::put($d . '/waits', implode("\n", $waits) . "\n");
        if ($cacheDown) {
            File::put($d . '/cache-down', '{}');
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
        $php = escapeshellarg(PHP_BINARY);
        // The app's driver is the cache one (as Laravel reads this .env): «down» lives in cache-down, not in
        // storage/framework/down.
        File::put($d . '/bin/php', <<<SH
            #!/bin/bash
            if [[ "\$1" != artisan ]]; then exec {$php} "\$@"; fi
            shift
            echo "ART \$*" >> "\$LOG"
            case "\$1" in
              list) printf 'app:cron-job\\napp:cron-wait\\napp:is-down\\n' ;;
              app:is-down) [[ -f cache-down ]] && exit 0 || exit 1 ;;
              app:cron-wait) n=\$(head -1 waits); sed -i 1d waits; exit "\${n:-0}" ;;
              down) echo '{}' > cache-down ;;
              up) rm -f cache-down ;;
            esac
            exit 0
            SH);
        chmod($d . '/bin/git', 0700);
        chmod($d . '/bin/php', 0700);

        $log = $d . '/log';
        exec('cd ' . escapeshellarg($d) . ' && PATH=' . escapeshellarg($d . '/bin:' . getenv('PATH'))
            . ' env -u COMPOSER_DEV -u ALLOW_DEV_IN_PRODUCTION -u APP_DIR -u MYCS_UPDATE_COPY -u APP_ENV -u APP_DEBUG -u CRON_WAIT_SECONDS -u APP_MAINTENANCE_DRIVER'
            . ' LOG=' . escapeshellarg($log) . ' PHP=php SKIP_SNAPSHOT=1'
            . ' QUEUE_STOP_CMD=' . escapeshellarg('echo QSTOP >> "$LOG"') . ' QUEUE_START_CMD=' . escapeshellarg('echo QSTART >> "$LOG"')
            . ' bash deploy/update.sh 2>&1 < /dev/null', $out, $code);

        $lines = File::exists($log) ? array_values(array_filter(explode("\n", (string) File::get($log)))) : [];

        return [$code, implode("\n", $out), $lines];
    }

    /**
     * ADV-CW-R3-1. `_was_down` trusts `_env APP_MAINTENANCE_DRIVER` when it reads empty or «file», and then looks only
     * at storage/framework/down. `_env` greps `^APP_MAINTENANCE_DRIVER=`: a line phpdotenv (and so Laravel) reads as the
     * cache driver, `export APP_MAINTENANCE_DRIVER=cache`, reads empty, as does a driver set in the process environment.
     * A re-run after a failed deploy (the app left down) whose second wait fails then runs `up` and brings the
     * half-installed release up (ADV-CW-4 again). The guard above it (APP_ENV/APP_DEBUG, review of #392) asks Laravel's
     * own Env for this reason; here asking `app:is-down` whenever it exists, and the file only without it, is enough.
     */
    public function test_bug_was_down_misreads_an_exported_cache_driver(): void
    {
        [$code, $out, $log] = $this->deploy([0, 1], 'export APP_MAINTENANCE_DRIVER=cache', cacheDown: true);

        $this->assertSame(1, $code, $out);
        $this->assertNotContains('ART up', $log, "the app was down before the deploy (cache driver): it stays down\n" . $out);
    }

    /** A quoted or commented driver line reads as neither empty nor «file»: the script asks the app, and gets it right. */
    public function test_holds_was_down_with_a_quoted_driver_asks_the_app(): void
    {
        foreach (["APP_MAINTENANCE_DRIVER='cache'", 'APP_MAINTENANCE_DRIVER="cache"', 'APP_MAINTENANCE_DRIVER=cache # store'] as $line) {
            [$code, $out, $log] = $this->deploy([0, 1], $line, cacheDown: true);

            $this->assertSame(1, $code, $out);
            $this->assertContains('ART app:is-down', $log, $line);
            $this->assertNotContains('ART up', $log, $line);
            File::deleteDirectory($this->dir);
        }
    }

    /** Up before the deploy, a failed second wait brings it up again (cache driver, asked of the app). */
    public function test_holds_a_deploy_that_put_it_down_brings_it_up_again(): void
    {
        [$code, $out, $log] = $this->deploy([0, 1], 'APP_MAINTENANCE_DRIVER=cache', cacheDown: false);

        $this->assertSame(1, $code, $out);
        $this->assertContains('ART up', $log);
        $this->assertNotContains('CHECKOUT', $log);
    }
}
