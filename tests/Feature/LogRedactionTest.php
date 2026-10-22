<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Logging\RedactSecrets;
use App\Mail\SystemMail;
use App\Models\DebugLog;
use App\Models\EmailLog;
use App\Models\ModuleAction;
use App\Models\Service;
use App\Models\User;
use App\Support\LogText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use RuntimeException;
use Tests\TestCase;

/**
 * What staff read (module action errors, system mails, error rows, laravel.log) keeps no secret, unless «Full logs» is
 * on, which keeps it exactly as received (security audit PROV-2, SEC-6, SEC-9; owner's decision 2026-10-23).
 */
class LogRedactionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'Authorization: Bearer whm-Token-1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.logs_full' => false]);
    }

    public function test_the_text_is_masked_unless_full_logs(): void
    {
        $this->assertStringNotContainsString('whm-Token-1234567890', (string) LogText::of(self::SECRET));
        $this->assertStringContainsString('********', (string) LogText::of(self::SECRET));

        config(['settings.logs_full' => true]);
        $this->assertSame(self::SECRET, LogText::of(self::SECRET));
    }

    private function service(): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'currency_code' => 'USD']);
    }

    public function test_a_module_actions_error_is_kept_masked(): void
    {
        $service = $this->service();

        $row = ModuleAction::recordFailure($service, 'create', 'CPanel', new RuntimeException('WHM said no: ' . self::SECRET));

        $this->assertStringNotContainsString('whm-Token-1234567890', $row->fresh()->last_error);
        $this->assertStringContainsString('WHM said no', $row->fresh()->last_error);
    }

    public function test_full_logs_keep_a_module_actions_error_as_received(): void
    {
        config(['settings.logs_full' => true]);
        $service = $this->service();

        $row = ModuleAction::recordFailure($service, 'create', 'CPanel', new RuntimeException('WHM said no: ' . self::SECRET));

        $this->assertStringContainsString('whm-Token-1234567890', $row->fresh()->last_error);
    }

    public function test_a_system_mail_and_its_log_are_masked(): void
    {
        NotificationHelper::sendSystemEmailNotification('Module action failed', e('The server answered: ' . self::SECRET));

        $log = EmailLog::query()->where('template_key', 'system')->sole();
        $this->assertStringNotContainsString('whm-Token-1234567890', $log->body);
        $this->assertStringContainsString('The server answered', $log->body);
        Mail::assertQueued(SystemMail::class, fn ($mail) => !str_contains($mail->render(), 'whm-Token-1234567890'));
    }

    public function test_laravel_log_masks_the_message_its_context_and_the_exception(): void
    {
        $handler = new TestHandler;
        $logger = new Logger(new Monolog('test', [$handler]));
        (new RedactSecrets)($logger);

        $logger->error('Calling WHM with ' . self::SECRET, ['password' => 'Plain-Pass-99', 'exception' => new RuntimeException('cPanel: ' . self::SECRET)]);

        $record = $handler->getRecords()[0];
        $written = $record->message . json_encode($record->context);
        $this->assertStringNotContainsString('whm-Token-1234567890', $written);
        $this->assertStringNotContainsString('Plain-Pass-99', $written);
        $this->assertStringContainsString('RuntimeException: cPanel', $written, 'the exception is still there, as text');
        $this->assertContains(RedactSecrets::class, config('logging.channels.stack.tap'), 'laravel.log is written through it');
    }

    public function test_an_error_row_of_the_debug_log_is_masked(): void
    {
        config(['settings.debug' => true]);

        report(new RuntimeException('Gateway answered ' . self::SECRET));

        $row = DebugLog::query()->where('type', 'exception')->sole();
        $this->assertStringNotContainsString('whm-Token-1234567890', json_encode($row->context));
    }

    public function test_laravel_log_keeps_the_cause_of_a_masked_exception(): void
    {
        $handler = new TestHandler;
        $logger = new Logger(new Monolog('test', [$handler]));
        (new RedactSecrets)($logger);

        $logger->error('x', ['exception' => new RuntimeException('Provisioning failed', 0, new RuntimeException('WHM: ' . self::SECRET))]);

        $written = json_encode($handler->getRecords()[0]->context);
        $this->assertStringContainsString('[previous exception] RuntimeException: WHM', $written);
        $this->assertStringNotContainsString('whm-Token-1234567890', $written);
    }
}
