<?php

namespace Tests\Feature;

use App\Console\Commands\FetchTicketMail;
use App\Console\Commands\MailSweep;
use App\Console\Commands\OwedCreatesCommand;
use App\Console\Commands\ReadBankAlerts;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Adversarial verification of flow f-scheduler-queue (sweep 2).
 *
 * F-SCHEDULER-QUEUE-1: deploy/update.sh, on its queue:restart path (the deploy runs as `mycs`, which may not stop the
 * system unit), goes on to the snapshot and migrations while a job is still running; and app:cron-wait does not wait
 * for other scheduled commands already running (bank:alerts).
 *
 * F-SCHEDULER-QUEUE-2: withoutOverlapping() with no expiry keeps a dead run's mutex for 24 h, so a 5-minute task is
 * skipped for a day.
 */
class Sweep2FSchedulerQueueTest extends TestCase
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
     * The script's own stop_queue_worker, with systemctl refusing the stop (a non-root user, no polkit rule) and a
     * worker mid-job that exits after its current job once queue:restart is asked (as Laravel's does). When the
     * function returns, the script's next line is the snapshot, then the checkout and migrate: the job must be over.
     */
    public function test_bug_restart_path_returns_while_a_job_is_still_running(): void
    {
        $this->dir = sys_get_temp_dir() . '/mycs-sweep2-fsq-' . uniqid();
        File::makeDirectory($this->dir . '/bin', 0700, true);

        $script = File::get(base_path('deploy/update.sh'));
        $start = strpos($script, '_stopped_by=""');
        $end = strpos($script, 'start_queue_worker() {');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $functions = substr($script, $start, $end - $start);

        // systemctl: the unit exists, the stop is refused (Access denied / interactive authentication required).
        File::put($this->dir . '/bin/systemctl', "#!/bin/sh\n[ \"\$1\" = cat ] && exit 0\nexit 1\n");
        chmod($this->dir . '/bin/systemctl', 0700);
        // queue:restart only sets a flag (Laravel: illuminate:queue:restart in the cache).
        File::put($this->dir . '/bin/fake-artisan', "#!/bin/sh\n[ \"\$1\" = queue:restart ] && touch " . escapeshellarg($this->dir . '/restart-flag') . "\nexit 0\n");
        chmod($this->dir . '/bin/fake-artisan', 0700);
        // A worker in the middle of a 4-second job (a RenewDomainJob, a SendDomainOwnerChangeJob...), which looks at
        // the restart flag only after its job.
        File::put($this->dir . '/fake-worker', "#!/bin/sh\nsleep 4\n[ -f " . escapeshellarg($this->dir . '/restart-flag') . " ] && exit 0\nsleep 60\n");
        chmod($this->dir . '/fake-worker', 0700);

        $harness = "set -Eeuo pipefail\n"
            . "log() { :; }; warn() { :; }; ok() { :; }; fail() { :; }\n"
            . "QUEUE_SERVICE=mycs-queue\nART=" . escapeshellarg($this->dir . '/bin/fake-artisan') . "\n"
            . $functions . "\n"
            . 'sh ' . escapeshellarg($this->dir . '/fake-worker') . " &\nWORKER=\$!\nsleep 0.5\n"
            . "stop_queue_worker\n"
            // Here the script starts mariadb-dump and, a few steps on, migrate --force.
            . "if kill -0 \$WORKER 2>/dev/null; then echo \"SNAPSHOT-WITH-JOB-RUNNING via \$_stopped_by\"; else echo \"WORKER-GONE via \$_stopped_by\"; fi\n";
        File::put($this->dir . '/harness.sh', $harness);

        exec('PATH=' . escapeshellarg($this->dir . '/bin:' . getenv('PATH')) . ' bash ' . escapeshellarg($this->dir . '/harness.sh') . ' 2>&1', $out, $code);
        $out = implode("\n", $out);

        $this->assertStringContainsString('via restart', $out, $out);
        $this->assertStringContainsString('WORKER-GONE', $out, 'The deploy went on to the snapshot and migrations while the queued job was still running: ' . $out);
    }

    /**
     * A bank:alerts run in progress (its withoutOverlapping mutex held by a live schedule:run) is not waited for by
     * app:cron-wait, the deploy's only wait before `down`, the snapshot and the migrations.
     */
    public function test_bug_cron_wait_ignores_a_bank_alerts_run_in_progress(): void
    {
        $event = $this->event(ReadBankAlerts::class);
        $this->assertTrue($event->mutex->create($event));

        try {
            $code = Artisan::call('app:cron-wait', ['--timeout' => 0]);
        } finally {
            $event->mutex->forget($event);
        }

        $this->assertSame(1, $code, 'app:cron-wait let the deploy go on while bank:alerts (it records payments) was running.');
    }

    /**
     * A run killed with its parent (SIGKILL, a hard reset) leaves its mutex. Two hours on, a task that runs every 5 or
     * 15 minutes is still skipped: the default expiry is 1440 minutes.
     */
    public function test_bug_a_dead_runs_mutex_silences_frequent_tasks_for_a_day(): void
    {
        $this->freezeTime();
        $skipped = [];
        foreach ([FetchTicketMail::class, MailSweep::class, OwedCreatesCommand::class] as $command) {
            $event = $this->event($command);
            $event->mutex->forget($event);
            $this->assertTrue($event->mutex->create($event));
        }

        $this->travel(2)->hours();

        foreach ([FetchTicketMail::class, MailSweep::class, OwedCreatesCommand::class] as $command) {
            $event = $this->event($command);
            if (!$event->filtersPass($this->app)) {
                $skipped[] = class_basename($command) . ' (expiry ' . $event->expiresAt . ' min)';
            }
            $event->mutex->forget($event);
        }

        $this->assertSame([], $skipped, 'Still skipped two hours after the run died: ' . implode(', ', $skipped));
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
