<?php

namespace Tests\Feature;

use App\Jobs\Server\TerminateJob;
use App\Mail\Mail as MyCSMail;
use App\Mail\ResentMail;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use App\Services\Service\RetryModuleAction;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use Tests\TestCase;

/** Sweep 3, lens «notifications»: adversarial verifier. Each test fails on main (ba71ac62) for the reason it names. */
class Sweep3LNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        Queue::fake();
        config(['settings.mail_disable' => false, 'settings.cronjob_payment_reminder' => 6, 'settings.cronjob_overdue_reminders' => '1,2',
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true, 'settings.system_email_address' => 'staff@example.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $due, string $created = '2026-10-10'): Invoice
    {
        $product = $this->createProduct();
        $user = User::factory()->create();
        $svc = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => $due]);
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $svc->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => $created]);
        DB::table('invoice_reminders')->where(['invoice_id' => $invoice->id, 'kind' => 'mailed'])->delete();
        Mail::fake();

        return $invoice->fresh();
    }

    /** @return list<string> */
    private function sent(): array
    {
        return Mail::queued(MyCSMail::class)->map(fn (MyCSMail $mail) => $mail->templateKey)->values()->all();
    }

    /**
     * L-NOTIFICATIONS-1. A closed account (WG-8, WHMCS «Closed») is mailed nothing in bulk (BulkMailer, «as WHMCS mails
     * no Closed client»), and its logins cannot open its invoices; the cron's reminders still go to it.
     */
    public function test_l1_a_closed_account_gets_no_payment_reminder(): void
    {
        $invoice = $this->invoice('2026-10-25'); // due in 5 days: «due soon» day
        $invoice->client->forceFill(['closed_at' => now()->subDay()])->save();
        $overdue = $this->invoice('2026-10-19'); // 1 day late
        $overdue->client->forceFill(['closed_at' => now()->subDay()])->save();
        Mail::fake();

        (new PaymentReminders)->send();

        $this->assertSame([], $this->sent(), 'a closed account was mailed its invoice reminders');
    }

    /**
     * L-NOTIFICATIONS-2. Staff cancelled the order (CancelOrder: TerminateJob($service, false), no mail). The terminate
     * failed; staff's Retry from Pending Module Actions builds a TerminateJob with the default flag and mails the customer.
     */
    public function test_l2_a_retried_silent_terminate_sends_no_mail(): void
    {
        require_once __DIR__ . '/../Fixtures/FlakyServer.php';
        FlakyServer::$failures = 1;
        FlakyServer::$calls = [];
        Mail::fake();
        $server = Server::query()->create(['name' => 'Flaky', 'extension' => 'FlakyServer', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $user = User::factory()->create();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'cancelled', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => '2026-11-20']);

        try {
            (new TerminateJob($service, false))->handle(); // as CancelOrder queues it; the server is down
        } catch (Exception) {
        }
        $open = ModuleAction::query()->open()->where('action', 'terminate')->sole();

        RetryModuleAction::run($open);

        $this->assertContains('terminate', array_slice(FlakyServer::$calls, 1), 'the retry reached the server');
        $this->assertNotContains('server_terminated', $this->sent(), 'a cancellation staff chose to make silent mailed the customer on Retry');
    }

    /**
     * L-NOTIFICATIONS-3. With mail_disable on, the cron marks the overdue notice sent though none went: turned back on
     * the same day, the notice never goes.
     */
    public function test_l3_a_reminder_skipped_while_mail_is_disabled_goes_once_mail_is_back(): void
    {
        $this->invoice('2026-10-19'); // 1 day late: overdue notice day
        config(['settings.mail_disable' => true]);
        (new PaymentReminders)->send();
        $this->assertSame([], $this->sent());

        config(['settings.mail_disable' => false]);
        (new PaymentReminders)->send();

        $this->assertSame(['invoice_overdue'], $this->sent(), 'the overdue notice was marked sent while mail was off, and never went');
    }

    /**
     * L-NOTIFICATIONS-4. app:mail-resend-failed resends an overdue notice for an invoice paid since it failed.
     */
    public function test_l4_resend_failed_skips_a_dunning_mail_for_an_invoice_paid_since(): void
    {
        $invoice = $this->invoice('2026-10-15');
        $log = EmailLog::query()->create(['client_id' => $invoice->client_id, 'subject' => 'Overdue', 'to' => 'customer@example.com',
            'body' => '<p>pay or be suspended</p>', 'template_key' => 'invoice_overdue', 'related_type' => Invoice::class,
            'related_id' => $invoice->id, 'trigger' => EmailLog::TRIGGER_AUTO]);
        DB::table('email_logs')->where('id', $log->id)->update(['status' => EmailLog::STATUS_FAILED, 'sending_at' => null]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);
        Mail::fake();

        $this->artisan('app:mail-resend-failed')->assertSuccessful();

        Mail::assertNotQueued(ResentMail::class);
    }
}
