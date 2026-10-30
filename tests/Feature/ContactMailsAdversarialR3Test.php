<?php

namespace Tests\Feature;

use App\Enums\NotificationEnabledStatus;
use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Livewire\Client\Account;
use App\Models\Audit;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketCc;
use App\Models\TicketDepartment;
use App\Models\TicketMailId;
use App\Models\TicketMessage;
use App\Models\TicketQuarantine;
use App\Models\User;
use App\Services\Mail\MailAudit;
use App\Services\Service\WelcomeMail;
use App\Support\CurrentClient;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use MyCS\TicketMail\Imap\Mailbox;
use MyCS\TicketMail\Tests\Eml;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\FakeTicketMailbox;
use Tests\TestCase;

/**
 * Round-3 adversarial review of #550 (7c547bad: the account's mails go to its primary contact's address,
 * Client::mailAddress, not to the owner's login). test_bug_* fail on that head; test_holds_* are guarantees attacked that
 * held. The account here: owner login owner@, primary contact books@ (an accountant made the account's address).
 */
class ContactMailsAdversarialR3Test extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT = 'books@example.test';

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['settings.mail_disable' => false, 'settings.default_currency' => 'USD']);
        (new EmailTemplateSeeder)->run();
        $this->owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->client = $this->owner->client();
        $this->client->primaryContact->update(['email' => self::ACCOUNT]);
        $this->client = $this->client->fresh();
        $this->clean();
    }

    private function clean(): void
    {
        EmailLog::query()->delete();
        Notification::query()->delete();
        Mail::fake();
    }

    /** @return list<string> */
    private function mailedTo(?string $template = null): array
    {
        return EmailLog::query()->when($template, fn ($q) => $q->where('template_key', $template))->orderBy('id')->pluck('to')->all();
    }

    private function member(array $permissions, string $email = 'member@example.test'): User
    {
        $member = User::factory()->withoutClient()->create(['email' => $email]);
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $member->id, 'permissions' => $permissions, 'granted_at' => now()]);
        app(CurrentClient::class)->forget();
        $this->actingAs($member)->withSession($this->loginUser($member, [CurrentClient::SESSION_KEY => $this->client->id]));

        return $member;
    }

    private function activeService(): Service
    {
        Queue::fake();
        Http::preventStrayRequests();
        $server = Server::query()->create(['name' => 'Orion', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addMonth(),
            'domain' => 'example.test', 'username' => 'exampl', 'password' => 'Old-Pass-123']);
        $this->clean();

        return $service;
    }

    private function department(): TicketDepartment
    {
        $support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $support->update(['email' => 'support@myip.test', 'imap_host' => 'imap.myip.test', 'imap_username' => 'support@myip.test',
            'imap_password' => 'secret', 'authserv_id' => 'mymail.myip.test', 'require_dmarc' => true, 'polling' => true]);

        return $support;
    }

    private function accountTicket(TicketDepartment $support): Ticket
    {
        $ticket = Ticket::query()->create(['client_id' => $this->client->id, 'subject' => 'Help', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'department_id' => $support->id]);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'user_id' => $this->owner->id, 'message' => 'Please help']);

        return $ticket;
    }

    private function staffReply(Ticket $ticket): TicketMessage
    {
        return TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'We fixed it.']);
    }

    // ---- ADV-550-R3-1: the welcome resend sends the password, then says it did not, and the hourly limit is gone ------

    /**
     * WelcomeMail::send looks its email log up by `to = owner->email`; the mail now goes to the account's address, so the
     * lookup misses: the password went out, staff are told «not sent: its template could not be rendered», and no
     * `welcome_resent` audit is written, so the PER_HOUR limit (counted from those audits) never counts it.
     */
    public function test_bug_a_welcome_resend_to_the_accounts_address_is_reported_as_failed_and_not_limited(): void
    {
        $service = $this->activeService();
        $staff = Staff::factory()->admin()->create();

        $failures = 0;
        for ($i = 0; $i < WelcomeMail::PER_HOUR + 2; $i++) {
            try {
                WelcomeMail::resend($service, $staff);
            } catch (DisplayException) {
                $failures++;
            }
        }

        $sent = count($this->mailedTo(WelcomeMail::TEMPLATE));
        $this->assertLessThanOrEqual(WelcomeMail::PER_HOUR, $sent, "the password went out {$sent} times, past the hourly limit");
        $this->assertSame(WelcomeMail::PER_HOUR, Audit::query()->where('event', 'welcome_resent')->count(), 'each resend audited');
        $this->assertSame(2, $failures, 'only the ones past the limit are refused');
    }

    // ---- ADV-550-R3-2: staff confirm the welcome (with its password) to one address; it goes to another --------------

    /** EditService's «Resend welcome email» and «Then send the welcome…» name WelcomeMail::recipient: still the login. */
    public function test_bug_the_welcome_confirmation_names_the_login_while_the_password_goes_to_the_accounts_address(): void
    {
        $service = $this->activeService();

        NotificationHelper::serverCreatedNotification($this->client->fresh(), $service, resend: true);

        $this->assertSame([self::ACCOUNT], $this->mailedTo(WelcomeMail::TEMPLATE));
        $this->assertSame(self::ACCOUNT, WelcomeMail::recipient($service), 'staff confirm the address it really goes to');
    }

    // ---- ADV-550-R3-3: the ticket's mail goes to an address whose answer is quarantined -------------------------------

    /**
     * Staff's reply on an account's ticket now goes to the account's address (books@, no login). Its holder answers it,
     * with our thread key, DMARC-vouched; InboundRouter::owner accepts only a login with «tickets», so the answer is
     * quarantined as «not_owner». Before 7c547bad the mail went to the owner's login, whose answer joined the ticket.
     */
    public function test_bug_the_accounts_address_cannot_answer_the_ticket_mail_it_was_sent(): void
    {
        $mailbox = new FakeTicketMailbox;
        $this->app->instance(Mailbox::class, $mailbox);
        $support = $this->department();
        $ticket = $this->accountTicket($support);
        $this->clean();
        $reply = $this->staffReply($ticket);

        $this->assertSame([self::ACCOUNT], $this->mailedTo('new_ticket_message'), 'the reply went to the account\'s address');
        $key = TicketMailId::query()->where('ticket_message_id', $reply->id)->where('kind', TicketMailId::REPLY)->sole();

        $mailbox->unseen[] = Eml::make(['From' => self::ACCOUNT, 'Message-ID' => '<ans1@example.test>', 'In-Reply-To' => "<{$key->message_id}>",
            'Authentication-Results: mymail.myip.test; dkim=pass header.d=example.test; dmarc=pass header.from=example.test', 'Subject' => 'Re: Help'], 'Thanks!');
        $this->artisan('app:fetch-emails')->assertSuccessful();

        $this->assertSame(0, TicketQuarantine::query()->count(), 'quarantined: ' . TicketQuarantine::query()->value('reason'));
        $this->assertSame('Thanks!', $ticket->messages()->latest('id')->first()->message);
        $this->assertSame(self::ACCOUNT, $key->recipient, 'the thread key records where the mail went');
    }

    // ---- ADV-550-R3-4: a ticket's copies deduplicate against the login, not the address the reply went to --------------

    /**
     * TicketMailer::copies skips a CC equal to `client->owner()->email`. Staff copy the account's address (the customer's
     * accountant asked to be on it): that address gets the reply twice, with two thread keys.
     */
    public function test_bug_a_ticket_cc_at_the_accounts_address_gets_the_reply_twice(): void
    {
        $support = $this->department();
        $ticket = $this->accountTicket($support);
        TicketCc::query()->create(['ticket_id' => $ticket->id, 'email' => self::ACCOUNT]);
        $this->clean();

        $this->staffReply($ticket);

        $this->assertSame([self::ACCOUNT], array_values(array_filter($this->mailedTo(), fn ($to) => $to === self::ACCOUNT)),
            'one mail to the account\'s address, not the reply and a CC copy');
    }

    // ---- ADV-550-R3-5: a mass mail's «stop these emails» for the owner, mailed to someone else --------------------------

    /**
     * News carries the owner's signed unsubscribe link (also as List-Unsubscribe one-click), kept out of the log as the
     * owner's own («a member who reads the account's mails must not take the owner off the news», review of #311). It
     * now goes to the account's address: one click there (or a mail provider's one-click button) turns the owner's news
     * off on every account they own, and their in-app too.
     */
    public function test_bug_the_owners_unsubscribe_link_is_mailed_to_the_accounts_address(): void
    {
        $template = NotificationTemplate::where('key', 'announcement_news')->first();
        $this->assertNotNull($template);
        $url = URL::signedRoute('announcements.unsubscribe', ['user' => $this->owner->id]);

        NotificationHelper::announcementNotification($this->client->fresh(), 'announcement_news', ['subject' => 'News', 'content' => 'Hello',
            'unsubscribe_url' => $url, 'client' => $this->client, 'user' => $this->owner]);

        $to = $this->mailedTo('announcement_news');
        $this->assertNotSame([], $to);
        $this->assertSame(['owner@example.test'], $to, 'the owner\'s own «stop these emails» only to the owner');
    }

    // ---- held ------------------------------------------------------------------------------------------------------------

    public function test_holds_account_mail_goes_to_the_primary_and_the_in_app_to_the_owner(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $this->clean();

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $invoice->fresh());

        $this->assertSame([self::ACCOUNT], $this->mailedTo());
        $this->assertSame([$this->owner->id], Notification::query()->pluck('user_id')->unique()->values()->all());
    }

    public function test_holds_login_mails_go_to_the_login_not_the_accounts_address(): void
    {
        NotificationHelper::passwordResetNotification($this->owner, ['url' => 'https://x.test/reset/abc']);
        NotificationHelper::emailVerificationNotification($this->owner);
        NotificationHelper::loginDetectedNotification($this->owner, ['ip' => '192.0.2.1']);

        $this->assertNotContains(self::ACCOUNT, $this->mailedTo());
        $this->assertContains('owner@example.test', $this->mailedTo('password_reset'));
        $this->assertContains('owner@example.test', $this->mailedTo('email_verification'));
    }

    /** @return iterable<string, array{0: string|null}> */
    public static function badPrimaryEmails(): iterable
    {
        yield 'blank' => [''];
        yield 'null' => [null];
        yield 'not an address' => ['not-an-email'];
        yield 'spaces only' => ['   '];
    }

    #[DataProvider('badPrimaryEmails')]
    public function test_holds_a_primary_without_a_valid_email_falls_back_to_the_login(?string $email): void
    {
        $this->client->primaryContact->forceFill(['email' => $email])->saveQuietly();

        $this->assertSame('owner@example.test', $this->client->fresh()->mailAddress());
    }

    public function test_holds_a_contact_at_the_accounts_address_in_another_case_gets_no_second_copy(): void
    {
        $contact = $this->client->contacts()->create(['type' => 'individual', 'first_name' => 'Eleni', 'email' => ' Books@Example.TEST', 'country' => 'GR']);
        $contact->forceFill(['email_preferences' => ['invoice']])->save();
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $this->clean();

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $invoice->fresh());

        $this->assertSame([self::ACCOUNT], $this->mailedTo());
    }

    public function test_holds_the_owners_opt_out_stops_the_mail_to_the_accounts_address(): void
    {
        $template = NotificationTemplate::where('key', 'invoice_paid')->first();
        $template->forceFill(['mail_enabled' => NotificationEnabledStatus::ChoiceOn->value])->save();
        $this->owner->notificationsPreferences()->create(['notification_template_id' => $template->id, 'mail_enabled' => false, 'in_app_enabled' => true]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $this->clean();

        NotificationHelper::invoicePaidNotification($this->client->fresh(), $invoice->fresh());

        $this->assertSame([], $this->mailedTo('invoice_paid'));
    }

    public function test_holds_a_member_without_invoices_cannot_move_the_accounts_address_to_their_own(): void
    {
        $this->client->primaryContact->forceFill(['country' => 'GR'])->save();
        $this->member(['profile', 'contacts', 'tickets', 'products', 'domains', 'emails', 'affiliates']);

        Livewire::test(Account::class)
            ->set('contact.country', 'GR')
            ->set('contact.email', 'member@example.test')
            ->call('submit')
            ->assertHasErrors('contact.email');

        $this->assertSame(self::ACCOUNT, $this->client->fresh()->mailAddress());
    }

    public function test_holds_a_member_without_invoices_still_saves_the_rest_with_the_same_address_in_another_case(): void
    {
        $this->client->primaryContact->forceFill(['country' => 'GR'])->save();
        $this->member(['profile']);

        Livewire::test(Account::class)
            ->set('contact.country', 'GR')
            ->set('contact.email', 'BOOKS@example.test')
            ->set('contact.city', 'Patra')
            ->call('submit')
            ->assertHasNoErrors('contact.email');

        $this->assertSame('Patra', $this->client->fresh()->primaryContact->city);
    }

    public function test_holds_a_guest_tickets_mail_goes_to_the_guest_not_the_account(): void
    {
        $support = $this->department();
        $ticket = Ticket::query()->create(['client_id' => null, 'subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'department_id' => $support->id, 'guest_name' => 'Maria', 'guest_email' => 'maria@example.com', 'opened_via' => 'email']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'message' => 'Help', 'via' => TicketMessage::VIA_EMAIL, 'email_message_id' => 'orig@example.com']);
        $this->clean();

        $this->staffReply($ticket);

        $this->assertSame(['maria@example.com'], $this->mailedTo());
    }

    public function test_holds_the_log_resend_offers_the_address_it_went_to(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $this->clean();
        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $invoice->fresh());

        $log = EmailLog::query()->sole();
        $resent = MailAudit::resend($log, $log->to);

        $this->assertSame(self::ACCOUNT, $resent->to);
    }
}
