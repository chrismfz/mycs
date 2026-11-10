<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
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

    /** @var list<resource> */
    private array $procs = [];

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) {
            proc_terminate($p, SIGKILL);
            proc_close($p);
        }
        if ($this->dir !== null) {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    /**
     * S6-OPS-1. schedule:run works out its due events once, at its start (Event::isDue is the only maintenance check;
     * filtersPass has none), then runs them one after another. app:cron-wait saw only the withoutOverlapping mutexes
     * held right now: in the gap between two events (while an event without a mutex runs, as the heartbeat or the
     * «cron-skipped» closure) it found none and returned 0. The deploy then put the site down and went on, while the
     * same schedule:run started the next event (bank:alerts, domains:sync, …) over the checkout and the migrations.
     *
     * Fixed in the wait, not in the scheduler: a live `artisan schedule:run` of this install is waited for as well. (The
     * verifier's test ran schedule:run and cron-wait in one process, where no wait can end: here the scheduler is a
     * process of its own, as under cron, in a gap between two events: no mutex held.)
     */
    public function test_cron_wait_waits_for_a_schedule_run_of_this_install_between_two_events(): void
    {
        Sleep::fake();
        $this->app->instance(Schedule::class, new Schedule); // no mutex held anywhere: the gap between two events
        $scheduler = $this->spawn('php artisan schedule:run', base_path());

        $this->assertSame(1, Artisan::call('app:cron-wait', ['--timeout' => 3]), 'the deploy went on under a scheduler with events still to start');
        $this->assertStringContainsString('schedule:run', Artisan::output());

        proc_terminate($scheduler, SIGKILL);
        proc_close($scheduler);
        $this->procs = [];

        $this->assertSame(0, Artisan::call('app:cron-wait', ['--timeout' => 3]), 'the scheduler is over: the deploy goes on');
    }

    /** Another install's scheduler on the host (Ekdosi's, a second MyCS) is not waited for: only this path's. */
    public function test_cron_wait_ignores_the_scheduler_of_another_install(): void
    {
        Sleep::fake();
        $this->app->instance(Schedule::class, new Schedule);
        $this->spawn('php artisan schedule:run', sys_get_temp_dir());
        $this->spawn('php /srv/other-install/artisan schedule:run', sys_get_temp_dir());

        $this->assertSame(0, Artisan::call('app:cron-wait', ['--timeout' => 3]));
    }

    /**
     * A fake process with this command line and working directory, a child of this test process (the only ones
     * app:cron-wait looks at in tests: CronWait::$ownChildrenOnly).
     *
     * @return resource
     */
    private function spawn(string $title, string $cwd)
    {
        $code = 'cli_set_process_title(' . var_export($title, true) . '); sleep(30);';
        $p = proc_open([PHP_BINARY, '-r', $code], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $cwd);
        $this->assertIsResource($p);
        $this->procs[] = $p;
        for ($i = 0; $i < 40; $i++) {
            exec('pgrep -P ' . getmypid() . ' -f ' . escapeshellarg(preg_quote($title, null)), $o, $c);
            if ($c === 0) {
                return $p;
            }
            usleep(50_000);
        }
        $this->fail('the fake process never showed its title');
    }

    /**
     * S6-OPS-2. update.sh recorded whether the site was already down (_was_down) but used it only on its failure paths:
     * a successful deploy always ended with `artisan up`, bringing up a site staff had put down on purpose (the return
     * to WHMCS, a planned maintenance window). Now it stays down, says so last, and workers that were not running
     * before the deploy are not started.
     */
    public function test_successful_deploy_leaves_down_a_site_that_was_down_before_it(): void
    {
        [$code, $out, $log] = $this->deploy(downBefore: true);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Deployed', $out);
        $this->assertFileExists($this->dir . '/is-down', "the site was down before the deploy (on purpose): a successful deploy left it up\n" . $out);
        $this->assertStringNotContainsString("ART up\n", $log);
        $this->assertStringContainsString('QSTOP', $log);
        $this->assertStringNotContainsString('QSTART', $log, 'no worker of this checkout was running before: none started');
        $this->assertStringContainsString('site was in maintenance before the deploy: left down; run artisan up when ready', $out);
    }

    /** The usual deploy, of a site that was up: its workers back, then up. */
    public function test_successful_deploy_of_a_site_that_was_up_ends_up_with_its_workers(): void
    {
        [$code, $out, $log] = $this->deploy(downBefore: false);

        $this->assertSame(0, $code, $out);
        $this->assertFileDoesNotExist($this->dir . '/is-down', $out);
        $this->assertMatchesRegularExpression('/QSTOP\n(.*\n)*QSTART\nART up\n/', $log);
        $this->assertStringNotContainsString('left down', $out);
    }

    /**
     * The real update.sh in a throwaway directory, with git, php artisan and curl stubbed.
     *
     * @return array{int, string, string} exit code, output, the log of artisan calls and queue hooks
     */
    private function deploy(bool $downBefore): array
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
        if ($downBefore) {
            File::put($d . '/is-down', '{}'); // staff put the site down before the deploy
        }

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

        return [$code, implode("\n", $out), (string) @file_get_contents($log)];
    }
}
