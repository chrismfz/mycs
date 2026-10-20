<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Tests\Eml;
use Symfony\Component\Mime\Email;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/**
 * Adversarial review of PR #249 (tickets A3, outbound mail). Each test fails on feat/tickets-a3 as pushed.
 */
class TicketMailOutboundAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    private FakeTicketMailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD',
            'settings.mail_disable' => false]);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
        $this->mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $this->mailbox);
    }

    /** @return Collection<int, Email> */
    private function sent(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($message) => $message->getOriginalMessage());
    }

    private function staffReply(Ticket $ticket, string $text = 'We fixed it.'): void
    {
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => $text]);
    }

    /** @return Collection<int, Email> */
    private function to(string $address): Collection
    {
        return $this->sent()->filter(fn (Email $email) => ($email->getTo()[0] ?? null)?->getAddress() === $address)->values();
    }

    /**
     * ADV-1. The customer's own Message-ID goes into In-Reply-To/References through addIdHeader, which runs it through
     * Symfony's Address (RFC 2822 addr-spec). MessageId::normalise() lets through ids Symfony refuses (`a@b@c`, `@x`,
     * `a..b@c`), so the listener throws in MessageSending: the staff reply is never delivered (the queued job fails on
     * every retry; with a sync queue the staff member's save errors). Anyone whose mail is the latest by-email message of
     * a ticket (its opener, or a quarantined mail staff released) silences every reply on it.
     */
    public function test_a_customer_message_id_symfony_refuses_never_stops_our_reply(): void
    {
        $this->mailbox->unseen[] = Eml::make(['From' => 'maria@example.com', 'Message-ID' => '<a@b@example.com>', 'Subject' => 'Site down',
            'Authentication-Results: mymail.myip.test; dmarc=fail header.from=example.com']);
        $this->artisan('app:fetch-emails')->assertSuccessful();
        $ticket = Ticket::query()->sole();
        $this->assertSame('a@b@example.com', $ticket->messages()->sole()->email_message_id);

        try {
            $this->staffReply($ticket);
        } catch (\Throwable $e) {
            $this->fail('the staff reply could not be mailed: ' . $e::class . ': ' . $e->getMessage());
        }
        $this->assertCount(1, $this->to('maria@example.com'), 'the guest gets the reply');
    }

    /**
     * ADV-2. Replies on a thread key are not rate limited (the 10/hour is only for NEW tickets), and every one now
     * mails every «Notify» staff member. One key (here the «we received it» an attacker gets for their own vouched
     * address) turns into an unbounded flood of notices: 50 mails per poll per department × N staff, every 5 minutes.
     */
    public function test_one_thread_key_never_floods_staff_with_notices(): void
    {
        $role = Role::create(['name' => 'Support', 'permissions' => ['admin.tickets.viewAny']]);
        $agents = collect(range(1, 3))->map(fn ($i) => Staff::factory()->create(['email' => "agent{$i}@myip.test", 'role_id' => $role->id]));
        $this->support->notifyStaff()->sync($agents->pluck('id')->all());

        $auth = 'Authentication-Results: mymail.myip.test; dmarc=pass header.from=attacker.example';
        $this->mailbox->unseen[] = Eml::make(['From' => 'a@attacker.example', 'Message-ID' => '<open@attacker.example>', 'Subject' => 'Hi', $auth]);
        $this->artisan('app:fetch-emails')->assertSuccessful();
        $key = TicketMailId::query()->where('kind', TicketMailId::RECEIVED)->sole()->message_id;

        for ($i = 0; $i < 40; $i++) {
            $this->mailbox->unseen[] = Eml::make(['From' => 'a@attacker.example', 'Message-ID' => "<r{$i}@attacker.example>",
                'In-Reply-To' => "<{$key}>", 'Subject' => 'Re: Hi', $auth], "spam {$i}");
        }
        $this->artisan('app:fetch-emails')->assertSuccessful();

        $notices = $this->sent()->filter(fn (Email $email) => str_ends_with((string) ($email->getTo()[0] ?? null)?->getAddress(), '@myip.test'));
        $this->assertLessThanOrEqual(3 * 10, $notices->count(), "staff got {$notices->count()} notices from one sender in one hour");
    }

    /**
     * ADV-3. Backscatter by staff: anyone can open a guest ticket with a forged From (no DMARC: it is still a guest
     * ticket, §3 Δ), with the subject and display name they like. The ticket keeps no trace that our server did not vouch
     * for the address, so staff cannot tell; their reply then mails the forged address, DKIM-signed by us, with the
     * attacker's subject in the Subject (`[Ticket #…] {{ ticket.subject }}`) and their display name in the greeting
     * (`Dear {{ ticket.guest_name }}`). The «we received it» was guarded against exactly this; the reply is not.
     */
    public function test_a_reply_to_an_unvouched_guest_never_carries_the_forgers_text(): void
    {
        $this->mailbox->unseen[] = Eml::make(['From' => '"Claim your prize at win.example" <victim@example.org>', 'Message-ID' => '<f1@example.org>',
            'Subject' => 'CHEAP PILLS http://win.example',
            'Authentication-Results: mymail.myip.test; dmarc=fail header.from=example.org']);
        $this->artisan('app:fetch-emails')->assertSuccessful();
        $ticket = Ticket::query()->sole();
        $this->assertTrue($ticket->isGuest());
        $this->assertCount(0, $this->to('victim@example.org'), 'no «we received it» to a forged From (held)');

        $this->staffReply($ticket, 'Sorry, we cannot help with that.');

        $mail = $this->to('victim@example.org')->first();
        if ($mail === null) {
            $this->assertTrue(true); // not mailed at all until staff confirm: fine

            return;
        }
        $text = (string) $mail->getSubject() . (string) $mail->getHtmlBody();
        $this->assertStringNotContainsString('win.example', $text, 'the forger\'s subject and name, mailed by us to the forged address');
    }

    /**
     * ADV-4. The staff notice is an automatic mail without Auto-Submitted (RFC 3834) or X-MyCS-Ticket: a staff member's
     * vacation responder (or a forwarder to the support mailbox) answers it like a person's mail. Every other automatic
     * mail of this PR is marked; this one is not.
     */
    public function test_the_staff_notice_is_marked_automatic(): void
    {
        $role = Role::create(['name' => 'Support', 'permissions' => ['admin.tickets.viewAny']]);
        $this->support->notifyStaff()->sync([Staff::factory()->create(['email' => 'agent@myip.test', 'role_id' => $role->id])->id]);

        $ticket = Ticket::query()->create(['subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium', 'department_id' => $this->support->id,
            'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'email']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Help', 'via' => TicketMessage::VIA_EMAIL, 'email_message_id' => 'orig@example.com']);

        $notice = $this->to('agent@myip.test')->sole();
        $this->assertSame('auto-generated', $notice->getHeaders()->get('Auto-Submitted')?->getBodyAsString());
    }
}
