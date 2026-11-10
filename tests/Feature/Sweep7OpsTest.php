<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Sweep 7, ops (adversarial verifier): what the deploy does not see, and a marker that outlives its failed deploy.
 */
class Sweep7OpsTest extends TestCase
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
     * S7-OPS-1 (a). A scheduler started by this install's artisan path from another directory (a crontab line
     * `* * * * * php /var/www/mycs/artisan schedule:run`: its cwd is $HOME) has a readable cwd that is not the app's:
     * schedulerAlive() skips it at `continue`, and its command line (this install's artisan, by path) is looked at only
     * when the cwd cannot be read. In a gap between two events it holds no mutex: the deploy goes on under it.
     */
    public function test_cron_wait_waits_for_a_schedule_run_started_by_this_installs_artisan_path_from_another_directory(): void
    {
        Sleep::fake();
        $this->app->instance(Schedule::class, new Schedule); // no mutex held: the gap between two events
        $here = realpath(base_path()) ?: base_path();
        $this->spawn('php ' . $here . '/artisan schedule:run', sys_get_temp_dir());

        $this->assertSame(1, Artisan::call('app:cron-wait', ['--timeout' => 2]), 'the deploy went on under a scheduler of this install (artisan by its path, cwd elsewhere)');
        $this->assertStringContainsString('schedule:run (pending tasks)', Artisan::output());
    }

    /**
     * S7-OPS-1 (b). The same rule in deploy/update.sh: a worker started as `php <here>/artisan queue:work` with no
     * WorkingDirectory (cwd /) is not among this checkout's workers, so on the queue:restart path nothing waits for its
     * current job before the snapshot and the migrations.
     */
    public function test_deploy_finds_a_queue_worker_started_by_this_checkouts_artisan_path_from_another_directory(): void
    {
        $this->dir = sys_get_temp_dir() . '/mycs-sweep7-ops-' . uniqid();
        File::makeDirectory($this->dir, 0700, true);
        $here = realpath($this->dir);
        $this->assertTrue((bool) preg_match('/^_queue_worker_pids\(\) \{\n.*?\n\}\n/ms', File::get(base_path('deploy/update.sh')), $m));
        File::put($this->dir . '/pids.sh', "set -uo pipefail\n" . $m[0] . "_queue_worker_pids\n");
        $worker = $this->spawn('php ' . $here . '/artisan queue:work --tries=3', '/');
        $pid = proc_get_status($worker)['pid'];

        exec('cd ' . escapeshellarg($here) . ' && env -u QUEUE_WORKER_MATCH bash pids.sh 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertContains((string) $pid, $out, 'this checkout\'s worker (artisan by its path, cwd /) is not waited for: ' . implode(' ', $out));
    }

    /**
     * S7-OPS-2. The marker of a failed deploy outlives it: `php artisan up` (which the failure message itself offers)
     * does not remove it. With no deploy in between, a site staff later put down on purpose (the return to WHMCS) is
     * taken for the failed deploy's, and a deploy inside that window starts the workers and brings the site up.
     */
    public function test_a_marker_left_by_a_failed_deploy_that_staff_resolved_by_hand_does_not_bring_up_a_later_window(): void
    {
        $this->prepare(downBefore: false);
        $marker = $this->dir . '/storage/framework/deploy-left-down';
        File::put($this->dir . '/fail-migrate', '');
        [$code, $out] = $this->runDeploy();
        $this->assertNotSame(0, $code, $out);
        $this->assertStringContainsString('or: php artisan up', $out);
        $this->assertFileExists($marker);

        // Staff fix it by hand and take the offered way out: php artisan up (the stub's `up`).
        File::delete([$this->dir . '/fail-migrate', $this->dir . '/is-down', $this->dir . '/log']);
        // Days later, no deploy in between: staff put the site down on purpose, its workers stopped. (The fix: a marker
        // older than 24 hours means nothing; younger, the next deploy still brings the site up, Sweep6OpsTest REV-767-1.)
        touch($marker, time() - 2 * 86400);
        File::put($this->dir . '/is-down', '{}');

        [$code, $out, $log] = $this->runDeploy();

        $this->assertSame(0, $code, $out);
        $this->assertFileExists($this->dir . '/is-down', "staff's own maintenance window: a deploy inside it brought the site up\n" . $out);
        $this->assertStringNotContainsString("ART up\n", $log);
        $this->assertStringNotContainsString('QSTART', $log, 'workers staff had stopped were started');
        $this->assertStringContainsString('left down', $out);
        $this->assertFileDoesNotExist($marker, 'the stale marker is removed');
    }

    /** The other side: a marker a few hours old is the failed deploy's, and the next deploy brings the site up. */
    public function test_a_marker_a_few_hours_old_still_brings_the_site_up(): void
    {
        $this->prepare(downBefore: true);
        $marker = $this->dir . '/storage/framework/deploy-left-down';
        File::put($marker, '');
        touch($marker, time() - 23 * 3600);

        [$code, $out, $log] = $this->runDeploy();

        $this->assertSame(0, $code, $out);
        $this->assertFileDoesNotExist($this->dir . '/is-down', $out);
        $this->assertStringContainsString("ART up\n", $log);
        $this->assertFileDoesNotExist($marker);
    }

    /** A marker dated in the future (a clock set back) is stale too: staff's window is left down. */
    public function test_a_marker_dated_in_the_future_is_stale(): void
    {
        $this->prepare(downBefore: true);
        $marker = $this->dir . '/storage/framework/deploy-left-down';
        File::put($marker, '');
        touch($marker, time() + 7200);

        [$code, $out, $log] = $this->runDeploy();

        $this->assertSame(0, $code, $out);
        $this->assertFileExists($this->dir . '/is-down', $out);
        $this->assertStringNotContainsString("ART up\n", $log);
        $this->assertFileDoesNotExist($marker);
    }

    /** A stale marker that cannot be removed (its directory another user's) is said and ignored: the deploy goes on. */
    public function test_a_stale_marker_that_cannot_be_removed_does_not_stop_the_deploy(): void
    {
        $this->prepare(downBefore: true);
        $marker = $this->dir . '/storage/framework/deploy-left-down';
        File::put($marker, '');
        touch($marker, time() - 2 * 86400);
        chmod($this->dir . '/storage/framework', 0500);
        if (@touch($this->dir . '/storage/framework/probe')) {
            chmod($this->dir . '/storage/framework', 0700);
            $this->markTestSkipped('root removes any file');
        }
        try {
            [$code, $out, $log] = $this->runDeploy();
        } finally {
            chmod($this->dir . '/storage/framework', 0700);
        }

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('could not remove', $out);
        $this->assertFileExists($this->dir . '/is-down', $out);
        $this->assertStringNotContainsString("ART up\n", $log);
    }

    /**
     * REV-777-7, the shell side: a worker of another install whose path ends with this checkout's is not this checkout's;
     * one given this checkout's artisan as a whole argument (real argv, cwd elsewhere) is.
     */
    public function test_deploy_matches_a_queue_worker_by_the_whole_artisan_argument(): void
    {
        $this->dir = sys_get_temp_dir() . '/mycs-sweep7-ops-' . uniqid();
        File::makeDirectory($this->dir, 0700, true);
        $here = realpath($this->dir);
        $this->assertTrue((bool) preg_match('/^_queue_worker_pids\(\) \{\n.*?\n\}\n/ms', File::get(base_path('deploy/update.sh')), $m));
        File::put($this->dir . '/pids.sh', "set -uo pipefail\n" . $m[0] . "_queue_worker_pids\n");
        $other = $this->spawn('php /srv/backup' . $here . '/artisan queue:work --tries=3', '/');
        // Real arguments, not a title: `php -r … <here>/artisan queue:work`.
        $mine = proc_open([PHP_BINARY, '-r', 'sleep(30);', '--', $here . '/artisan', 'queue:work'], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, '/');
        $this->assertIsResource($mine);
        $this->procs[] = $mine;
        usleep(200_000);

        exec('cd ' . escapeshellarg($here) . ' && env -u QUEUE_WORKER_MATCH bash pids.sh 2>&1', $out, $code);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertNotContains((string) proc_get_status($other)['pid'], $out, 'another install\'s worker (its path ends with ours) is waited for');
        $this->assertContains((string) proc_get_status($mine)['pid'], $out, implode(' ', $out));
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
     * The real update.sh in a throwaway directory, with git, php artisan and curl stubbed.
     */
    private function prepare(bool $downBefore): void
    {
        $this->dir = sys_get_temp_dir() . '/mycs-sweep7-ops-' . uniqid();
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
              migrate) [[ -f fail-migrate ]] && exit 1 ;;
              app:cron-wait) [[ -f fail-cron-wait && -f is-down ]] && exit 1 ;;
              up) rm -f is-down ;;
            esac
            exit 0
            SH);
        File::put($d . '/bin/curl', "#!/bin/bash\nprintf 200\n"); // the smoke check, no network
        chmod($d . '/bin/git', 0700);
        chmod($d . '/bin/php', 0700);
        chmod($d . '/bin/curl', 0700);
    }

    /**
     * @return array{int, string, string} exit code, output, the log of artisan calls and queue hooks
     */
    private function runDeploy(): array
    {
        $d = $this->dir;
        $out = [];
        $log = $d . '/log';
        exec('cd ' . escapeshellarg($d) . ' && PATH=' . escapeshellarg($d . '/bin:' . getenv('PATH'))
            . ' env -u COMPOSER_DEV -u ALLOW_DEV_IN_PRODUCTION -u APP_DIR -u MYCS_UPDATE_COPY -u APP_ENV -u APP_DEBUG -u CRON_WAIT_SECONDS -u APP_MAINTENANCE_DRIVER'
            . ' LOG=' . escapeshellarg($log) . ' PHP=php COMPOSER=true NPM=true SKIP_SNAPSHOT=1'
            . ' QUEUE_STOP_CMD=' . escapeshellarg('echo QSTOP >> "$LOG"') . ' QUEUE_START_CMD=' . escapeshellarg('echo QSTART >> "$LOG"')
            . ' bash deploy/update.sh 2>&1 < /dev/null', $out, $code);

        return [$code, implode("\n", $out), (string) @file_get_contents($log)];
    }
}
