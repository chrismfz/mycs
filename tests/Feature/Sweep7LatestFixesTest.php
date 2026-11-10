<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Sweep 7, adversarial verification of «latest-fixes» (#767 the deploy's wait for a live scheduler). Failed on main as
 * reviewed (44532e53). S7-LATEST-FIXES-1 (addon dates) is in Sweep7AddonDatesTest. Fake data only.
 */
class Sweep7LatestFixesTest extends TestCase
{
    /** @var list<resource> */
    private array $procs = [];

    protected function tearDown(): void
    {
        foreach ($this->procs as $p) {
            proc_terminate($p, SIGKILL);
            proc_close($p);
        }
        parent::tearDown();
    }

    /**
     * S7-LATEST-FIXES-2. app:cron-wait waits for a live `artisan schedule:run` of this install (#767, S6-OPS-1), but one
     * started by artisan's absolute path from another directory (`* * * * * php /path/to/app/artisan schedule:run`, its
     * cwd the user's home) has a readable cwd that is not the app's: the loop goes on to the next pid and never reads the
     * command line. The deploy goes on under a scheduler with tasks still to start.
     */
    public function test_s7_latest_fixes_2_cron_wait_waits_for_a_scheduler_started_by_artisans_path_from_another_directory(): void
    {
        Sleep::fake();
        $this->app->instance(Schedule::class, new Schedule); // no mutex held: the gap between two events
        $here = realpath(base_path()) ?: base_path();
        $this->spawn('php ' . $here . '/artisan schedule:run', sys_get_temp_dir());

        $this->assertSame(1, Artisan::call('app:cron-wait', ['--timeout' => 3]),
            'the deploy went on under this install\'s scheduler (artisan named by its path, cwd elsewhere)');
        $this->assertStringContainsString('schedule:run', Artisan::output());
    }

    /**
     * A fake process with this command line and working directory, a child of this test process (as Sweep6OpsTest's).
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
}
