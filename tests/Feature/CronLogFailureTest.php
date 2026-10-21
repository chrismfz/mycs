<?php

namespace Tests\Feature;

use App\Jobs\Server\SuspendJob;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use RuntimeException;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * A log that cannot be written (2026-10-21: / at 100%, Monolog's StreamHandler throws "Writing to the log file failed")
 * made report() throw inside the catch of a failed step: the run stopped there, and the steps after it (renewals,
 * suspensions, reminders) never ran. One step's failure stays that step's.
 */
class CronLogFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14]);
        Queue::fake();
    }

    /** The log as configured (stack over daily), its file not writable: the run goes on past a failed step. */
    public function test_a_failed_step_with_the_log_not_writable_leaves_the_later_steps_running(): void
    {
        $this->breakTheLog('daily');
        $service = $this->overdueService();
        $this->failEarlyStep();

        $failure = $this->runTheCron();

        $this->assertSame('epp codes step broke', $failure?->getMessage(), 'the run ends failed, with the step that failed');
        $this->assertSuspendedAndBilled($service);
    }

    /**
     * A channel without ignore_exceptions (an operator's LOG_CHANNEL=daily): report() itself throws, and the cron's own
     * guard keeps it in the step. What was not logged goes to the console.
     */
    public function test_report_throwing_inside_a_step_does_not_abort_the_run(): void
    {
        $this->breakTheLog('daily');
        config(['logging.default' => 'daily']);
        $service = $this->overdueService();
        $this->failEarlyStep();

        $failure = $this->runTheCron();

        $this->assertSame('epp codes step broke', $failure?->getMessage());
        $this->assertSuspendedAndBilled($service);
        $this->assertStringContainsString('Not logged (Writing to the log file failed', Artisan::output());
    }

    /** The log failing on its own (no step failed): nothing changes, the run succeeds. */
    public function test_the_log_not_writable_with_no_failed_step_is_a_successful_run(): void
    {
        $this->breakTheLog('daily');
        $service = $this->overdueService();

        $this->assertNull($this->runTheCron());
        $this->assertSuspendedAndBilled($service);
    }

    private function breakTheLog(string $channel): void
    {
        config(["logging.channels.{$channel}" => ['driver' => 'monolog', 'handler' => FullDiskLogHandler::class]]);
        Log::forgetChannel($channel);
        Log::forgetChannel('stack');
        // As configured (the test proves the shipped value, not one set here).
        $this->assertTrue((bool) config('logging.channels.stack.ignore_exceptions'));
    }

    /** domain_epp_codes_cleared, early in the run, fails: its query throws once it ran. */
    private function failEarlyStep(): void
    {
        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'epp_code')) {
                throw new RuntimeException('epp codes step broke');
            }
        });
    }

    private function overdueService(): Service
    {
        $user = User::factory()->create();
        $product = $this->createProduct();

        return Service::factory()->create([
            'client_id' => $user->client()->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now()->subDays(3),
            'currency_code' => 'USD',
            'price' => 10.00,
        ]);
    }

    private function runTheCron(): ?\Throwable
    {
        try {
            Artisan::call('app:cron-job');
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    /** invoices_created and services_suspended both run after domain_epp_codes_cleared. */
    private function assertSuspendedAndBilled(Service $service): void
    {
        $this->assertDatabaseHas('invoices', ['client_id' => $service->client_id, 'status' => 'unpaid', 'due_at' => $service->expires_at]);
        $this->assertDatabaseHas('services', ['id' => $service->id, 'status' => 'suspended']);
        Queue::assertPushed(SuspendJob::class, fn ($job) => $job->service->id === $service->id);
        $this->assertDatabaseHas('cron_stats', ['key' => 'services_suspended']);
        $this->assertDatabaseHas('cron_stats', ['key' => 'email_logs_deleted']); // the last step
    }
}

/** A log file on a full disk, as Monolog's StreamHandler fails there. */
class FullDiskLogHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        throw new UnexpectedValueException('Writing to the log file failed: Write of 512 bytes failed with errno=28 No space left on device');
    }
}
