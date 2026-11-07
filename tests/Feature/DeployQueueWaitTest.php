<?php

namespace Tests\Feature;

use App\Console\Commands\ReadBankAlerts;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The deploy's waits around the migrations (sweep 2, F-SCHEDULER-QUEUE-1): app:cron-wait also waits for the scheduled
 * commands already running, and deploy/update.sh's queue:restart path waits for this checkout's workers' current job.
 */
class DeployQueueWaitTest extends TestCase
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

    public function test_cron_wait_waits_for_a_running_task_and_goes_on_once_it_is_over(): void
    {
        $event = $this->event(ReadBankAlerts::class);
        $this->assertTrue($event->mutex->create($event));
        $sleeps = 0;
        Sleep::fake();
        Sleep::whenFakingSleep(function () use ($event, &$sleeps): void {
            if (++$sleeps === 3) {
                $event->mutex->forget($event);
            }
        });

        try {
            $code = Artisan::call('app:cron-wait', ['--timeout' => 60]);
        } finally {
            $event->mutex->forget($event);
        }

        $output = Artisan::output();
        $this->assertSame(0, $code, $output);
        $this->assertSame(3, $sleeps);
        $this->assertStringContainsString('bank:alerts', $output);
    }

    public function test_cron_wait_fails_when_a_task_is_still_running_after_the_timeout(): void
    {
        $event = $this->event(ReadBankAlerts::class);
        $this->assertTrue($event->mutex->create($event));
        Sleep::fake();

        try {
            $code = Artisan::call('app:cron-wait', ['--timeout' => 5]);
        } finally {
            $event->mutex->forget($event);
        }

        $this->assertSame(1, $code);
        Sleep::assertSleptTimes(5);
        $this->assertStringContainsString('schedule:clear-cache', Artisan::output());
    }

    public function test_cron_wait_passes_with_nothing_running(): void
    {
        $this->assertSame(0, Artisan::call('app:cron-wait', ['--timeout' => 0]));
    }

    public function test_restart_path_fails_when_a_worker_is_still_on_its_job_after_the_wait(): void
    {
        // A worker that never looks at the flag within the wait.
        [$out] = $this->runStop(workerSeconds: 60, cwd: null, waitSeconds: 1);

        $this->assertStringContainsString('RC=1', $out, $out);
        $this->assertStringContainsString('still on a job', $out, $out);
    }

    public function test_restart_path_does_not_wait_for_another_checkouts_worker(): void
    {
        [$out, $seconds] = $this->runStop(workerSeconds: 60, cwd: sys_get_temp_dir(), waitSeconds: 30);

        $this->assertStringContainsString('RC=0 via restart', $out, $out);
        $this->assertLessThan(10, $seconds, $out);
    }

    /**
     * REV2-FSQ-1's other side: a worker of this checkout whose cwd cannot be read (another user running it) is still
     * waited for when its command line names this checkout's artisan by its path.
     */
    public function test_restart_path_waits_for_this_checkouts_worker_named_by_path_when_its_cwd_is_unreadable(): void
    {
        [$out] = $this->runStop(workerSeconds: 60, cwd: sys_get_temp_dir(), waitSeconds: 1,
            name: '/usr/bin/php ' . getcwd() . '/artisan queue:work', hideCwd: true);

        $this->assertStringContainsString('RC=1', $out, $out);
        $this->assertStringContainsString('still on a job', $out, $out);
    }

    /**
     * REV2-FSQ-2: app:cron-wait holds the deploy back while any withoutOverlapping mutex is held, so none may keep the
     * 1440-minute default: a dead run's would fail every deploy for a day.
     */
    public function test_every_overlapping_task_has_an_expiry_of_two_hours_at_most(): void
    {
        $long = [];
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            if ($event->withoutOverlapping && $event->expiresAt > 120) {
                $long[] = (string) $event->command;
            }
        }

        $this->assertSame([], $long);
    }

    /** @return array{0: string, 1: float} the harness's output, and how long stop_queue_worker took */
    private function runStop(int $workerSeconds, ?string $cwd, int $waitSeconds, string $name = 'php artisan queue:work', bool $hideCwd = false): array
    {
        $this->dir = sys_get_temp_dir() . '/mycs-deploy-qw-' . uniqid();
        File::makeDirectory($this->dir . '/bin', 0700, true);
        $script = File::get(base_path('deploy/update.sh'));
        $start = strpos($script, '_stopped_by=""');
        $end = strpos($script, 'start_queue_worker() {');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        File::put($this->dir . '/bin/systemctl', "#!/bin/sh\n[ \"\$1\" = cat ] && exit 0\nexit 1\n");
        chmod($this->dir . '/bin/systemctl', 0700);
        File::put($this->dir . '/bin/fake-artisan', "#!/bin/sh\nexit 0\n");
        chmod($this->dir . '/bin/fake-artisan', 0700);
        File::put($this->dir . '/fake-worker', "#!/bin/sh\nsleep {$workerSeconds}\n");
        chmod($this->dir . '/fake-worker', 0700);
        if ($hideCwd) {
            // readlink /proc/<worker>/cwd refused (exit 1), as for another user's process.
            File::put($this->dir . '/bin/readlink', "#!/bin/sh\ncase \"\$1\" in /proc/\$(cat " . escapeshellarg($this->dir . '/worker.pid') . ")/cwd) exit 1 ;; esac\nexec /usr/bin/readlink \"\$@\"\n");
            chmod($this->dir . '/bin/readlink', 0700);
        }

        $harness = "set -Eeuo pipefail\n"
            . "log() { :; }; warn() { :; }; ok() { :; }; fail() { echo \"FAIL: \$*\"; }\n"
            . "QUEUE_SERVICE=mycs-queue\nQUEUE_WAIT_SECONDS={$waitSeconds}\nART=" . escapeshellarg($this->dir . '/bin/fake-artisan') . "\n"
            . substr($script, $start, $end - $start) . "\n"
            . '(' . ($cwd !== null ? 'cd ' . escapeshellarg($cwd) . ' && ' : '')
            . 'exec -a ' . escapeshellarg($name) . ' sh ' . escapeshellarg($this->dir . '/fake-worker') . ") >/dev/null 2>&1 &\nWORKER=\$!\n"
            . 'echo $WORKER > ' . escapeshellarg($this->dir . '/worker.pid') . "\nsleep 0.5\n"
            . "rc=0; stop_queue_worker || rc=\$?\necho \"RC=\$rc via \$_stopped_by\"\nkill \$WORKER 2>/dev/null || true\n";
        File::put($this->dir . '/harness.sh', $harness);

        $t = microtime(true);
        exec('PATH=' . escapeshellarg($this->dir . '/bin:' . getenv('PATH')) . ' bash ' . escapeshellarg($this->dir . '/harness.sh') . ' 2>&1', $out);

        return [implode("\n", $out), microtime(true) - $t];
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
