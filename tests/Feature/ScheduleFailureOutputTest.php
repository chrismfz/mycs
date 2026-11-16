<?php

namespace Tests\Feature;

use App\Console\ScheduleFailureOutput;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A scheduled command that fails says why in the log (nixpal 2026-11-16: «domains:sync failed with exit code [1]», its
 * reason only in the data).
 */
class ScheduleFailureOutputTest extends TestCase
{
    public function test_a_failed_command_logs_its_last_lines_and_a_good_one_logs_nothing(): void
    {
        Log::spy();
        $schedule = new Schedule;
        $failing = $schedule->exec('sh -c \'echo started; echo "the registrar timed out"; exit 3\'')->description('Syncs the domains');
        $good = $schedule->exec('echo all fine');
        ScheduleFailureOutput::apply($schedule);

        $failing->run($this->app);
        $good->run($this->app);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $message === 'Scheduled command failed: Syncs the domains'
            && $context['exit_code'] === 3 && $context['output'] === "started\nthe registrar timed out");
        @unlink($failing->output);
        @unlink($good->output);
    }

    public function test_every_command_of_the_real_schedule_keeps_its_output_and_closures_are_left_alone(): void
    {
        $events = $this->app->make(Schedule::class)->events();
        $commands = array_filter($events, fn ($event) => !$event instanceof CallbackEvent);

        $this->assertNotEmpty($commands);
        foreach ($commands as $event) {
            $this->assertStringContainsString('logs/schedule-', (string) $event->output, (string) $event->command);
        }
        foreach (array_filter($events, fn ($event) => $event instanceof CallbackEvent) as $event) {
            $this->assertSame($event->getDefaultOutput(), $event->output, 'a closure: untouched');
        }
    }

    public function test_the_tail_keeps_the_end_of_a_long_output(): void
    {
        $output = implode("\n", array_map(fn ($i) => "line {$i}", range(1, 100))) . "\n";

        $tail = ScheduleFailureOutput::tail($output);
        $this->assertSame(ScheduleFailureOutput::LINES, count(explode("\n", $tail)));
        $this->assertStringEndsWith('line 100', $tail);
        $this->assertSame(ScheduleFailureOutput::CHARS, mb_strlen(ScheduleFailureOutput::tail(str_repeat('x', 5000))));
    }
}
