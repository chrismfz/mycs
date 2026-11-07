<?php

namespace Tests\Feature;

use App\Console\Commands\Domains\SyncCosts;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Independent review (sweep 2) of the fixes for F-SCHEDULER-QUEUE-1/2: the deploy's waits for queue workers and
 * scheduled tasks.
 */
class Rev2FSchedulerQueueTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            exec('pkill -f ' . escapeshellarg($this->dir . '/fake-worker') . ' 2>/dev/null');
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    /**
     * REV2-FSQ-1. The deploy runs as `mycs` (su - mycs -c deploy/update.sh). Another user's queue worker on the host
     * (the Ekdosi one, `ekdosi` user, `php artisan queue:work` too) has a /proc/PID/cwd that `mycs` cannot read, and
     * _queue_worker_pids counts every unreadable cwd as this checkout's worker. That worker never sees MyCS's
     * queue:restart flag, so the restart path waits QUEUE_WAIT_SECONDS for it and fails the deploy. Simulated here with a
     * readlink that is refused for the foreign worker's PID, as it is for a non-root user.
     */
    public function test_restart_path_does_not_wait_for_another_users_worker_whose_cwd_is_unreadable(): void
    {
        $this->dir = sys_get_temp_dir() . '/mycs-rev2-qw-' . uniqid();
        File::makeDirectory($this->dir . '/bin', 0700, true);
        $script = File::get(base_path('deploy/update.sh'));
        $start = strpos($script, '_stopped_by=""');
        $end = strpos($script, 'start_queue_worker() {');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        File::put($this->dir . '/bin/systemctl', "#!/bin/sh\n[ \"\$1\" = cat ] && exit 0\nexit 1\n");
        File::put($this->dir . '/bin/fake-artisan', "#!/bin/sh\nexit 0\n");
        // readlink /proc/<foreign pid>/cwd: Permission denied (exit 1), as for another user's process.
        File::put($this->dir . '/bin/readlink', "#!/bin/sh\ncase \"\$1\" in /proc/\$(cat " . escapeshellarg($this->dir . '/foreign.pid') . ")/cwd) exit 1 ;; esac\nexec /usr/bin/readlink \"\$@\"\n");
        File::put($this->dir . '/fake-worker', "#!/bin/sh\nsleep 60\n");
        foreach (['bin/systemctl', 'bin/fake-artisan', 'bin/readlink', 'fake-worker'] as $f) {
            chmod($this->dir . '/' . $f, 0700);
        }

        $harness = "set -Eeuo pipefail\n"
            . "log() { :; }; warn() { :; }; ok() { :; }; fail() { echo \"FAIL: \$*\"; }\n"
            . "QUEUE_SERVICE=mycs-queue\nQUEUE_WAIT_SECONDS=3\nART=" . escapeshellarg($this->dir . '/bin/fake-artisan') . "\n"
            . substr($script, $start, $end - $start) . "\n"
            // Another application's worker, elsewhere on the host (its cwd not this checkout).
            . '(cd ' . escapeshellarg(sys_get_temp_dir()) . ' && exec -a ' . escapeshellarg('/usr/bin/php artisan queue:work --tries=3')
            . ' sh ' . escapeshellarg($this->dir . '/fake-worker') . ") >/dev/null 2>&1 &\nWORKER=\$!\necho \$WORKER > "
            . escapeshellarg($this->dir . '/foreign.pid') . "\nsleep 0.5\n"
            . "rc=0; stop_queue_worker || rc=\$?\necho \"RC=\$rc via \$_stopped_by\"\nkill \$WORKER 2>/dev/null || true\n";
        File::put($this->dir . '/harness.sh', $harness);

        exec('PATH=' . escapeshellarg($this->dir . '/bin:' . getenv('PATH')) . ' bash ' . escapeshellarg($this->dir . '/harness.sh') . ' 2>&1', $out);
        $out = implode("\n", $out);

        $this->assertStringContainsString('RC=0 via restart', $out, 'The deploy fails waiting for another user\'s queue worker: ' . $out);
    }

    /**
     * REV2-FSQ-2. app:cron-wait now fails on any held withoutOverlapping mutex. A weekly or daily task still on the
     * 1440-minute default (SyncCosts, GatewayLogPrune, RegistrarDebugLogPrune, EuVatRatesCheck) killed with its parent
     * (the SIGKILL / hard reset of F-SCHEDULER-QUEUE-2) leaves a mutex that blocks every deploy for up to a day, with
     * no task process alive. Before the fix such a mutex never held a deploy back.
     */
    public function test_a_dead_daily_tasks_mutex_does_not_block_deploys_for_a_day(): void
    {
        $this->freezeTime();
        config(['cache.default' => 'file']);
        $event = $this->event(SyncCosts::class);
        $event->mutex->forget($event);
        $this->assertTrue($event->mutex->create($event));

        $this->travel(2)->hours();

        try {
            $code = Artisan::call('app:cron-wait', ['--timeout' => 0]);
        } finally {
            $event->mutex->forget($event);
        }

        $this->assertSame(0, $code, 'A crashed run\'s mutex (no process alive, 2 h on) still blocks the deploy: ' . Artisan::output());
    }

    private function event(string $command): Event
    {
        $name = $this->app->make($command)->getName();
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, $name) && !str_contains((string) $event->command, '--')) {
                return $event;
            }
        }
        $this->fail("No scheduled event for {$name}");
    }
}
