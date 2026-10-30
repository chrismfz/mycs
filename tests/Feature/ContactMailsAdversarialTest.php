<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Livewire\Client\Contacts;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentClient;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of #550 (the account's mails to its other contacts): who gets which mail, from every way a
 * contact is saved. test_bug_* fail on the PR's head; test_holds_* are guarantees attacked that held.
 */
class ContactMailsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['settings.mail_disable' => false]);
        (new EmailTemplateSeeder)->run();
        $this->owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->client = $this->owner->client();
        $this->clean();
    }

    private function clean(): void
    {
        EmailLog::query()->delete();
        Notification::query()->delete();
        Mail::fake();
    }

    /** @param  array<int, string>|null  $kinds */
    private function contact(string $email, ?array $kinds, array $extra = []): Contact
    {
        $contact = $this->client->contacts()->create(['type' => 'individual', 'first_name' => 'Eleni', 'email' => $email, 'country' => 'GR'] + $extra);
        $contact->forceFill(['email_preferences' => $kinds])->save();

        return $contact;
    }

    /** @return list<string> */
    private function mailedTo(): array
    {
        return EmailLog::query()->orderBy('id')->pluck('to')->all();
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $this->clean();

        return $invoice->fresh();
    }

    private function service(): Service
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
        $this->clean();

        return $service;
    }

    private function member(array $permissions, string $email = 'member@example.test'): User
    {
        $member = User::factory()->withoutClient()->create(['email' => $email]);
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $member->id, 'permissions' => $permissions, 'granted_at' => now()]);
        app(CurrentClient::class)->forget();
        $this->actingAs($member)->withSession($this->loginUser($member, [CurrentClient::SESSION_KEY => $this->client->id]));

        return $member;
    }

    // ---- ADV-550-1: a member's «contacts» alone redirects the invoices to their own address -------------------------

    /**
     * The owner set up the accountant to take the invoices. A member with «contacts» but not «invoices» may not tick
     * «invoice», and withAllowedKinds keeps it as it was; but nothing stops them changing the contact's EMAIL to their
     * own. The kind stays ticked, now on the member's address: they read every invoice the site hides from them.
     */
    public function test_bug_a_member_without_invoices_redirects_an_invoice_contact_to_their_own_address(): void
    {
        $books = $this->contact('books@example.test', ['invoice']);
        $this->member(['contacts']);

        Livewire::test(Contacts::class)->call('edit', $books->id)
            ->set('contact.email', 'member@example.test')->call('save')->assertHasErrors(['contact.email']); // the fix: refused

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $this->invoice());

        $this->assertNotContains('member@example.test', $this->mailedTo(), 'a member without «invoices» reads no invoice by mail');
    }

    // ---- ADV-550-2: the old primary, once another is made primary, takes every kind as a contact -------------------

    /**
     * The primary's ticks are the OWNER's mails (EmailKinds::takes); another contact's are its own copies. makePrimary
     * hands the old primary's list to the new one and leaves the old one with it too, now read with the other meaning:
     * the account's details address (never mailed before, ContactsAdversarialTest «never goes to a contact typed
     * address») starts getting a copy of every kind. Every WHMCS-imported primary has an explicit list
     * (ImportFromWhmcs::emailPreferences), and so has any primary saved once from the account page.
     */
    public function test_bug_a_demoted_primary_starts_getting_copies_of_the_owners_kinds(): void
    {
        $this->client->primaryContact->forceFill(['email' => 'info@example.test', 'email_preferences' => Contact::EMAIL_PREFERENCES])->save();
        $this->contact('new-details@example.test', null)->makePrimary();
        $this->client = $this->client->fresh();

        NotificationHelper::invoiceCreatedNotification($this->client, $this->invoice());

        $this->assertSame(['owner@example.test'], $this->mailedTo(), 'making another contact primary mails no one new');
    }

    // ---- ADV-550-3: an address that opted in is dropped because the owner shares it, though the owner got nothing ---

    /**
     * WHMCS lets the client untick invoice mails when a contact takes them, and on myip 82 contacts share their account's
     * email. toContacts seeds «sent» with the owner's address before knowing whether the owner's mail went out ($noMail):
     * the contact that ticked «invoice» is skipped as a duplicate of a mail never sent. Nobody gets the invoice.
     */
    public function test_bug_a_contact_sharing_the_owners_address_is_skipped_when_the_owner_got_nothing(): void
    {
        $this->client->primaryContact->forceFill(['email_preferences' => ['general']])->save();
        $this->client = $this->client->fresh();
        $this->contact('Owner@Example.test', ['invoice']);

        NotificationHelper::invoiceCreatedNotification($this->client, $this->invoice());

        $this->assertCount(1, EmailLog::query()->whereRaw('LOWER("to") = ?', ['owner@example.test'])->get(), 'the address that takes invoices gets the invoice once');
    }

    // ---- ADV-550-4: any third-party address, never confirmed, with a greeting the customer wrote ---------------------

    /**
     * A fresh account (its owner's email not even verified) adds contacts with anyone's address and a «first name» of its
     * choosing, ticks «general», places orders: our server mails each victim «Dear <their text>» from the company's
     * domain, as many as it adds. Nothing confirms the address, nothing caps the number.
     */
    public function test_bug_an_unconfirmed_third_party_address_gets_mail_with_the_customers_text(): void
    {
        $this->markTestIncomplete('ADV-550-4: a design call for the owner (WHMCS confirms no contact address either).');
        $this->owner->forceFill(['email_verified_at' => null])->save();
        foreach (range(1, 5) as $i) {
            $this->contact("victim{$i}@example.test", ['general', 'invoice'], ['first_name' => 'friend, claim your prize at https://spam.example']);
        }

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $this->invoice());

        $this->assertSame(['owner@example.test'], $this->mailedTo(), 'no account mail to an address that never confirmed it wants it');
    }

    // ---- what held ------------------------------------------------------------------------------------------------

    /** A member's own new contact: the kinds they may not see stay unticked, also by tampering the list. */
    public function test_holds_a_member_cannot_tick_by_tampering_the_list(): void
    {
        $this->member(['contacts', 'domains']);

        Livewire::test(Contacts::class)->call('create')
            ->set('contact.type', 'individual')->set('contact.first_name', 'Me')->set('contact.email', 'member@example.test')->set('contact.country', 'GR')
            ->set('contact.email_preferences', ['x' => 'invoice', 'y' => 'product', 'z' => 'domain'])->call('save')->assertHasNoErrors();

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $this->invoice());

        $this->assertSame(['domain'], Contact::query()->where('email', 'member@example.test')->sole()->email_preferences);
        $this->assertNotContains('member@example.test', $this->mailedTo());
    }

    /** A member cannot reach the primary contact (or another account's) through the contacts page. */
    public function test_holds_a_member_cannot_edit_the_primary_from_the_contacts_page(): void
    {
        $this->member(['contacts']);

        Livewire::test(Contacts::class)->call('edit', $this->client->primaryContact->id)->assertStatus(404);
    }

    /** Ticket messages, the welcome mail and anything carrying a secret: the owner's alone. */
    public function test_holds_secrets_and_owner_only_mails_never_reach_a_contact(): void
    {
        $this->contact('tech@example.test', Contact::EMAIL_PREFERENCES);
        $service = $this->service();

        NotificationHelper::serverCreatedNotification($this->client, $service, ['service_password' => 'hunter2'], byStaff: true);
        NotificationHelper::serverUnsuspendedNotification($this->client, $service, ['new_password' => 'swordfish']);
        NotificationHelper::serverSuspendedNotification($this->client, $service, ['reason' => 'abuse']);

        $this->assertSame(['owner@example.test', 'owner@example.test', 'owner@example.test', 'tech@example.test'], $this->mailedTo());
        Mail::assertNotQueued(\App\Mail\Mail::class, fn ($mail) => $mail->hasTo('tech@example.test') && $mail->templateKey !== 'server_suspended');
    }

    /** A template that does not parse: no throw to the caller (the cron, a payment), the owner's path as before. */
    public function test_holds_a_broken_template_never_breaks_the_caller(): void
    {
        NotificationTemplate::query()->where('key', 'new_invoice_created')->update(['body' => 'Hi {% endfor %} {{ user.first_name }}']);
        $this->contact('books@example.test', ['invoice']);

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $this->invoice());

        $this->assertSame([], $this->mailedTo());
    }

    /** Staff's «Send Email» passes the owner's gate, never a contact's: a contact gets only the kinds it took. */
    public function test_holds_staff_send_never_widens_a_contacts_kinds(): void
    {
        $this->contact('dns@example.test', ['domain']);

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $this->invoice(), byStaff: true);

        $this->assertSame(['owner@example.test'], $this->mailedTo());
    }

    /** In the account's language, not the request's. */
    public function test_holds_the_contacts_copy_is_in_the_accounts_language(): void
    {
        config(['settings.allowed_languages' => ['en', 'el']]);
        $this->client->forceFill(['language' => 'el'])->save();
        $this->contact('books@example.test', ['invoice']);
        app()->setLocale('en');

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $this->invoice());

        $owner = EmailLog::query()->where('to', 'owner@example.test')->sole();
        $copy = EmailLog::query()->where('to', 'books@example.test')->sole();
        $this->assertSame($owner->subject, $copy->subject);
        $this->assertMatchesRegularExpression('/\p{Greek}/u', (string) $copy->subject);
    }

    /** WHMCS's tblcontacts with no flag ticked, and a registrar's contact (null): none. */
    public function test_holds_imported_and_registrar_contacts_take_nothing_unless_ticked(): void
    {
        DB::table('contacts')->insert(['client_id' => $this->client->id, 'type' => 'individual', 'email' => 'whmcs@example.test',
            'email_preferences' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]);
        $this->contact('registrant@example.test', null);

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $this->invoice());

        $this->assertSame(['owner@example.test'], $this->mailedTo());
    }
}
