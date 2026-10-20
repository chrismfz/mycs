<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\TicketPollRun;
use App\Models\TicketQuarantine;
use App\Models\User;
use App\Support\Tickets\InboundOutcome;
use App\Support\Tickets\InboundRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\InboundEmail;
use MyCS\TicketMail\MessageId;
use MyCS\TicketMail\Parser;
use MyCS\TicketMail\Tests\Eml;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/**
 * Mail into tickets, PR A2 (docs/tickets.md §3, §6): what the security model allows and what it keeps out, through the
 * poller with a fake mailbox and the real parser.
 */
class TicketMailInboundTest extends TestCase
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

    /** Our server vouches (or not) for the From, on top of the headers. */
    private function pass(string $domain = 'example.com'): string
    {
        return "Authentication-Results: mymail.myip.test; dkim=pass header.d={$domain}; dmarc=pass header.from={$domain}";
    }

    private function failed(string $domain = 'example.com'): string
    {
        return "Authentication-Results: mymail.myip.test; dkim=none; dmarc=fail header.from={$domain}";
    }

    /** @param  array<int|string, string>  $headers */
    private function receive(array $headers = [], string $text = 'Hello there', array $files = []): void
    {
        static $n = 0;
        $headers += ['Message-ID' => '<in' . (++$n) . '.' . bin2hex(random_bytes(4)) . '@example.com>'];
        $this->mailbox->unseen[] = Eml::make($headers, $text, $files);
        $this->artisan('app:fetch-emails')->assertSuccessful();
    }

    /** A ticket with a staff reply we mailed: its Message-ID is the thread key (A3 will write them). */
    private function answered(array $attributes): array
    {
        $ticket = Ticket::query()->create($attributes + ['subject' => 'Help', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'department_id' => $this->support->id]);
        $key = MessageId::generate('myip.test');
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id,
            'message' => 'Our answer', 'email_message_id' => $key]);

        return [$ticket->fresh(), $key];
    }

    private function guestTicket(): array
    {
        return $this->answered(['guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'form']);
    }

    private function quarantined(): TicketQuarantine
    {
        return TicketQuarantine::query()->latest('id')->firstOrFail();
    }

    public function test_a_new_mail_from_a_stranger_is_a_guests_ticket(): void
    {
        $this->receive(['From' => 'Maria <maria@example.com>', 'Subject' => 'Re: Fwd: My site is down', 'Cc' => 'boss@example.com'], 'It is down');

        $ticket = Ticket::query()->sole();
        $this->assertSame([null, 'Maria', 'maria@example.com', 'email', $this->support->id, 'My site is down', Ticket::STATUS_OPEN],
            [$ticket->client_id, $ticket->guest_name, $ticket->guest_email, $ticket->opened_via, $ticket->department_id, $ticket->subject, $ticket->status]);
        $message = $ticket->messages()->sole();
        $this->assertSame(['It is down', TicketMessage::VIA_EMAIL, null], [$message->message, $message->via, $message->user_id]);
        $this->assertSame(1, $this->mailbox->seen === [] ? 0 : count($this->mailbox->seen));
        $this->assertSame([1, 1, 0], [TicketPollRun::query()->sole()->fetched, TicketPollRun::query()->sole()->opened, TicketPollRun::query()->sole()->quarantined]);
    }

    /** §3 Δ: into an account only when our server vouches for a login's address; otherwise a guest's, which no account sees. */
    public function test_a_new_mail_from_a_login_goes_to_its_account_only_when_vouched_for(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com']);

        $this->receive(['From' => 'maria@example.com', $this->pass()]);
        $this->assertSame([$user->client()->id, $user->id], [Ticket::query()->sole()->client_id, Ticket::query()->sole()->messages()->sole()->user_id]);

        $this->receive(['From' => 'maria@example.com', $this->failed(), 'Subject' => 'Forged']);
        $forged = Ticket::query()->where('subject', 'Forged')->sole();
        $this->assertTrue($forged->isGuest(), 'the From alone proves nothing');
        $this->assertFalse($user->can('view', $forged));
    }

    public function test_the_owners_reply_with_our_message_id_joins_the_ticket(): void
    {
        [$ticket, $key] = $this->guestTicket();

        $this->receive(['From' => 'maria@example.com', $this->pass(), 'In-Reply-To' => "<{$key}>", 'Subject' => 'Re: Help'],
            "Thanks, it works now.\n\nOn Tue, 20 Oct 2026, Support wrote:\n> Our answer\n");

        $this->assertSame(Ticket::STATUS_CUSTOMER_REPLY, $ticket->fresh()->status);
        $reply = $ticket->messages()->latest('id')->first();
        $this->assertSame('Thanks, it works now.', $reply->message, 'the quoted history goes');
        $this->assertStringContainsString('> Our answer', (string) $reply->body_original);
        $this->assertSame(1, Ticket::query()->count());
    }

    public function test_an_account_tickets_reply_from_one_of_its_logins_joins_it(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $member = User::factory()->create(['email' => 'member@example.com']);
        $client = $owner->client();
        $client->grantTo($member, ['tickets']);
        [$ticket, $key] = $this->answered(['client_id' => $client->id]);

        $this->receive(['From' => 'member@example.com', $this->pass(), 'References' => "<x@y> <{$key}>"], 'From the member');

        $this->assertSame($member->id, $ticket->messages()->latest('id')->first()->user_id);
    }

    /** §3 Β: the owner's address, but our server did not vouch for it: quarantine, the ticket suggested. */
    public function test_a_forged_from_with_the_key_is_quarantined(): void
    {
        [$ticket, $key] = $this->guestTicket();

        $this->receive(['From' => 'maria@example.com', $this->failed(), 'In-Reply-To' => "<{$key}>"], 'Send the password to me');
        $this->receive(['From' => 'maria@example.com', 'In-Reply-To' => "<{$key}>"], 'No results at all');
        // The sender wrote a «pass» of their own, below ours.
        $this->receive(['From' => 'maria@example.com', $this->failed(), 'Authentication-Results: mymail.myip.test; dmarc=pass header.from=example.com',
            'In-Reply-To' => "<{$key}>"], 'Forged results');

        $this->assertSame(1, $ticket->messages()->count(), 'nothing joined');
        $this->assertSame(Ticket::STATUS_ANSWERED, $ticket->fresh()->status, 'still waiting on the customer');
        $this->assertSame(['not_authenticated', 'not_authenticated', 'not_authenticated'], TicketQuarantine::query()->pluck('reason')->all());
        $this->assertSame($ticket->id, $this->quarantined()->suggested_ticket_id);
    }

    public function test_a_server_without_results_threads_by_the_key_and_the_owner(): void
    {
        $this->support->update(['require_dmarc' => false, 'authserv_id' => null]);
        [$ticket, $key] = $this->guestTicket();

        $this->receive(['From' => 'maria@example.com', 'In-Reply-To' => "<{$key}>"], 'Reply');
        $this->assertSame(2, $ticket->messages()->count());

        // The key alone, from another: still no.
        $this->receive(['From' => 'eve@evil.test', 'In-Reply-To' => "<{$key}>"], 'Me too');
        $this->assertSame(2, $ticket->messages()->count());
        $this->assertSame('not_owner', $this->quarantined()->reason);
    }

    /** §3 Α: a guessed key (a number, the old `{id}@host`, our form but not ours) never threads. */
    public function test_a_guessed_message_id_never_threads(): void
    {
        [$ticket] = $this->guestTicket();
        $ourMessage = $ticket->messages()->sole();

        foreach (["<{$ourMessage->id}@myip.test>", "<{$ticket->id}@myip.test>", '<tk.0123456789abcdef0123456789abcdef@myip.test>', "<{$ticket->reference}>"] as $guess) {
            $this->receive(['From' => 'maria@example.com', $this->pass(), 'In-Reply-To' => $guess, 'References' => $guess], 'Guess');
        }

        $this->assertSame(1, $ticket->messages()->count());
        $this->assertSame(5, Ticket::query()->count(), 'each a new ticket of its own');
    }

    /** The Message-ID of a mail that came in is the sender's choice: never a key, also when it looks like ours. */
    public function test_an_inbound_message_id_is_never_a_thread_key(): void
    {
        $planted = MessageId::generate('myip.test');
        $this->receive(['From' => 'eve@evil.test', 'Message-ID' => "<{$planted}>"], 'Planted');
        $this->receive(['From' => 'eve@evil.test', 'In-Reply-To' => "<{$planted}>"], 'Again');

        $this->assertSame(2, Ticket::query()->count());
    }

    /** §3 Γ: a stranger with the key (Cc'd once, a forward) does not get in; To and Cc never join anyone. */
    public function test_cc_injection_is_refused(): void
    {
        [$ticket, $key] = $this->guestTicket();

        $this->receive(['From' => 'eve@evil.test', $this->pass('evil.test'), 'In-Reply-To' => "<{$key}>", 'Cc' => 'maria@example.com'], 'Let me in');
        $this->receive(['From' => 'maria@example.com', $this->pass(), 'In-Reply-To' => "<{$key}>", 'Cc' => 'eve@evil.test'], 'Mine');
        $this->receive(['From' => 'eve@evil.test', $this->pass('evil.test'), 'In-Reply-To' => "<{$key}>"], 'Now me');

        $this->assertSame(['Our answer', 'Mine'], $ticket->messages()->pluck('message')->all());
        $this->assertSame(['not_owner', 'not_owner'], TicketQuarantine::query()->pluck('reason')->all());
    }

    public function test_a_login_without_the_tickets_permission_or_of_another_account_is_no_owner(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $billing = User::factory()->create(['email' => 'billing@example.com']);
        $owner->client()->grantTo($billing, ['invoices']);
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);
        [$ticket, $key] = $this->answered(['client_id' => $owner->client()->id]);

        $this->receive(['From' => 'billing@example.com', $this->pass(), 'In-Reply-To' => "<{$key}>"], 'No permission');
        $this->receive(['From' => 'stranger@example.com', $this->pass(), 'In-Reply-To' => "<{$key}>"], 'Another account');

        $this->assertSame(1, $ticket->messages()->count());
        $this->assertSame(['not_owner', 'not_owner'], TicketQuarantine::query()->pluck('reason')->all());
        $this->assertNotNull($stranger);
    }

    /** §5α: naming a ticket without our key (an old WHMCS mail): quarantine, suggested only for its owner. */
    public function test_a_ticket_named_in_the_subject_without_the_key_waits_for_staff(): void
    {
        [$ticket] = $this->guestTicket();

        $this->receive(['From' => 'eve@evil.test', 'Subject' => "Re: [Ticket ID: {$ticket->reference}] Help"], 'Stranger');
        $this->assertSame(['no_thread_key', null], [$this->quarantined()->reason, $this->quarantined()->suggested_ticket_id]);

        $this->receive(['From' => 'maria@example.com', $this->pass(), 'Subject' => "Re: [#{$ticket->reference}] Help"], 'Owner');
        $this->assertSame(['no_thread_key', $ticket->id], [$this->quarantined()->reason, $this->quarantined()->suggested_ticket_id]);

        $this->assertSame(1, $ticket->messages()->count());
        $this->assertSame(1, Ticket::query()->count());
    }

    /** §3 Ε: auto-replies, bounces, lists neither open nor reopen. */
    public function test_automated_mail_never_opens_or_reopens(): void
    {
        [$ticket, $key] = $this->guestTicket();
        $ticket->update(['status' => Ticket::STATUS_CLOSED]);

        $this->receive(['From' => 'maria@example.com', $this->pass(), 'In-Reply-To' => "<{$key}>", 'Auto-Submitted' => 'auto-replied'], 'I am away');
        $this->receive(['From' => 'MAILER-DAEMON@mx.example.com', 'Return-Path' => '<>'], 'Undelivered');
        $this->receive(['From' => 'news@example.com', 'List-Id' => '<news.example.com>'], 'Newsletter');
        $this->receive(['From' => 'maria@example.com', 'Precedence' => 'bulk'], 'Bulk');

        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->fresh()->status);
        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(['auto_submitted', 'null_sender', 'list', 'precedence'], TicketQuarantine::query()->pluck('auth_detail')->all());
    }

    /** The «ticket trick»: a third site's confirmation mail to our address becomes nobody's way in. */
    public function test_a_third_sites_confirmation_mail_gives_no_one_access(): void
    {
        $attacker = User::factory()->create(['email' => 'eve@evil.test']);

        $this->receive(['From' => 'Shop <no-reply@shop.test>', 'Subject' => 'Confirm your account'], 'Click https://shop.test/confirm/abc');
        $this->receive(['From' => 'Shop <hello@shop.test>', 'Subject' => 'Your login code'], 'Code 123456');

        $this->assertSame('automated', TicketQuarantine::query()->sole()->reason);
        $ticket = Ticket::query()->sole();
        $this->assertSame([null, 'hello@shop.test'], [$ticket->client_id, $ticket->guest_email]);
        $this->assertFalse($attacker->can('view', $ticket));
    }

    public function test_the_same_mail_twice_is_one(): void
    {
        $raw = Eml::make(['From' => 'maria@example.com', 'Message-ID' => '<same@example.com>']);
        $this->mailbox->unseen = [$raw, $raw];
        $this->artisan('app:fetch-emails')->assertSuccessful();
        $this->mailbox->unseen = [$raw];
        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame(1, Ticket::query()->count());
        $this->assertCount(3, $this->mailbox->seen, 'a duplicate is read, never left to come back');
    }

    public function test_our_own_address_two_senders_no_sender_a_giant(): void
    {
        $this->receive(['From' => 'support@myip.test']);
        $this->receive(['From' => 'maria@example.com, eve@evil.test']);
        $this->receive(['From' => 'undisclosed-recipients:;']);
        $this->mailbox->unseen[] = 'not a mail at all';
        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(['own_address', 'multiple_from', 'no_sender'], array_slice(TicketQuarantine::query()->pluck('reason')->all(), 0, 3));
        $this->assertSame([], $this->mailbox->unseen, 'nothing wedges the mailbox');

        $giant = app(InboundRouter::class)->route($this->support, Parser::headersOnly("From: maria@example.com\r\nMessage-ID: <g@x>", 50_000_000), 'From: maria@example.com');
        $this->assertSame('too_large', $giant->quarantine?->reason);
    }

    public function test_blocked_senders_are_dropped(): void
    {
        TicketBlockedSender::query()->create(['value' => 'Spam@Example.com']);
        TicketBlockedSender::query()->create(['value' => 'evil.test']);
        [$ticket, $key] = $this->guestTicket();
        $ticket->update(['guest_email' => 'spam@example.com']);

        $this->receive(['From' => 'spam@example.com', $this->pass(), 'In-Reply-To' => "<{$key}>"]);
        $this->receive(['From' => 'eve@evil.test']);
        $this->receive(['From' => 'eve@sub.evil.test'], 'A subdomain is not the domain');

        $this->assertSame(1, $ticket->messages()->count());
        $this->assertSame(0, TicketQuarantine::query()->count());
        $this->assertSame(['eve@sub.evil.test'], Ticket::query()->whereKeyNot($ticket->id)->pluck('guest_email')->all());
    }

    public function test_a_clients_only_department_takes_no_strangers(): void
    {
        $this->support->update(['clients_only' => true]);

        $this->receive(['From' => 'maria@example.com']);

        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame('clients_only', $this->quarantined()->reason);
    }

    public function test_one_sender_opens_at_most_ten_tickets_an_hour(): void
    {
        RateLimiter::clear('ticket-mail-new:maria@example.com');
        for ($i = 0; $i < 11; $i++) {
            $this->receive(['From' => 'maria@example.com', 'Subject' => "Mail {$i}"]);
        }

        $this->assertSame(InboundRouter::NEW_TICKETS_PER_HOUR, Ticket::query()->count());
        $this->assertSame('rate_limited', $this->quarantined()->reason);
    }

    public function test_files_follow_the_rules(): void
    {
        $this->receive(['From' => 'maria@example.com'], 'See attached', [
            ['name' => 'invoice.pdf', 'content' => Eml::pdf()],
            ['name' => 'virus.exe', 'content' => "MZ\x90"],
            ['name' => 'fake.pdf', 'content' => '<script>alert(1)</script>'],
        ]);

        $message = Ticket::query()->sole()->messages()->sole();
        $file = $message->attachments()->sole();
        $this->assertSame(['invoice.pdf', 'application/pdf'], [$file->filename, $file->mime_type]);
        $this->assertMatchesRegularExpression('#^tickets/uploads/[0-9A-Z]{26}\.pdf$#', $file->path);
        $this->assertStringContainsString('virus.exe, fake.pdf', $message->message);
        @unlink($file->localPath);
    }

    public function test_a_failing_mail_stays_unread_and_stops_no_other(): void
    {
        $this->app->instance(InboundRouter::class, new class extends InboundRouter
        {
            public function route(TicketDepartment $department, ?InboundEmail $email, string $raw): InboundOutcome
            {
                if ($email?->subject === 'boom') {
                    throw new \RuntimeException('database gone');
                }

                return parent::route($department, $email, $raw);
            }
        });
        $this->mailbox->unseen = [Eml::make(['Subject' => 'one', 'Message-ID' => '<1@x>']), Eml::make(['Subject' => 'boom', 'Message-ID' => '<2@x>']),
            Eml::make(['Subject' => 'three', 'Message-ID' => '<3@x>'])];

        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame(['one', 'three'], Ticket::query()->orderBy('id')->pluck('subject')->all());
        $this->assertCount(1, $this->mailbox->unseen);
        $this->assertSame(['database gone'], TicketPollRun::query()->sole()->errors);
    }

    public function test_a_department_without_polling_or_a_login_is_not_read(): void
    {
        $this->support->update(['polling' => false]);
        $this->mailbox->unseen = [Eml::make()];

        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame(0, TicketPollRun::query()->count());
        $this->assertCount(1, $this->mailbox->unseen);
    }

    public function test_the_password_is_encrypted_and_never_audited(): void
    {
        $stored = (string) \DB::table('ticket_departments')->where('id', $this->support->id)->value('imap_password');

        $this->assertNotSame('secret', $stored);
        $this->assertSame('secret', $this->support->fresh()->imap_password);
        $this->assertArrayNotHasKey('imap_password', $this->support->fresh()->toArray());
        $this->assertStringNotContainsString('secret', $stored);
        $this->assertContains('imap_password', $this->support->getAuditExclude());
    }
}
