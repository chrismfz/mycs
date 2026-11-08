<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Jobs\Server\CreateJob;
use App\Mail\Mail as MyCSMail;
use App\Mail\ResentMail;
use App\Models\BillingAgreement;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\CardExpiryNotices;
use App\Services\Service\RetryModuleAction;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use Tests\TestCase;

/** Sweep 3, lens «notifications»: the fixes' own edges, besides the verifier's tests (Sweep3LNotificationsTest). */
class Sweep3LNotificationsFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        Queue::fake();
        config(['settings.mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $status, ?int $serverId = null): Service
    {
        $product = $this->createProduct();
        if ($serverId !== null) {
            $product->product->update(['server_id' => $serverId]);
        }
        $user = User::factory()->create();

        return Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-11-20']);
    }

    /** @return list<string> */
    private function sent(): array
    {
        return Mail::queued(MyCSMail::class)->map(fn (MyCSMail $mail) => $mail->templateKey)->values()->all();
    }

    /** L-NOTIFICATIONS-1: a closed account's suspension notice (ALWAYS) does not go either, nor in-app. */
    public function test_a_closed_account_gets_no_suspension_notice(): void
    {
        Mail::fake();
        $service = $this->service('suspended');
        $service->client->forceFill(['closed_at' => now()->subDay()])->save();

        NotificationHelper::serverSuspendedNotification($service->client, $service);

        $this->assertSame([], $this->sent());
        $this->assertSame(0, DB::table('notifications')->count());
    }

    /** L-NOTIFICATIONS-1: staff may still send a closed account what it needs by hand (docs/accounts.md WG-8). */
    public function test_staff_can_still_mail_a_closed_accounts_invoice_by_hand(): void
    {
        $service = $this->service('active');
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-25']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        $service->client->forceFill(['closed_at' => now()->subDay()])->save();
        Mail::fake();

        NotificationHelper::invoiceNotification($service->client, $invoice->fresh(), 'invoice_payment_reminder', byStaff: true);

        $this->assertSame(['invoice_payment_reminder'], $this->sent());
    }

    /** L-NOTIFICATIONS-2: a create staff accepted without the welcome mail, retried after it failed, still sends none. */
    public function test_a_retried_silent_create_sends_no_welcome_mail(): void
    {
        require_once __DIR__ . '/../Fixtures/FlakyServer.php';
        FlakyServer::$failures = 1;
        FlakyServer::$calls = [];
        Mail::fake();
        $server = Server::query()->create(['name' => 'Flaky', 'extension' => 'FlakyServer', 'type' => 'server', 'enabled' => true]);
        $service = $this->service('pending', $server->id);

        try {
            (new CreateJob($service, false, true))->handle(); // AcceptOrder without «Send welcome email»; the server is down
        } catch (Exception) {
        }
        $open = ModuleAction::query()->open()->where('action', 'create')->sole();
        $this->assertSame(['send_notification' => false, 'by_staff' => true], $open->job_options);

        RetryModuleAction::run($open);

        $this->assertSame(['create', 'create'], FlakyServer::$calls, 'the retry reached the server');
        $this->assertNotContains('new_server_created', $this->sent());
    }

    /** L-NOTIFICATIONS-2: a job asked with the defaults keeps them: its Retry still mails as before. */
    public function test_a_default_job_keeps_its_mail_on_retry(): void
    {
        $service = $this->service('active');
        $row = ModuleAction::query()->create(['service_id' => $service->id, 'action' => 'terminate', 'status' => ModuleAction::STATUS_OPEN]);

        $job = $row->lifecycleJob();

        $this->assertTrue((bool) $job->sendNotification);
    }

    /** L-NOTIFICATIONS-2: a payment's replay of a create the queue never took asks for it as it was asked. */
    public function test_the_replay_of_a_create_not_queued_keeps_its_flags(): void
    {
        $service = $this->service('pending');
        ModuleAction::query()->create(['service_id' => $service->id, 'action' => 'create', 'status' => ModuleAction::STATUS_OPEN,
            'last_error' => ModuleAction::NOT_QUEUED . 'redis down', 'job_options' => ['send_notification' => false, 'by_staff' => false]]);

        ModuleAction::askAgainNotQueued($service);

        Queue::assertPushed(CreateJob::class, fn (CreateJob $job) => $job->sendNotification === false);
    }

    /** L-NOTIFICATIONS-3: with mail off, the card-expiry notice is not marked as told; it goes once mail is back. */
    public function test_a_card_expiry_notice_waits_while_mail_is_disabled(): void
    {
        config(['settings.cc_expiry_notice_day' => 15, 'settings.mail_disable' => true]);
        $user = User::factory()->create();
        $gateway = Gateway::query()->create(['extension' => 'Sweep3Card', 'name' => 'Card', 'type' => 'gateway', 'enabled' => true]);
        $agreement = BillingAgreement::query()->create(['client_id' => $user->client()->id, 'gateway_id' => $gateway->id, 'name' => 'Card',
            'external_reference' => 'ref', 'type' => 'card', 'expiry' => '2026-10-31']);

        $this->assertSame(0, (new CardExpiryNotices)->run());

        $this->assertNull($agreement->fresh()->expiry_notified_at);
    }

    /** L-NOTIFICATIONS-4: a suspension notice of a service unsuspended since is left out of the batch resend. */
    public function test_resend_failed_skips_a_suspension_notice_of_a_service_active_again(): void
    {
        $service = $this->service('active');
        $log = EmailLog::query()->create(['client_id' => $service->client_id, 'subject' => 'Suspended', 'to' => 'customer@example.com',
            'body' => '<p>suspended</p>', 'template_key' => 'server_suspended', 'related_type' => $service->getMorphClass(),
            'related_id' => $service->id, 'trigger' => EmailLog::TRIGGER_AUTO]);
        DB::table('email_logs')->where('id', $log->id)->update(['status' => EmailLog::STATUS_FAILED, 'sending_at' => null]);
        Mail::fake();

        $this->artisan('app:mail-resend-failed')->expectsOutputToContain('left out: its service is active now')->assertSuccessful();

        Mail::assertNotQueued(ResentMail::class);
    }

    /** L-NOTIFICATIONS-4: one still true (its invoice still unpaid) is resent as before. */
    public function test_resend_failed_still_resends_a_reminder_of_an_invoice_still_unpaid(): void
    {
        $service = $this->service('active');
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-15']);
        $invoice->items()->create(['description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        $log = EmailLog::query()->create(['client_id' => $invoice->client_id, 'subject' => 'Overdue', 'to' => 'customer@example.com',
            'body' => '<p>pay</p>', 'template_key' => 'invoice_overdue', 'related_type' => $invoice->getMorphClass(),
            'related_id' => $invoice->id, 'trigger' => EmailLog::TRIGGER_AUTO]);
        DB::table('email_logs')->where('id', $log->id)->update(['status' => EmailLog::STATUS_FAILED, 'sending_at' => null]);
        Mail::fake();

        $this->artisan('app:mail-resend-failed')->assertSuccessful();

        Mail::assertQueued(ResentMail::class, 1);
    }
}
