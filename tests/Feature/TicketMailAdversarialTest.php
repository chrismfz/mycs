<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\TicketQuarantine;
use App\Models\User;
use App\Support\Tickets\InboundOutcome;
use App\Support\Tickets\InboundRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use MyCS\TicketMail\AuthenticationResults;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\InboundEmail;
use MyCS\TicketMail\MessageId;
use MyCS\TicketMail\Tests\Eml;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/**
 * Adversarial review of PR #244 (A2, mail into tickets). Each test is a finding and fails on the PR as it stands.
 */
class TicketMailAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private FakeTicketMailbox $mailbox;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $this->mailbox);
        $this->support = TicketDepartment::query()->where('name', 'Support')->firstOrFail();
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
    }

    /** @param  array<int|string, string>  $headers */
    private function receive(array $headers = [], string $text = 'Hello there', array $files = []): void
    {
        $headers += ['Message-ID' => '<adv.' . bin2hex(random_bytes(6)) . '@example.com>'];
        $this->mailbox->unseen[] = Eml::make($headers, $text, $files);
        $this->artisan('app:fetch-emails')->assertSuccessful();
    }

    /**
     * F1 (High if the MTA echoes the envelope sender as it came): AuthenticationResults::parse() splits on «;» without
     * honouring quoted strings. The envelope sender is the attacker's: `MAIL FROM:<"x;dmarc=pass header.from=example.com
     * y"@evil.test>` is a legal quoted local part, SPF passes for evil.test, and our server writes it into smtp.mailfrom.
     * The split turns it into a «dmarc=pass header.from=example.com» of our own server: a forged From goes into the
     * victim's account.
     */
    public function test_a_quoted_envelope_sender_cannot_forge_our_servers_dmarc_pass(): void
    {
        $header = 'mymail.myip.test; spf=pass smtp.mailfrom="x;dmarc=pass header.from=example.com y"@evil.test; dkim=none; dmarc=fail header.from=example.com';
        $this->assertFalse(AuthenticationResults::verdict([$header], 'mymail.myip.test', 'owner@example.com')->authenticated,
            'our server said dmarc=fail; the «pass» is inside the attacker\'s quoted envelope sender');

        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $this->receive(['From' => 'owner@example.com', "Authentication-Results: {$header}", 'Subject' => 'Change my contact email to eve@evil.test'], 'Thanks');

        $ticket = Ticket::query()->sole();
        $this->assertTrue($ticket->isGuest(), 'a forged From never lands in the account');
        $this->assertFalse($owner->can('view', $ticket));
    }

    /**
     * F2 (Medium, loses mail): a picture pasted in the body (Gmail, Outlook: inline, with a Content-ID) is dropped
     * without a word: no file, no «Files not kept». The screenshot of the error is what the customer sent.
     */
    public function test_a_pasted_screenshot_is_neither_kept_nor_told(): void
    {
        $this->receive(['From' => 'maria@example.com'], 'See the error in the screenshot', [
            ['name' => 'image001.png', 'type' => 'image/png', 'content' => Eml::png(), 'inline' => 'ii_abc123'],
        ]);

        $message = Ticket::query()->sole()->messages()->sole();
        $this->assertTrue($message->attachments()->exists() || str_contains($message->message, 'image001.png'),
            'the pasted screenshot is gone without a trace');
    }

    /**
     * F3 (Medium, availability): a mail the router fails on stays unread forever and is fetched again first, every run
     * (oldest, limit 50). Fifty of them and no new mail is ever read. There is no give-up (an attempt count, then the
     * quarantine). A real trigger on MariaDB is F4.
     */
    public function test_mail_that_always_fails_does_not_wedge_the_mailbox(): void
    {
        $this->app->instance(InboundRouter::class, new class extends InboundRouter
        {
            public function route(TicketDepartment $department, ?InboundEmail $email, string $raw): InboundOutcome
            {
                if ($email?->subject === 'poison') {
                    throw new \RuntimeException('always');
                }

                return parent::route($department, $email, $raw);
            }
        });
        for ($i = 0; $i < 50; $i++) {
            $this->mailbox->unseen[] = Eml::make(['Subject' => 'poison', 'Message-ID' => "<p{$i}@evil.test>", 'From' => 'eve@evil.test']);
        }
        $this->mailbox->unseen[] = Eml::make(['Subject' => 'Real customer', 'Message-ID' => '<real@example.com>', 'From' => 'maria@example.com']);

        for ($run = 0; $run < 5; $run++) {
            $this->artisan('app:fetch-emails')->assertSuccessful();
        }

        $this->assertSame(1, Ticket::query()->where('subject', 'Real customer')->count(), 'the real mail is never read');
    }

    /**
     * F4 (Medium on MariaDB, the production database; passes on SQLite): a Date the sender chose beyond 2038 (or before
     * 1970) is written into ticket_quarantine.sent_at, a TIMESTAMP: out of range, an exception, the mail left unread
     * (F3). Any quarantined mail (automated, no_sender, not_owner…) with such a Date throws every time.
     */
    public function test_a_date_out_of_a_timestamps_range_is_quarantined_not_an_error(): void
    {
        $this->receive(['From' => 'news@evil.test', 'List-Id' => '<x.evil.test>', 'Date' => 'Tue, 20 Oct 2099 10:00:00 +0000'], 'Bulk');
        $this->receive(['From' => 'news@evil.test', 'List-Id' => '<x.evil.test>', 'Date' => 'Thu, 01 Jan 1960 10:00:00 +0000'], 'Bulk');

        $this->assertSame(2, TicketQuarantine::query()->count(), (string) json_encode(\App\Models\TicketPollRun::query()->pluck('errors')));
        $this->assertSame([], $this->mailbox->unseen, 'nothing left to wedge the mailbox');
    }

    /**
     * F5 (Medium, a targeted DoS): the new-ticket rate limit counts the From before anyone vouched for it. Ten forged
     * mails «From: owner@example.com» (dmarc=fail) use up the customer's hour: their own, vouched mail is quarantined
     * as rate_limited instead of opening in their account.
     */
    public function test_forged_mail_does_not_use_up_the_real_senders_rate_limit(): void
    {
        RateLimiter::clear('ticket-mail-new:owner@example.com');
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        for ($i = 0; $i < InboundRouter::NEW_TICKETS_PER_HOUR; $i++) {
            $this->receive(['From' => 'owner@example.com', 'Authentication-Results: mymail.myip.test; dkim=none; dmarc=fail header.from=example.com',
                'Subject' => "Forged {$i}"], 'spam');
        }

        $this->receive(['From' => 'owner@example.com', 'Authentication-Results: mymail.myip.test; dkim=pass header.d=example.com; dmarc=pass header.from=example.com',
            'Subject' => 'My server is down'], 'Real');

        $real = Ticket::query()->where('subject', 'My server is down')->first();
        $this->assertNotNull($real, 'the real customer\'s mail was quarantined: ' . TicketQuarantine::query()->latest('id')->value('reason'));
        $this->assertSame($owner->client()->id, $real->client_id);
    }

    /**
     * F6 (Low): a blocked address comes back with a +tag (the same mailbox at most providers), also in a reply with the
     * ticket's key: the block holds only for the exact string.
     */
    public function test_a_blocked_address_is_not_back_with_a_plus_tag(): void
    {
        TicketBlockedSender::query()->create(['value' => 'spammer@example.com']);

        $this->receive(['From' => 'spammer+1@example.com', 'Subject' => 'Again']);

        $this->assertSame(0, Ticket::query()->count(), 'spammer+1@ is spammer@');
    }

    /**
     * F7 (Low today, for A3): threadKeys() takes any non-email message with a `tk.` id, internal notes included. A note
     * never leaves us, so its id must never be a thread key (one written into a note, or a note given an id by A3's
     * sender, would thread whoever has it).
     */
    public function test_an_internal_notes_id_is_no_thread_key(): void
    {
        $ticket = Ticket::query()->create(['subject' => 'Help', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'department_id' => $this->support->id, 'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'form']);
        $key = MessageId::generate('myip.test');
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id,
            'message' => 'Internal: customer is a reseller', 'is_internal' => true, 'email_message_id' => $key]);

        $this->assertFalse(TicketMessage::query()->threadKeys()->where('email_message_id', $key)->exists(), 'a note is never mailed, so never a key');
    }
}
