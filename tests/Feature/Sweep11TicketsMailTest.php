<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketBlockedSender;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use App\Models\TicketQuarantine;
use App\Models\User;
use App\Support\Tickets\QuarantineActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\MessageId;
use MyCS\TicketMail\Tests\Eml;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/**
 * Sweep 11, tickets-mail (adversarial verifier). S11-TICKETS-MAIL-1: the quarantine's «Block the sender» has none of
 * the refusals of the ticket list's «Block sender & Delete» (a customer's login, one of our addresses).
 */
class Sweep11TicketsMailTest extends TestCase
{
    use RefreshDatabase;

    private FakeTicketMailbox $mailbox;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $this->mailbox);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
    }

    /** @param  array<int|string, string>  $headers */
    private function receive(array $headers, string $text): void
    {
        static $n = 0;
        $headers += ['Message-ID' => '<s11in' . (++$n) . '.' . bin2hex(random_bytes(4)) . '@example.com>'];
        $this->mailbox->unseen[] = Eml::make($headers, $text);
        $this->artisan('app:fetch-emails')->assertSuccessful();
    }

    /**
     * A customer's out-of-office sits in the quarantine; staff click «Block the sender». The ticket list refuses to
     * block a customer's login; here it goes through, and her next genuine reply (our key, DMARC pass) is dropped:
     * no message, no quarantine row.
     */
    public function test_the_quarantine_does_not_block_a_customers_login(): void
    {
        $this->markTestIncomplete('known (2026-11-12): the quarantine\'s «Block the sender» has no guard for a customer\'s login or our own address; watched live');
        $pass = 'Authentication-Results: mymail.myip.test; dkim=pass header.d=example.com; dmarc=pass header.from=example.com';
        $user = User::factory()->create(['email' => 'maria@example.com']);
        $staff = Staff::factory()->admin()->create();
        $ticket = Ticket::query()->create(['subject' => 'Help', 'status' => Ticket::STATUS_ANSWERED, 'priority' => 'medium',
            'department_id' => $this->support->id, 'client_id' => $user->client()->id]);
        $key = MessageId::generate('myip.test');
        $answer = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => $staff->id, 'message' => 'Our answer']);
        TicketMailId::query()->create(['ticket_id' => $ticket->id, 'ticket_message_id' => $answer->id, 'message_id' => $key, 'kind' => TicketMailId::REPLY]);

        $this->receive(['From' => 'maria@example.com', $pass, 'In-Reply-To' => "<{$key}>", 'Auto-Submitted' => 'auto-replied'], 'I am away until Monday');
        $row = TicketQuarantine::query()->sole();
        $this->assertSame('automated', $row->reason);

        $refused = false;
        try {
            app(QuarantineActions::class)->block($row, $staff);
        } catch (InvalidArgumentException) {
            $refused = true;
        }

        // Her real reply, a week later.
        $this->receive(['From' => 'maria@example.com', $pass, 'In-Reply-To' => "<{$key}>", 'Subject' => 'Re: Help'], 'Back now: it still does not work.');

        $this->assertSame(1, TicketQuarantine::query()->count(), 'nor is it kept for staff');
        $this->assertSame(2, $ticket->messages()->count(), 'her genuine reply is dropped: no message, no quarantine row');
        $this->assertTrue($refused, 'a customer\'s login is blocked from the quarantine without a word (the ticket list refuses it)');
    }

    /** The same for one of our own addresses: the ticket list refuses it, the quarantine blocks it. */
    public function test_the_quarantine_does_not_block_one_of_our_addresses(): void
    {
        $this->markTestIncomplete('known (2026-11-12): the quarantine\'s «Block the sender» has no guard for a customer\'s login or our own address; watched live');
        $staff = Staff::factory()->admin()->create();
        $this->receive(['From' => 'support@myip.test', 'Subject' => 'Loop'], 'A mail in our own name');
        $row = TicketQuarantine::query()->sole();
        $this->assertSame('own_address', $row->reason);

        try {
            app(QuarantineActions::class)->block($row, $staff);
        } catch (InvalidArgumentException) {
        }

        $this->assertFalse(TicketBlockedSender::blocks('support@myip.test'), 'our own department address is now a blocked sender');
    }
}
