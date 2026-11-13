<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\TicketDepartment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Tests\Eml;
use Symfony\Component\Mime\Email;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/**
 * Adversarial review of #833. «Known address» is not «our customer's address»: anyone may register a login with an
 * address that is not theirs (unverified, mail_must_verify off by default; InboundRouter::routes says so), and any
 * customer may add a contact with any address, unverified and unlimited. Each such address then gets an unvouched
 * «we received it» a day for a forged mail: backscatter to strangers, the very thing the guard is for.
 */
class Rev833Test extends TestCase
{
    use RefreshDatabase;

    private FakeTicketMailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD']);
        $support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
        $this->mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $this->mailbox);
    }

    /** @return Collection<int, Email> */
    private function received(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($message) => $message->getOriginalMessage())
            ->filter(fn (Email $email) => $email->getHeaders()->get('Auto-Submitted')?->getBodyAsString() === 'auto-replied')->values();
    }

    private function forged(string $from, string $id): void
    {
        $this->mailbox->unseen[] = Eml::make(['From' => $from, 'Message-ID' => "<{$id}@example.org>", 'Subject' => 'Hi',
            'Authentication-Results: mymail.myip.test; iprev=pass']);
    }

    /** REV-833-1: a login whose address was never proven (self-registered with a stranger's address) is no customer's. */
    public function test_rev_833_1_no_unvouched_ack_to_an_unverified_login(): void
    {
        User::factory()->unverified()->create(['email' => 'victim@stranger.example', 'imported_email_at' => null]);
        $this->forged('victim@stranger.example', 'u1');
        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame([], $this->received()->map(fn (Email $e) => $e->getTo()[0]->getAddress())->all(),
            'an unverified login is anyone\'s claim to an address: forged mail from it must not make us mail it');
    }

    /** REV-833-1: a contact is whatever address a customer typed in (client area «Contacts»), unverified and unlimited. */
    public function test_rev_833_1_no_unvouched_ack_to_an_address_a_customer_typed_in_as_a_contact(): void
    {
        $client = Client::factory()->create();
        foreach (['a@stranger.example', 'b@stranger.example', 'c@stranger.example'] as $address) {
            $client->contacts()->create(['first_name' => 'X', 'last_name' => 'Y', 'email' => $address]);
        }
        $this->forged('a@stranger.example', 'c1');
        $this->forged('b@stranger.example', 'c2');
        $this->forged('c@stranger.example', 'c3');
        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame([], $this->received()->map(fn (Email $e) => $e->getTo()[0]->getAddress())->all(),
            'one customer adding N contacts makes us mail N strangers a day');
    }

    /** REV-833-2: an archived contact is no longer the account's address. */
    public function test_rev_833_2_no_unvouched_ack_to_an_archived_contact(): void
    {
        $client = Client::factory()->create();
        $contact = $client->contacts()->create(['first_name' => 'X', 'last_name' => 'Y', 'email' => 'gone@stranger.example']);
        $contact->archive();
        $this->forged('gone@stranger.example', 'a1');
        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame([], $this->received()->map(fn (Email $e) => $e->getTo()[0]->getAddress())->all());
    }
}
