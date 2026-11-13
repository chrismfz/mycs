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
use MyCS\TicketMail\Dkim;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\MessageId;
use MyCS\TicketMail\Tests\DkimSigner;
use MyCS\TicketMail\Tests\Eml;
use Symfony\Component\Mime\Email;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/**
 * Our own DKIM check in the router (docs/tickets.md §3 Β): a receiving server that vouches for little (orion's exim: iprev
 * on top) no longer leaves every sender unvouched. A valid signature of the From's domain vouches as our server's DMARC
 * pass would: «we received it», replies with «Require DMARC», a ticket in the account; a broken one vouches for nothing.
 */
class TicketMailDkimTest extends TestCase
{
    use RefreshDatabase;

    private FakeTicketMailbox $mailbox;

    private TicketDepartment $support;

    private DkimSigner $signer;

    /** @var array<string, list<string>|null> */
    private array $dns = [];

    private int $lookups = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test']);
        $this->mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $this->mailbox);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'orion.myip.test', 'require_dmarc' => true, 'polling' => true]);
        $this->signer = DkimSigner::rsa();
        $this->dns = ['sel._domainkey.example.org' => [$this->signer->publicRecord]];
        $this->app->instance(Dkim::class, new Dkim(function (string $name) {
            $this->lookups++;

            return array_key_exists($name, $this->dns) ? $this->dns[$name] : [];
        }));
    }

    /** As orion's exim writes it: iprev alone in the header on top. */
    private function mail(string $from, string $id, array $headers = [], string $text = "Hello there\r\n"): string
    {
        return Eml::make($headers + ['Authentication-Results: orion.myip.test; iprev=pass (mx.example.org) smtp.remote-ip=192.0.2.1',
            'From' => $from, 'Message-ID' => "<{$id}@example.org>", 'Subject' => 'Our invoice'], $text);
    }

    private function signed(string $from, string $id, array $headers = [], string $text = "Hello there\r\n"): string
    {
        return $this->signer->sign($this->mail($from, $id, $headers, $text), d: 'example.org', headers: ['from', 'to', 'subject', 'message-id', 'in-reply-to']);
    }

    private function fetch(string ...$raws): void
    {
        array_push($this->mailbox->unseen, ...$raws);
        $this->artisan('app:fetch-emails')->assertSuccessful();
    }

    /** @return Collection<int, string> who got «we received it» */
    private function received(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($message) => $message->getOriginalMessage())
            ->filter(fn (Email $email) => $email->getHeaders()->get('Auto-Submitted')?->getBodyAsString() === 'auto-replied')
            ->map(fn (Email $email) => $email->getTo()[0]->getAddress())->values();
    }

    public function test_a_signed_stranger_gets_we_received_it_a_forged_or_unsigned_one_never(): void
    {
        $this->fetch(
            $this->signed('Accountant <accountant@example.org>', 'a1'),
            str_replace('Hello there', 'Hello forged', $this->signed('ceo@example.org', 'a2')),
            $this->mail('agency@example.org', 'a3'),
            $this->signed('victim@mail.example.org', 'a4'),
        );

        $this->assertSame(['accountant@example.org', 'victim@mail.example.org'], $this->received()->all(), 'a parent d= vouches for a subdomain From');
        $this->assertSame(4, Ticket::query()->count(), 'every mail is a ticket, as before');
        $this->assertSame(0, TicketQuarantine::query()->count());
    }

    public function test_a_signed_customer_opens_the_ticket_in_their_account(): void
    {
        $user = User::factory()->create(['email' => 'eleni@example.org', 'email_verified_at' => now()]);

        $this->fetch($this->signed('eleni@example.org', 'c1'), $this->mail('eleni@example.org', 'c2'));

        $this->assertSame([$user->client()->id, null], Ticket::query()->orderBy('id')->pluck('client_id')->all(), 'unsigned: a guest\'s ticket');
    }

    public function test_a_reply_with_require_dmarc_joins_when_signed_and_is_quarantined_with_both_reasons_when_not(): void
    {
        $ticket = Ticket::query()->create(['subject' => 'Help', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'department_id' => $this->support->id, 'guest_name' => 'Maria', 'guest_email' => 'maria@example.org', 'opened_via' => 'email']);
        $message = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'Our answer']);
        $key = MessageId::generate('myip.test');
        TicketMailId::query()->create(['ticket_id' => $ticket->id, 'ticket_message_id' => $message->id, 'message_id' => $key, 'kind' => TicketMailId::REPLY]);

        $this->fetch($this->signed('maria@example.org', 'r1', ['In-Reply-To' => "<{$key}>"], "Thanks, it works\r\n"));
        $this->assertSame('Thanks, it works', $ticket->messages()->latest('id')->first()->message);

        $this->fetch(str_replace('Thanks again', 'Send the password', $this->signed('maria@example.org', 'r2', ['In-Reply-To' => "<{$key}>"], "Thanks again\r\n")));
        $this->dns = ['sel._domainkey.example.org' => null];
        $this->fetch($this->signed('maria@example.org', 'r3', ['In-Reply-To' => "<{$key}>"]));

        $this->assertSame([['not_authenticated', 'no_aligned_pass+dkim_body_hash'], ['not_authenticated', 'no_aligned_pass+dkim_dns_failure']],
            TicketQuarantine::query()->orderBy('id')->get()->map(fn ($q) => [$q->reason, $q->auth_detail])->all());
        $this->assertFalse((bool) TicketQuarantine::query()->first()->authenticated);
    }

    public function test_our_servers_pass_needs_no_dns(): void
    {
        $this->fetch(Eml::make(['Authentication-Results: orion.myip.test; dmarc=pass header.from=example.org', 'From' => 'maria@example.org',
            'Message-ID' => '<p1@example.org>']));

        $this->assertSame(['maria@example.org'], $this->received()->all());
        $this->assertSame(0, $this->lookups);
    }
}
