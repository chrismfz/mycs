<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sweep 10, ops (adversarial verifier): a deploy that dies of a signal leaves no marker of its own outage.
 */
class Sweep10OpsTest extends TestCase
{
    private ?string $dir = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    /**
     * S10-OPS-1. The failed deploy's marker (storage/framework/deploy-left-down) is written only by the ERR trap, and
     * bash runs no ERR trap when the script itself dies of a signal (the SSH session dropped: SIGHUP; a kill; the OOM
     * killer). The site is then down with the workers stopped and no marker, so the re-run takes the outage for staff's
     * own window: it leaves the site down, starts no worker, and ends «Deployed» with exit 0.
     */
    public function test_the_rerun_after_a_deploy_killed_by_a_signal_brings_the_site_up(): void
    {
        $this->prepare(downBefore: false);
        // The session drops during the migrations: the stub's `migrate` sends SIGHUP to the deploy script (its parent).
        File::put($this->dir . '/hup-migrate', '');
        [$code, $out, $log] = $this->runDeploy();
        $this->assertSame(129, $code, 'the first deploy was to die of SIGHUP: ' . $out);
        $this->assertStringContainsString('ART down', $log);
        $this->assertStringContainsString('QSTOP', $log);
        $this->assertFileExists($this->dir . '/is-down', 'the killed deploy left the site down');
        $this->assertStringNotContainsString("ART up\n", $log);

        // The operator logs in again and re-runs the deploy, as docs/install.md says («το επόμενο που πετυχαίνει το ανεβάζει»).
        File::delete([$this->dir . '/hup-migrate', $this->dir . '/log']);
        [$code, $out, $log] = $this->runDeploy();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Deployed', $out);
        $this->assertFileDoesNotExist($this->dir . '/is-down', "the deploy's own outage was taken for staff's window: «Deployed», exit 0, the site still down\n" . $out);
        $this->assertStringContainsString("ART up\n", $log);
        $this->assertStringContainsString('QSTART', $log, 'the workers the killed deploy stopped were not started');
    }

    /**
     * The real update.sh in a throwaway directory, with git, php artisan and curl stubbed.
     */
    private function prepare(bool $downBefore): void
    {
        $this->dir = sys_get_temp_dir() . '/mycs-sweep10-ops-' . uniqid();
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
              migrate) if [[ -f hup-migrate ]]; then kill -HUP "\$PPID"; sleep 2; fi ;;
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
