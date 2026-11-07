<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use App\Models\TicketQuarantine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Tests\Eml;
use Symfony\Component\Mime\Email;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/** The fixes of the f-support findings (sweep 2), beyond the verifier's tests (Sweep2FSupportTest). */
class Sweep2FSupportFixesTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    private FakeTicketMailbox $mailbox;

    private const VOUCHED = 'Authentication-Results: mymail.myip.test; dkim=pass header.d=example.com; dmarc=pass header.from=example.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD',
            'settings.mail_disable' => false]);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $this->mailbox);
    }

    private function poll(): void
    {
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
    }

    /** @return Collection<int, Email> */
    private function sent(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($message) => $message->getOriginalMessage());
    }

    public function test_turning_polling_on_sets_mail_since_once(): void
    {
        $this->freezeSecond(); // the column keeps whole seconds
        $this->assertNull($this->support->mail_since);
        $this->support->update(['name' => 'Support desk']);
        $this->assertNull($this->support->fresh()->mail_since, 'not reading: no date');

        $this->poll();
        $since = $this->support->fresh()->mail_since;
        $this->assertTrue($since->equalTo(now()));

        $this->travel(3)->days();
        $this->support->update(['polling' => false]);
        $this->support->update(['polling' => true]);
        $this->assertTrue($this->support->fresh()->mail_since->equalTo($since), 'turned off and on, it keeps its date');
    }

    public function test_old_mail_goes_to_the_quarantine_new_mail_still_opens_a_ticket(): void
    {
        $this->freezeTime();
        $this->poll();
        $this->mailbox->unseen[] = Eml::make(['From' => 'maria@example.com', 'Message-ID' => '<old@example.com>', 'Subject' => 'Old',
            'Date' => now()->subDay()->toRfc2822String(), self::VOUCHED]);
        $this->mailbox->unseen[] = Eml::make(['From' => 'maria@example.com', 'Message-ID' => '<new@example.com>', 'Subject' => 'New',
            'Date' => now()->addMinute()->toRfc2822String(), self::VOUCHED]);

        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame(['New'], Ticket::query()->pluck('subject')->all());
        $quarantine = TicketQuarantine::query()->sole();
        $this->assertSame('before_mail_since', $quarantine->reason);
        $this->assertSame('Old', $quarantine->subject);
        $this->assertArrayHasKey('before_mail_since', TicketQuarantine::REASONS);
        $this->assertSame([], $this->mailbox->unseen, 'both read: the old one is kept in the quarantine, not left to block the mailbox');
        $this->assertSame(1, TicketMailId::query()->where('kind', TicketMailId::RECEIVED)->count(), 'one «we received it», for the new mail');
    }

    public function test_an_old_reply_to_a_ticket_is_not_added_to_it(): void
    {
        $this->poll();
        $ticket = Ticket::query()->create(['subject' => 'Help', 'status' => Ticket::STATUS_ANSWERED, 'priority' => 'medium',
            'department_id' => $this->support->id, 'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'form']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'Answer']);
        $ours = TicketMailId::query()->where('ticket_id', $ticket->id)->latest('id')->value('message_id');
        $this->assertNotNull($ours);
        $this->mailbox->unseen[] = Eml::make(['From' => 'maria@example.com', 'Message-ID' => '<r1@example.com>', 'Subject' => 'Re: Help',
            'In-Reply-To' => "<{$ours}>", 'References' => "<{$ours}>", 'Date' => now()->subMonth()->toRfc2822String(), self::VOUCHED]);

        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame(1, $ticket->messages()->count(), 'no reply added from a month ago');
        $this->assertSame('before_mail_since', TicketQuarantine::query()->sole()->reason);
    }

    public function test_a_department_without_mail_since_reads_every_unseen_mail(): void
    {
        $this->poll();
        $this->support->forceFill(['mail_since' => null])->saveQuietly();
        $this->mailbox->unseen[] = Eml::make(['From' => 'maria@example.com', 'Message-ID' => '<old@example.com>', 'Subject' => 'Old',
            'Date' => now()->subYear()->toRfc2822String(), self::VOUCHED]);

        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame(['Old'], Ticket::query()->pluck('subject')->all(), 'as before: staff emptied the date');
    }

    public function test_reply_to_prefers_the_department_address_over_its_login(): void
    {
        $this->poll();
        $this->support->update(['email' => 'help@myip.test']);
        $ticket = Ticket::query()->create(['subject' => 'Help', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'department_id' => $this->support->id, 'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'form']);

        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'We fixed it.']);

        $mail = $this->sent()->filter(fn (Email $email) => ($email->getTo()[0] ?? null)?->getAddress() === 'maria@example.com')->sole();
        $this->assertSame(['help@myip.test'], array_map(fn ($address) => $address->getAddress(), $mail->getReplyTo()));
    }

    public function test_a_department_with_no_address_and_a_login_that_is_none_has_no_reply_to(): void
    {
        $this->poll();
        $this->support->update(['email' => null, 'imap_username' => 'support']);
        $ticket = Ticket::query()->create(['subject' => 'Help', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'department_id' => $this->support->id, 'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'form']);

        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'We fixed it.']);

        $mail = $this->sent()->filter(fn (Email $email) => ($email->getTo()[0] ?? null)?->getAddress() === 'maria@example.com')->sole();
        $this->assertSame([], $mail->getReplyTo());
    }

    /**
     * F-SUPPORT-1. The auto-close reads each ticket again under its lock: one whose status changed after the list was read
     * (the customer answered) is not closed on a stale copy.
     */
    public function test_auto_close_reads_the_status_again_before_closing(): void
    {
        config(['settings.cronjob_close_ticket' => 5]);
        $staff = Staff::factory()->admin()->create();
        $client = User::factory()->create()->client();
        [$first, $second] = [Ticket::factory()->create(['client_id' => $client->id, 'status' => Ticket::STATUS_OPEN]),
            Ticket::factory()->create(['client_id' => $client->id, 'status' => Ticket::STATUS_OPEN])];
        $this->travel(-10)->days();
        foreach ([$first, $second] as $ticket) {
            TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => $staff->id, 'message' => 'Answer']);
        }
        $this->travelBack();
        $this->assertSame(Ticket::STATUS_ANSWERED, $second->fresh()->status);

        // While the cron closes the first, the second turns to the customer's (their reply came in meanwhile).
        Ticket::updated(function (Ticket $ticket) use ($first, $second) {
            if ($ticket->id === $first->id && $ticket->status === Ticket::STATUS_CLOSED) {
                DB::table('tickets')->where('id', $second->id)->update(['status' => Ticket::STATUS_CUSTOMER_REPLY]);
            }
        });

        Artisan::call('app:cron-job');

        $this->assertSame(Ticket::STATUS_CLOSED, $first->fresh()->status);
        $this->assertSame(Ticket::STATUS_CUSTOMER_REPLY, $second->fresh()->status, 'never closed on the stale copy');
    }
}
