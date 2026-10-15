<?php

namespace Tests\Feature;

use App\Admin\RelationManagers\EmailLogsRelationManager;
use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Helpers\NotificationHelper;
use App\Mail\ResentMail;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Mail\MailAudit;
use Filament\Facades\Filament;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Mail as MailFacade;
use Mockery;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * Adversarial review of PR #137 (the mail audit). Each test states what should hold and fails on the PR as it stands.
 */
class MailAuditReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Invoice $invoice;

    /** @var list<\Closure(list<string>): void> one behaviour per call of the fake server, then it accepts */
    public static array $script = [];

    /** @var list<list<string>> */
    public static array $delivered = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null, 'settingable_id' => null], ['value' => '0', 'type' => 'boolean']);
        config(['settings.mail_disable' => false, 'settings.mail_from_address' => 'billing@example.test']);
        self::$script = [];
        self::$delivered = [];
        $this->user = User::factory()->create(['email' => 'owner@example.test']);
        $this->invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $this->invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
    }

    private function fakeServer(): void
    {
        MailFacade::extend('review-smtp', fn () => new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $envelope ??= Envelope::create($message);
                $recipients = array_map(fn ($address) => $address->getAddress(), $envelope->getRecipients());
                if (($step = array_shift(MailAuditReviewTest::$script)) !== null) {
                    $step($recipients);
                }
                MailAuditReviewTest::$delivered[] = $recipients;

                return new SentMessage($message, $envelope);
            }

            public function __toString(): string
            {
                return 'review-smtp://';
            }
        });
        config(['mail.mailers.review' => ['transport' => 'review-smtp'], 'mail.default' => 'review', 'queue.default' => 'sync']);
    }

    private function loggedMail(string $to = 'owner@example.test', array $cc = []): array
    {
        $log = EmailLog::query()->create(['client_id' => $this->user->client()->id, 'subject' => 'Invoice', 'to' => $to, 'body' => '<p>Invoice</p>',
            'cc' => $cc ?: null, 'template_key' => 'new_invoice_created']);
        $mail = new ResentMail('Invoice', '<p>Invoice</p>');
        $mail->email_log_id = $log->id;
        $mail->to($to)->cc($cc);

        return [$log, $mail];
    }

    private function staff(array $permissions): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    /**
     * R1: the password reset mail's link (a live token) is in the log's body, and Resend sends it to any address staff
     * type: whoever has «Resend Email» can take over a customer's login. A live token must not go out again.
     */
    public function test_r1_resend_does_not_send_a_live_password_reset_link_to_another_address(): void
    {
        MailFacade::fake();
        NotificationTemplate::query()->where('key', 'password_reset')->update(['enabled' => true]);
        $token = 'livetoken0123456789abcdef';

        NotificationHelper::passwordResetNotification($this->user, ['url' => "https://mycs.example.test/password/reset/{$token}?email=owner%40example.test"]);
        $log = EmailLog::query()->where('template_key', 'password_reset')->sole();
        // Fixed where it starts: the log never keeps the live link (it showed in the log before the fix).
        $this->assertStringNotContainsString($token, $log->body, 'the live link is not kept in the log');

        MailAudit::resend($log, 'attacker@example.test');

        MailFacade::assertQueued(ResentMail::class, fn (ResentMail $mail) => $mail->hasTo('attacker@example.test'));
        MailFacade::assertNotQueued(ResentMail::class, fn (ResentMail $mail) => str_contains($mail->renderedHtml, $token));
    }

    /**
     * R2: a refused Cc sends it once more to the To only; if that second send fails with anything but an
     * UnexpectedResponseException (connection dropped, timeout), the row stays «sending»: the queue's retries find it
     * claimed and return without sending. The mail is lost until the sweep calls it «may have gone out».
     */
    public function test_r2_a_connection_failure_on_the_to_only_send_is_retried_not_stuck_in_sending(): void
    {
        $this->fakeServer();
        [$log, $mail] = $this->loggedMail(cc: ['gone@example.test']);
        self::$script = [
            fn () => throw new UnexpectedResponseException('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 <gone@example.test>: Recipient address rejected".', 550),
            fn () => throw new TransportException('Connection to "smtp.example.test:587" has been closed unexpectedly.'),
        ];

        try {
            $mail->send(app('mailer'));
        } catch (TransportException) {
        }
        $this->assertSame(EmailLog::STATUS_PENDING, $log->fresh()->status, 'back to pending for the queue');

        (clone $mail)->send(app('mailer')); // the queue's next try
        $this->assertSame(EmailLog::STATUS_SENT, $log->fresh()->status);
        $this->assertCount(1, self::$delivered);
    }

    /**
     * R3: the queue worker is down for a day. The sweep marks the queued mails failed («never sent»), staff run
     * app:mail-resend-failed after the outage, then the worker comes back and runs the original jobs: «failed» is
     * claimable, so every customer gets the mail twice.
     */
    public function test_r3_an_original_job_that_runs_after_its_batch_resend_does_not_send_twice(): void
    {
        $this->fakeServer();
        [$log, $mail] = $this->loggedMail();
        EmailLog::query()->whereKey($log->id)->update(['created_at' => now()->subHours(25)]);

        $this->artisan('app:mail-sweep')->assertSuccessful();
        $this->assertSame(EmailLog::STATUS_FAILED, $log->fresh()->status);
        $this->artisan('app:mail-resend-failed')->assertSuccessful(); // sync queue: the resend goes out now
        $this->assertCount(1, self::$delivered);

        $mail->send(app('mailer')); // the worker is back and runs the original job
        $this->assertCount(1, self::$delivered, 'the customer got it twice');
    }

    /**
     * R4: same root cause from the Resend button: the original's job failed (status failed), staff resend it, later
     * someone runs `queue:retry all`: the original is claimed again and goes out a second time.
     */
    public function test_r4_queue_retry_of_a_failed_original_after_staff_resent_it_does_not_send_twice(): void
    {
        $this->fakeServer();
        [$log, $mail] = $this->loggedMail();
        $log->update(['status' => EmailLog::STATUS_FAILED, 'error' => 'Connection refused']);

        MailAudit::resend($log, $log->to);
        $this->assertCount(1, self::$delivered);

        $mail->send(app('mailer')); // queue:retry of the original failed job
        $this->assertCount(1, self::$delivered, 'resent by staff and retried by the queue: twice');
    }

    /**
     * R5: the sweep marks a row left «sending» failed with «it may have gone out. Check before resending.», and
     * app:mail-resend-failed resends it anyway, without anyone checking: the duplicate the claim exists to prevent.
     */
    public function test_r5_the_batch_does_not_resend_a_mail_that_may_have_gone_out(): void
    {
        MailFacade::fake();
        [$log] = $this->loggedMail();
        $log->update(['status' => EmailLog::STATUS_SENDING, 'sending_at' => now()->subHour()]);

        $this->artisan('app:mail-sweep')->assertSuccessful();
        $this->assertStringContainsString('may have gone out', (string) $log->fresh()->error);
        $this->artisan('app:mail-resend-failed')->assertSuccessful();

        $this->assertSame(0, EmailLog::query()->where('resent_from_id', $log->id)->count());
    }

    /**
     * R6: a worker that dies (or times out) mid-send on the last try leaves the row «sending»; the queue then fails the
     * job (MaxAttemptsExceeded) and Queue::failing turns it into a plain «failed» with the queue's message: the «may
     * have gone out» warning is lost, and the row becomes claimable (queue:retry) and batch-resendable.
     */
    public function test_r6_the_queue_failure_keeps_the_may_have_gone_out_warning_of_a_row_left_sending(): void
    {
        [$log, $mail] = $this->loggedMail();
        $log->update(['status' => EmailLog::STATUS_SENDING, 'sending_at' => now()->subMinutes(2)]);

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn(ResentMail::class);
        $job->shouldReceive('getRawBody')->andReturn(json_encode(['data' => ['command' => serialize(new SendQueuedMailable($mail))]]));
        $job->shouldReceive('uuid')->andReturn('job-uuid');
        event(new JobFailed('database', $job, new MaxAttemptsExceededException('App\Mail\ResentMail has been attempted too many times.')));

        $fresh = $log->fresh();
        $this->assertTrue($fresh->status !== EmailLog::STATUS_FAILED || str_contains((string) $fresh->error, 'may have gone out'),
            "a row that may have gone out became a plain failed one: {$fresh->error}");
    }

    /** Sanity (passes): the relation manager hides Resend from staff without the permission. */
    public function test_sanity_relation_manager_resend_needs_the_permission(): void
    {
        MailFacade::fake();
        [$log] = $this->loggedMail();
        $viewer = $this->staff(['admin.email_logs.viewAny', 'admin.email_logs.view', 'admin.clients.viewAny', 'admin.clients.update', 'admin.clients.view']);

        $this->livewireAs($viewer)->test(EmailLogsRelationManager::class, ['ownerRecord' => $this->user->client(), 'pageClass' => EditClient::class])
            ->assertTableActionHidden('resend', $log);
    }

    /** Sanity (passes): «Last mail failed» on the invoice list works on SQLite. */
    public function test_sanity_invoice_last_mail_failed_filter(): void
    {
        MailFacade::fake();
        $other = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        EmailLog::query()->create(['subject' => 'a', 'to' => 'x@example.test', 'body' => 'b', 'status' => EmailLog::STATUS_FAILED, 'related_type' => $this->invoice->getMorphClass(), 'related_id' => $this->invoice->id]);
        EmailLog::query()->create(['subject' => 'a', 'to' => 'x@example.test', 'body' => 'b', 'status' => EmailLog::STATUS_FAILED, 'related_type' => $other->getMorphClass(), 'related_id' => $other->id]);
        EmailLog::query()->create(['subject' => 'a', 'to' => 'x@example.test', 'body' => 'b', 'status' => EmailLog::STATUS_SENT, 'related_type' => $other->getMorphClass(), 'related_id' => $other->id]);

        $this->livewireAs($this->staff(['*']))->test(ListInvoices::class)->filterTable('mail_failed')
            ->assertCanSeeTableRecords([$this->invoice])->assertCanNotSeeTableRecords([$other]);
    }
}
