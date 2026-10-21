<?php

namespace Tests\Feature;

use App\Jobs\Server\SuspendJob;
use App\Logging\WriteFailures;
use App\Models\EmailLog;
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
use Psr\Log\LoggerInterface;
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
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
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
        $this->assertStaffToldTheLogFailed();
    }

    /**
     * A channel without the stack's tap (an operator's LOG_CHANNEL=daily): the handler throws, and report() keeps it
     * (ErrorHandler::reportThrowable).
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
        $this->assertStaffToldTheLogFailed();
    }

    /** The log failing with no failed step: the run succeeds; nothing it reported was lost, so no notice about the log. */
    public function test_the_log_not_writable_with_no_failed_step_is_a_successful_run(): void
    {
        $this->breakTheLog('daily');
        $service = $this->overdueService();

        $this->assertNull($this->runTheCron());
        $this->assertSuspendedAndBilled($service);
        $this->assertNull(WriteFailures::first());
        $this->assertDatabaseMissing('email_logs', ['subject' => 'Cron Job: needs staff attention']);
    }

    /** report() with no logger at all: kept, never thrown, and said so (not as the reported error itself). */
    public function test_report_with_no_logger_never_throws(): void
    {
        $this->app->bind(LoggerInterface::class, fn () => throw new \Exception('no logger'));
        WriteFailures::forget();

        report(new RuntimeException('a step broke'));

        $this->assertSame('RuntimeException: No logger to report a step broke', WriteFailures::first());
    }

    private function breakTheLog(string $channel): void
    {
        config(["logging.channels.{$channel}" => ['driver' => 'monolog', 'handler' => FullDiskLogHandler::class]]);
        Log::forgetChannel($channel);
        Log::forgetChannel('stack');
    }

    private function assertStaffToldTheLogFailed(): void
    {
        $mail = EmailLog::query()->where('subject', 'Cron Job: needs staff attention')->latest('id')->first();
        $this->assertNotNull($mail, 'the notices mail is sent');
        $this->assertStringContainsString('log_not_written: The application log could not be written (UnexpectedValueException: Writing to the log file failed', html_entity_decode((string) $mail->body, ENT_QUOTES));
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
