<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketQuarantine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Tests\Eml;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/**
 * Sweep 6, adversarial verifier for tickets-mail: failing tests for what the inbound router gets wrong.
 */
class Sweep6TicketsMailTest extends TestCase
{
    use RefreshDatabase;

    private FakeTicketMailbox $mailbox;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $this->mailbox);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
    }

    private function pass(string $domain): string
    {
        return "Authentication-Results: mymail.myip.test; dkim=pass header.d={$domain}; dmarc=pass header.from={$domain}";
    }

    /** @param  array<int|string, string>  $headers */
    private function receive(array $headers, string $text = 'Hello there'): void
    {
        $headers += ['Message-ID' => '<s6.' . bin2hex(random_bytes(6)) . '@example.com>'];
        $this->mailbox->unseen[] = Eml::make($headers, $text);
        $this->artisan('app:fetch-emails')->assertSuccessful();
    }

    /**
     * S6-TICKETS-MAIL-1: a login whose address was never verified (anyone may register any unused address, and
     * mail_must_verify is off by default) receives the real owner's mailed-in ticket.
     */
    public function test_a_mailed_in_ticket_never_goes_to_a_login_whose_email_is_unverified(): void
    {
        $this->assertFalse((bool) config('settings.mail_must_verify'), 'the default');
        $squatter = User::factory()->unverified()->create(['email' => 'billing@victimco.example']);

        // The real owner of the address writes in; their own server's DMARC passes.
        $this->receive(['From' => 'Victim <billing@victimco.example>', 'Subject' => 'Our VPS root password', $this->pass('victimco.example')], 'Secret details');

        $ticket = Ticket::query()->sole();
        $this->assertTrue($ticket->isGuest(), 'an unverified login is not proof the sender is that login: a guest ticket expected');
        $this->assertFalse($squatter->can('view', $ticket), 'the squatter reads the stranger\'s support mail');
    }

    /**
     * S6-TICKETS-MAIL-3: the subject tag MyCS itself sends («[Ticket #REF]», EmailTemplateSeeder) is not recognised, so a
     * reply that lost its References opens a second ticket (and a «we received it») instead of going to the quarantine.
     */
    public function test_a_reply_with_our_own_subject_tag_and_no_thread_key_is_quarantined_with_the_ticket_suggested(): void
    {
        $owner = User::factory()->create(['email' => 'maria@example.com']);
        $ticket = Ticket::query()->create(['reference' => '123456', 'subject' => 'Problem', 'status' => Ticket::STATUS_OPEN,
            'priority' => 'medium', 'department_id' => $this->support->id, 'client_id' => $owner->client()->id]);

        $this->receive(['From' => 'maria@example.com', 'Subject' => 'Re: [Ticket #123456] Problem', $this->pass('example.com')], 'Still broken');

        $this->assertSame(1, Ticket::query()->count(), 'a second ticket was opened for a reply to ticket 123456');
        $quarantine = TicketQuarantine::query()->sole();
        $this->assertSame(['no_thread_key', $ticket->id], [$quarantine->reason, $quarantine->suggested_ticket_id]);
    }
}
