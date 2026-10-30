<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A deploy waits for a cron run in progress (sweep 9γ, owner 2026-10-30): app:cron-wait returns once the cron's lock is
 * free, and fails (the deploy stops before it changes anything) while a run still holds it.
 */
class CronWaitTest extends TestCase
{
    public function test_no_run_in_progress_lets_the_deploy_go_on_and_leaves_the_lock_free(): void
    {
        $this->artisan('app:cron-wait', ['--timeout' => 0])->assertExitCode(0);

        $this->assertTrue(Cache::lock('app:cron-job', 10)->get(), 'released at once: the cron can run');
    }

    public function test_a_run_in_progress_stops_the_deploy_when_it_outlasts_the_wait(): void
    {
        $run = Cache::lock('app:cron-job', 7200);
        $this->assertTrue($run->get(), 'precondition: a cron run holds it');

        $this->artisan('app:cron-wait', ['--timeout' => 1])->assertExitCode(1);

        $run->release();
        $this->artisan('app:cron-wait', ['--timeout' => 1])->assertExitCode(0);
    }

    public function test_the_deploy_script_waits_before_maintenance_and_again_after(): void
    {
        $script = (string) file_get_contents(base_path('deploy/update.sh'));
        $wait = strpos($script, 'cron_wait ||');
        $down = strpos($script, '$ART down');

        $this->assertNotFalse($wait);
        $this->assertLessThan($down, $wait, 'waited for before maintenance');
        $this->assertGreaterThan($down, strrpos($script, 'cron_wait ||'), 'and again after, for a run that began in between');
        $this->assertLessThan(strpos($script, '$ART migrate --force'), strrpos($script, 'cron_wait ||'), 'both before the migrations');
    }
}
