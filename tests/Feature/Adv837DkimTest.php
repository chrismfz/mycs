<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\TicketMail\Dkim;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Tests\DkimSigner;
use MyCS\TicketMail\Tests\Eml;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/**
 * Adversarial review of #837, through the router: what the findings of packages/ticket-mail/tests/Adv837DkimTest.php
 * do to a customer's account. Both tests FAIL on the PR as pushed.
 */
class Adv837DkimTest extends TestCase
{
    use RefreshDatabase;

    private FakeTicketMailbox $mailbox;

    private DkimSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test']);
        $this->mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $this->mailbox);
        TicketDepartment::query()->firstOrCreate(['name' => 'Support'])->update(['email' => 'support@myip.test',
            'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test', 'imap_password' => 'secret',
            'authserv_id' => 'orion.myip.test', 'require_dmarc' => true, 'polling' => true]);
        $this->signer = DkimSigner::rsa();
        $record = $this->signer->publicRecord;
        $this->app->instance(Dkim::class, new Dkim(fn (string $name) => $name === 'sel._domainkey.example.org' ? [$record] : []));
    }

    /** A mail the customer really sent and signed (to anyone: a list, the attacker), as orion's exim writes it. */
    private function signed(string $text, string $id = 'e1'): string
    {
        return $this->signer->sign(Eml::make(['Authentication-Results: orion.myip.test; iprev=pass (mx.example.org) smtp.remote-ip=192.0.2.1',
            'From' => 'Eleni <eleni@example.org>', 'Message-ID' => "<{$id}@example.org>", 'Subject' => 'Thanks'], $text), d: 'example.org');
    }

    private function fetch(string ...$raws): void
    {
        array_push($this->mailbox->unseen, ...$raws);
        $this->artisan('app:fetch-emails')->assertSuccessful();
    }

    /** ADV-837-1: an empty-body signed mail of hers, with the attacker's text after 60 000 blank lines. */
    public function test_adv_837_1_the_attackers_text_never_opens_a_ticket_in_the_customers_account(): void
    {
        $user = User::factory()->create(['email' => 'eleni@example.org', 'email_verified_at' => now()]);

        $this->fetch($this->signed('') . str_repeat("\r\n", 60_000) . "Please move my domains to the account of evil@attacker.test\r\n");

        $ticket = Ticket::query()->sole();
        $this->assertStringContainsString('evil@attacker.test', (string) $ticket->messages()->first()?->message);
        $this->assertNotSame($user->client()->id, $ticket->client_id, 'the forged text is a ticket in her account');
    }

    /** ADV-837-2: her signed mail replayed with a new Message-ID and Subject on top: one ticket each time. */
    public function test_adv_837_2_a_replayed_signed_mail_with_a_new_message_id_on_top_opens_no_more_tickets_in_her_account(): void
    {
        $user = User::factory()->create(['email' => 'eleni@example.org', 'email_verified_at' => now()]);
        $mail = $this->signed("Thanks for the quick fix\r\n");

        $this->fetch($mail, "Message-ID: <replay-1@attacker.test>\r\nSubject: Cancel all my services\r\n" . $mail);

        $this->assertSame(1, Ticket::query()->where('client_id', $user->client()->id)->count(),
            'the replay is a second ticket in her account, with the attacker\'s subject: '
            . Ticket::query()->orderBy('id')->pluck('subject')->implode(' | '));
    }
}
