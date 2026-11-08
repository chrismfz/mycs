<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Sweep 6, ops (adversarial verifier): the deploy's protection of the window between `down` and `up`.
 */
class Sweep6OpsTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    /**
     * S6-OPS-1. schedule:run works out its due events once, at its start (Event::isDue is the only maintenance check;
     * filtersPass has none), then runs them one after another. app:cron-wait sees only the withoutOverlapping mutexes
     * held right now: in the gap between two events (here: while an event without a mutex runs, as the heartbeat or
     * the «cron-skipped» closure) it finds none and returns 0. The deploy then puts the site down and goes on, while
     * the same schedule:run starts the next event (bank:alerts, domains:sync, …) over the checkout and the migrations.
     */
    public function test_bug_schedule_run_keeps_starting_due_events_after_down_and_cron_wait_returned_0(): void
    {
        $down = new class implements MaintenanceMode
        {
            public bool $on = false;

            public function activate(array $payload): void
            {
                $this->on = true;
            }

            public function deactivate(): void
            {
                $this->on = false;
            }

            public function active(): bool
            {
                return $this->on;
            }

            public function data(): array
            {
                return [];
            }
        };
        $this->app->instance(MaintenanceMode::class, $down);
        Sleep::fake();

        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);

        $waitCode = null;
        $ranInMaintenance = false;
        // First due event, no mutex (as the heartbeat): the deploy happens while it runs, i.e. between two events.
        $schedule->call(function () use ($down, &$waitCode): void {
            $waitCode = Artisan::call('app:cron-wait', ['--timeout' => 5]); // update.sh: cron_wait
            $down->activate([]);                                              // update.sh: down
            $waitCode = max($waitCode, Artisan::call('app:cron-wait', ['--timeout' => 5])); // the second cron_wait
        })->name('first-event')->everyMinute();
        // Second due event: a money/data writer with its withoutOverlapping mutex (as bank:alerts).
        $schedule->call(function () use ($down, &$ranInMaintenance): void {
            $ranInMaintenance = $down->active();
        })->name('bank-alerts-like')->everyMinute()->withoutOverlapping(15);

        Artisan::call('schedule:run');

        $this->assertSame(0, $waitCode, 'cron-wait let the deploy go on');
        $this->assertFalse($ranInMaintenance, 'the scheduler started a writing task after the deploy put the site down and passed cron-wait');
    }

    /**
     * S6-OPS-2. update.sh records whether the site was already down (_was_down) but uses it only on its failure paths:
     * a successful deploy always ends with `artisan up`, bringing up a site staff had put down on purpose (the return
     * to WHMCS, a planned maintenance window).
     */
    public function test_bug_successful_deploy_brings_up_a_site_that_was_down_before_it(): void
    {
        $this->dir = sys_get_temp_dir() . '/mycs-sweep6-ops-' . uniqid();
        $d = $this->dir;
        File::makeDirectory($d . '/deploy', 0700, true);
        File::makeDirectory($d . '/bin');
        File::makeDirectory($d . '/storage/framework', 0700, true);
        File::copy(base_path('deploy/update.sh'), $d . '/deploy/update.sh');
        File::put($d . '/deploy/private-files.sh', "exit 0\n");
        File::put($d . '/artisan', '');
        File::put($d . '/.env', "APP_ENV=production\nAPP_DEBUG=false\nAPP_URL=http://deploy.test\n");
        symlink(base_path('vendor'), $d . '/vendor');
        File::put($d . '/is-down', '{}'); // staff put the site down before the deploy

        File::put($d . '/bin/git', <<<'SH'
            #!/bin/bash
            case "$1" in
              status|fetch|update-index|read-tree|ls-files|checkout) exit 0 ;;
              symbolic-ref) echo main ;;
              rev-parse)
                if [[ "$*" == *HEAD* ]]; then echo aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa; else echo bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb; fi ;;
              merge-base) exit 1 ;;
              log) echo 2026-11-08T00:00:00+00:00 ;;
            esac
            exit 0
            SH);
        $php = escapeshellarg(PHP_BINARY);
        File::put($d . '/bin/php', <<<SH
            #!/bin/bash
            if [[ "\$1" != artisan ]]; then exec {$php} "\$@"; fi
            shift
            echo "ART \$*" >> "\$LOG"
            case "\$1" in
              list) printf 'app:cron-job\\napp:cron-wait\\napp:is-down\\n' ;;
              app:is-down) [[ -f is-down ]] && exit 0 || exit 1 ;;
              down) echo '{}' > is-down ;;
              up) rm -f is-down ;;
            esac
            exit 0
            SH);
        File::put($d . '/bin/curl', "#!/bin/bash\nprintf 200\n"); // the smoke check, no network
        chmod($d . '/bin/git', 0700);
        chmod($d . '/bin/php', 0700);
        chmod($d . '/bin/curl', 0700);

        $log = $d . '/log';
        exec('cd ' . escapeshellarg($d) . ' && PATH=' . escapeshellarg($d . '/bin:' . getenv('PATH'))
            . ' env -u COMPOSER_DEV -u ALLOW_DEV_IN_PRODUCTION -u APP_DIR -u MYCS_UPDATE_COPY -u APP_ENV -u APP_DEBUG -u CRON_WAIT_SECONDS -u APP_MAINTENANCE_DRIVER'
            . ' LOG=' . escapeshellarg($log) . ' PHP=php COMPOSER=true NPM=true SKIP_SNAPSHOT=1'
            . ' QUEUE_STOP_CMD=' . escapeshellarg('echo QSTOP >> "$LOG"') . ' QUEUE_START_CMD=' . escapeshellarg('echo QSTART >> "$LOG"')
            . ' bash deploy/update.sh 2>&1 < /dev/null', $out, $code);
        $out = implode("\n", $out);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Deployed', $out);
        $this->assertFileExists($d . '/is-down', "the site was down before the deploy (on purpose): a successful deploy left it up\n" . $out);
    }
}
