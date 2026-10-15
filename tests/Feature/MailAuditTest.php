<?php

namespace Tests\Feature;

use App\Admin\RelationManagers\EmailLogsRelationManager;
use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\EmailLogResource\Pages\ListEmailLogs;
use App\Admin\Resources\EmailLogResource\Pages\ViewEmailLog;
use App\Helpers\NotificationHelper;
use App\Mail\ResentMail;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail as MailFacade;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * The mail audit (docs/mail.md): every mail's row says who it went to and from, which template, what it was about and
 * who sent it; the send keeps its way out (sending, sent with its Message-ID, never twice); a refused Cc/Bcc sends it
 * to the To only, a refused To fails it at once, anything else is retried; stuck rows are swept; staff resend.
 */
class MailAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Invoice $invoice;

    /** @var list<string> addresses the fake server refuses at RCPT */
    public static array $refuse = [];

    public static ?int $code = null;

    /** @var list<list<string>> envelopes the fake server took */
    public static array $delivered = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null, 'settingable_id' => null], ['value' => '0', 'type' => 'boolean']);
        config(['settings.mail_disable' => false, 'settings.mail_from_address' => 'billing@example.test']);
        self::$refuse = [];
        self::$code = null;
        self::$delivered = [];
        $this->user = User::factory()->create(['email' => 'owner@example.test']);
        $this->invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $this->invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
    }

    /** A server that refuses some recipients at RCPT, as Symfony's SMTP transport reports it. */
    private function fakeServer(): void
    {
        MailFacade::extend('fake-smtp', fn () => new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $envelope ??= Envelope::create($message);
                $recipients = array_map(fn ($address) => $address->getAddress(), $envelope->getRecipients());
                foreach ($recipients as $address) {
                    if (in_array($address, MailAuditTest::$refuse, true)) {
                        $code = MailAuditTest::$code ?? 550;
                        throw new UnexpectedResponseException("Expected response code \"250/251/252\" but got code \"{$code}\", with message \"{$code} 5.1.1 <{$address}>: Recipient address rejected\".", $code);
                    }
                }
                MailAuditTest::$delivered[] = $recipients;

                return new SentMessage($message, $envelope);
            }

            public function __toString(): string
            {
                return 'fake-smtp://';
            }
        });
        config(['mail.mailers.fake' => ['transport' => 'fake-smtp'], 'mail.default' => 'fake', 'queue.default' => 'sync']);
    }

    private function invoiceMail(array $cc = []): EmailLog
    {
        NotificationTemplate::query()->where('key', 'new_invoice_created')->update(['cc' => $cc]);
        NotificationHelper::invoiceCreatedNotification($this->user->client(), $this->invoice);

        return EmailLog::query()->where('template_key', 'new_invoice_created')->latest('id')->firstOrFail();
    }

    private function staff(array $permissions = ['*']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    public function test_the_row_says_from_whom_to_whom_which_template_and_about_what(): void
    {
        MailFacade::fake();

        $log = $this->invoiceMail(['accounts@example.test']);

        $this->assertSame(['owner@example.test', 'billing@example.test', ['accounts@example.test'], 'new_invoice_created', $this->user->client()->id],
            [$log->to, $log->from_address, $log->cc, $log->template_key, $log->client_id]);
        $this->assertSame('Invoice #' . $this->invoice->id, $log->relatedLabel());
        $this->assertSame([EmailLog::STATUS_PENDING, EmailLog::TRIGGER_AUTO], [$log->status, $log->trigger]);
        $this->assertSame($log->id, $this->invoice->fresh()->latestEmailLog->id);
    }

    public function test_the_send_keeps_its_way_out_and_never_sends_twice(): void
    {
        $this->fakeServer();

        $log = $this->invoiceMail()->fresh();

        $this->assertSame([EmailLog::STATUS_SENT, 1], [$log->status, $log->attempts]);
        $this->assertNotNull($log->sent_at);
        $this->assertNotEmpty($log->message_id);
        $this->assertCount(1, self::$delivered);

        $again = new ResentMail('x', 'y');
        $again->email_log_id = $log->id;
        $again->to('owner@example.test')->send(app('mailer'));
        $this->assertCount(1, self::$delivered, 'a sent row is not sent again (a retry, a duplicate job)');

        EmailLog::query()->whereKey($log->id)->update(['status' => EmailLog::STATUS_SENDING]);
        $again->send(app('mailer'));
        $this->assertCount(1, self::$delivered, 'nor one a worker left mid-send: it may have gone out');
    }

    public function test_a_refused_copy_sends_it_to_the_to_only_and_says_so(): void
    {
        $this->fakeServer();
        self::$refuse = ['gone@example.test'];

        $log = $this->invoiceMail(['gone@example.test'])->fresh();

        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertSame([['owner@example.test']], self::$delivered);
        $this->assertNull($log->cc);
        $this->assertStringContainsString('gone@example.test', (string) $log->error);
    }

    public function test_a_refused_to_fails_at_once_and_anything_else_is_retried(): void
    {
        $this->fakeServer();
        self::$refuse = ['owner@example.test'];

        $log = $this->invoiceMail()->fresh();
        $this->assertSame([EmailLog::STATUS_FAILED, 1], [$log->status, $log->attempts]);
        $this->assertStringContainsString('refused the recipient', (string) $log->error);

        self::$code = 451; // try again later: back to pending for the queue's next try
        $retry = EmailLog::query()->create(['subject' => 'x', 'to' => 'owner@example.test', 'body' => 'y']);
        $mail = new ResentMail('x', 'y');
        $mail->email_log_id = $retry->id;
        try {
            $mail->to('owner@example.test')->send(app('mailer'));
            $this->fail('a temporary refusal goes back to the queue');
        } catch (UnexpectedResponseException) {
        }
        $this->assertSame([EmailLog::STATUS_PENDING, 1], [$retry->fresh()->status, $retry->fresh()->attempts]);
        $this->assertStringContainsString('451', (string) $retry->fresh()->error);
    }

    public function test_stuck_mails_are_swept_as_failed(): void
    {
        MailFacade::fake();
        $stuck = $this->invoiceMail();
        $stuck->update(['status' => EmailLog::STATUS_SENDING, 'sending_at' => now()->subHour()]);
        $old = $this->invoiceMail();
        EmailLog::query()->whereKey($old->id)->update(['created_at' => now()->subDays(2)]);
        $fresh = $this->invoiceMail();

        $this->artisan('app:mail-sweep')->assertSuccessful();

        $this->assertSame([EmailLog::STATUS_FAILED, EmailLog::STATUS_FAILED, EmailLog::STATUS_PENDING],
            [$stuck->fresh()->status, $old->fresh()->status, $fresh->fresh()->status]);
        $this->assertStringContainsString('may have gone out', (string) $stuck->fresh()->error);
    }

    public function test_staff_resend_to_another_address_and_only_with_the_permission(): void
    {
        MailFacade::fake();
        $original = $this->invoiceMail(['accounts@example.test']);
        $staff = $this->staff();

        $this->livewireAs($staff)->test(ViewEmailLog::class, ['record' => $original->getRouteKey()])
            ->callAction('resend', ['to' => 'other@example.test', 'with_copies' => false])
            ->assertHasNoActionErrors();

        $resend = EmailLog::query()->where('resent_from_id', $original->id)->sole();
        $this->assertSame(['other@example.test', null, EmailLog::TRIGGER_MANUAL, $staff->id, $original->body, 'Invoice #' . $this->invoice->id],
            [$resend->to, $resend->cc, $resend->trigger, $resend->staff_id, $resend->body, $resend->relatedLabel()]);
        MailFacade::assertQueued(ResentMail::class, fn (ResentMail $mail) => $mail->hasTo('other@example.test') && $mail->email_log_id === $resend->id);

        $viewer = $this->staff(['admin.email_logs.viewAny', 'admin.email_logs.view']);
        $this->livewireAs($viewer)->test(ViewEmailLog::class, ['record' => $original->getRouteKey()])->assertActionHidden('resend');
    }

    public function test_the_list_filters_and_the_account_shows_its_mails(): void
    {
        MailFacade::fake();
        $failed = $this->invoiceMail();
        $failed->update(['status' => EmailLog::STATUS_FAILED]);
        $sent = $this->invoiceMail();
        $sent->update(['status' => EmailLog::STATUS_SENT]);
        $staff = $this->staff();

        $this->livewireAs($staff)->test(ListEmailLogs::class)
            ->filterTable('status', [EmailLog::STATUS_FAILED])
            ->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$sent]);
        $this->livewireAs($staff)->test(EmailLogsRelationManager::class, ['ownerRecord' => $this->user->client(), 'pageClass' => EditClient::class])
            ->assertCanSeeTableRecords([$failed, $sent]);
    }

    public function test_a_batch_resends_the_failed_once(): void
    {
        MailFacade::fake();
        $failed = $this->invoiceMail();
        $failed->update(['status' => EmailLog::STATUS_FAILED]);

        $this->artisan('app:mail-resend-failed')->assertSuccessful();
        $this->artisan('app:mail-resend-failed')->assertSuccessful();

        $this->assertSame([EmailLog::TRIGGER_BATCH], EmailLog::query()->where('resent_from_id', $failed->id)->pluck('trigger')->all());
    }
}
