<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\BankAlerts\Authenticity;
use MyCS\TicketMail\AuthenticationResults;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Tests\Eml;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/**
 * Sweep 7, S7-TICKETS-MAIL-1: the Authentication-Results of our server, read strictly. The three «s7_1» tests are the
 * adversarial reviewer's (adv/sweep7-tickets-mail, tests/Feature/Sweep7TicketsMailTest.php), brought here as written.
 */
class Sweep7AuthenticationResultsTest extends TestCase
{
    use RefreshDatabase;

    private TicketDepartment $support;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD']);
        Setting::query()->updateOrCreate(['key' => 'captcha', 'settingable_type' => null], ['value' => 'disabled']);
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null], ['value' => '0']);
        $this->support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $this->support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);
        TicketDepartment::query()->where('id', '!=', $this->support->id)->update(['hidden' => true]);
    }

    /**
     * What a server that echoes the envelope sender in its SPF comment (rspamd: «domain of X designates Y as permitted
     * sender») writes for a mail that fails DKIM and DMARC for the From's domain and passes SPF for the attacker's own.
     */
    private static function echoed(string $authservId, string $fromDomain, string $envelopeSender): string
    {
        return "{$authservId}; dkim=none; dmarc=fail header.from={$fromDomain} (policy=none); "
            . "spf=pass ({$authservId}: domain of {$envelopeSender} designates 203.0.113.9 as permitted sender) smtp.mailfrom={$envelopeSender}";
    }

    /** S7-TICKETS-MAIL-1: a «)» in the envelope sender closes the server's comment, and what follows reads as its word. */
    public function test_s7_1_an_envelope_sender_that_closes_the_comment_does_not_vouch_for_the_from(): void
    {
        // The control: the same mail from an honest-looking envelope sender is not vouched for.
        $honest = self::echoed('mymail.myip.test', 'victim.example', 'bounce@evil.example');
        $this->assertFalse(AuthenticationResults::verdict([$honest], 'mymail.myip.test', 'owner@victim.example')->authenticated);

        $forged = self::echoed('mymail.myip.test', 'victim.example', '"a) smtp.mailfrom=victim.example ("@evil.example');
        $verdict = AuthenticationResults::verdict([$forged], 'mymail.myip.test', 'owner@victim.example');
        $this->assertFalse($verdict->authenticated, "SPF passed for evil.example only, yet the From is vouched for ({$verdict->reason})");
    }

    /** S7-TICKETS-MAIL-1, the bank's alerts: the same trick writes «dkim=pass» and «dmarc=pass» for the bank. */
    public function test_s7_1_an_envelope_sender_that_closes_the_comment_does_not_vouch_for_a_bank_alert(): void
    {
        $honest = self::echoed('mymail.myip.test', 'bank.example', 'bounce@evil.example');
        $this->assertFalse(Authenticity::verdict([$honest], [], [], [], 'mymail.myip.test', 'myip.test', 'alerts@bank.example')->authenticated);

        $forged = self::echoed('mymail.myip.test', 'bank.example', '"a) ; dkim=pass header.d=bank.example; dmarc=pass header.from=bank.example; x=y ("@evil.example');
        $verdict = Authenticity::verdict([$forged], [], [], [], 'mymail.myip.test', 'myip.test', 'alerts@bank.example');
        $this->assertFalse($verdict->authenticated, "nothing of the bank's passed, yet the alert is authentic ({$verdict->reason})");
    }

    /** S7-TICKETS-MAIL-1, end to end: the forged mail opens a ticket inside the customer's account, as their user. */
    public function test_s7_1_the_forged_mail_does_not_open_a_ticket_inside_the_customers_account(): void
    {
        $mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $mailbox);
        $user = User::factory()->create(['email' => 'owner@victim.example']);
        $user->client();

        $mailbox->unseen[] = Eml::make(['From' => 'owner@victim.example', 'Subject' => 'Change my email', 'Message-ID' => '<s7a@evil.example>',
            'Authentication-Results: ' . self::echoed('mymail.myip.test', 'victim.example', '"a) smtp.mailfrom=victim.example ("@evil.example')], 'Please change it');
        $this->artisan('app:fetch-emails')->assertSuccessful();

        $ticket = Ticket::query()->first();
        $this->assertNotNull($ticket, 'a guest ticket is what an unvouched mail from a login opens');
        $this->assertSame([null, false], [$ticket->client_id, (bool) $ticket->sender_vouched], 'an unvouched mail is never inside the account, nor vouched');
    }
}
