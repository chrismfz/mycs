<?php

namespace Tests\Feature;

use App\Enums\NotificationEnabledStatus;
use App\Helpers\NotificationHelper;
use App\Livewire\Client\Account;
use App\Livewire\Client\Contacts;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Support\CurrentClient;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Round-2 adversarial review of #550 (the account's mails to its other contacts), against the round-1 fixes.
 * test_bug_* fail on the PR's head (62de3db9); test_holds_* are guarantees attacked that held.
 */
class ContactMailsAdversarialR2Test extends TestCase
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
    private function contact(string $email, ?array $kinds): Contact
    {
        $contact = $this->client->contacts()->create(['type' => 'individual', 'first_name' => 'Eleni', 'email' => $email, 'country' => 'GR']);
        $contact->forceFill(['email_preferences' => $kinds])->save();

        return $contact;
    }

    /** @return list<string> */
    private function mailedTo(?string $template = null): array
    {
        return EmailLog::query()->when($template, fn ($q) => $q->where('template_key', $template))->orderBy('id')->pluck('to')->all();
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $this->clean();

        return $invoice->fresh();
    }

    private function member(array $permissions, string $email = 'member@example.test'): User
    {
        $member = User::factory()->withoutClient()->create(['email' => $email]);
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $member->id, 'permissions' => $permissions, 'granted_at' => now()]);
        app(CurrentClient::class)->forget();
        $this->actingAs($member)->withSession($this->loginUser($member, [CurrentClient::SESSION_KEY => $this->client->id]));

        return $member;
    }

    // ---- ADV-550-R2-1: a template whose mail staff turned off still mails every contact -----------------------------

    /**
     * Staff set a template's mail to «Never» (in-app only, NotificationTemplateResource): deliver() sends the owner no
     * mail, but toContacts checks only $template->enabled, so every contact taking the kind still gets the mail.
     */
    public function test_bug_a_template_whose_mail_is_never_still_mails_the_contacts(): void
    {
        $this->contact('books@example.test', ['invoice']);
        NotificationTemplate::where('key', 'new_invoice_created')->update(['mail_enabled' => NotificationEnabledStatus::Never->value]);

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $this->invoice());

        $this->assertNotContains('owner@example.test', $this->mailedTo(), 'the owner: no mail, as staff chose');
        $this->assertSame([], $this->mailedTo(), 'a template whose mail is «Never» mails nobody, a contact neither');
    }

    // ---- ADV-550-R2-2: the owner's own opt-out comes back through a contact with their address ----------------------

    /**
     * The owner turned a «choice» template's mail off in their own notification settings. deliver() returns false, so
     * toContacts no longer reserves the owner's address (ADV-550-3's fix), and a contact with the owner's address (WHMCS's
     * tblcontacts often holds the owner once more) mails it to them anyway.
     */
    public function test_bug_the_owners_own_opt_out_is_undone_by_a_contact_with_their_address(): void
    {
        $template = NotificationTemplate::where('key', 'invoice_paid')->first();
        $template->forceFill(['mail_enabled' => NotificationEnabledStatus::ChoiceOn->value])->save();
        $this->owner->notificationsPreferences()->create(['notification_template_id' => $template->id, 'mail_enabled' => false, 'in_app_enabled' => true]);
        $this->contact('Owner@Example.test', ['invoice']);

        NotificationHelper::invoicePaidNotification($this->client->fresh(), $this->invoice());

        $this->assertSame([], array_map('strtolower', $this->mailedTo()), 'the owner said «no mail» for this template; their own address gets none');
    }

    // ---- ADV-550-R2-3: a member without «invoices» unticks the owner's invoices on the account page -----------------

    /**
     * EmailKinds::PERMISSIONS is «the account permission a member needs to tick or untick a kind on a contact». The
     * contacts page applies it; the account page (Account::submit, permission «profile») saves the primary's
     * email_preferences as sent, so a member with «profile» alone takes the owner off the invoices.
     */
    public function test_bug_a_member_without_invoices_unticks_the_owners_invoices_on_the_account_page(): void
    {
        $this->client->primaryContact->forceFill(['country' => 'GR', 'email_preferences' => null])->save();
        $this->member(['profile']);

        Livewire::test(Account::class)->set('contact.country', 'GR')->set('contact.email_preferences', ['general', 'product', 'domain', 'support', 'affiliate'])
            ->call('submit')->assertHasNoErrors();

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $this->invoice());

        $this->assertContains('owner@example.test', $this->mailedTo(), 'a member without «invoices» cannot change who gets the invoices');
    }

    // ---- ADV-550-R2-4: a contact made primary loses its own copies --------------------------------------------------

    /**
     * The accountant's contact takes the invoices; staff make it the primary (the account's billing details). makePrimary
     * gives it the old primary's ticks, which are the OWNER's mails; toContacts skips the primary and the owner is mailed
     * at the login's address. The accountant's address silently stops getting the invoices.
     */
    public function test_bug_a_contact_made_primary_stops_getting_the_mails_it_took(): void
    {
        $books = $this->contact('books@example.test', ['invoice']);
        $books->makePrimary();

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $this->invoice());

        $this->assertContains('books@example.test', $this->mailedTo(), 'the accountant still gets the invoices they took');
    }

    // ---- What held ---------------------------------------------------------------------------------------------------

    /** The email refusal: a different address in another case or with spaces, or cleared, is still a change. */
    public function test_holds_case_and_whitespace_never_slip_past_the_email_refusal(): void
    {
        $books = $this->contact('books@example.test', ['invoice']);
        $this->member(['contacts']);

        foreach (['MEMBER@example.test', ' member@example.test', ''] as $address) {
            Livewire::test(Contacts::class)->call('edit', $books->id)->set('contact.email', $address)->call('save')->assertHasErrors(['contact.email']);
        }
        // The same address in another case is no change (refused or not, it stays the accountant's mailbox).
        Livewire::test(Contacts::class)->call('edit', $books->id)->set('contact.email', 'BOOKS@example.test')->call('save')->assertHasNoErrors();

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $this->invoice());
        $this->assertNotContains('member@example.test', array_map(fn ($a) => strtolower(trim($a)), $this->mailedTo()));
    }

    /** Untick then change, or archive and make a new one with the member's own address: no invoice reaches them. */
    public function test_holds_two_steps_and_archive_recreate_never_redirect_the_invoices(): void
    {
        $books = $this->contact('books@example.test', ['invoice']);
        $this->member(['contacts', 'domains']);

        // Step 1: untick everything (invoice stays: the member may not untick it), step 2: the email is still refused.
        Livewire::test(Contacts::class)->call('edit', $books->id)->set('contact.email_preferences', [])->call('save')->assertHasNoErrors();
        $this->assertSame(['invoice'], $books->fresh()->email_preferences);
        Livewire::test(Contacts::class)->call('edit', $books->id)->set('contact.email', 'member@example.test')->call('save')->assertHasErrors(['contact.email']);

        // Archive it, and a new contact on the member's address ticking invoice (tampered) and domain (allowed).
        Livewire::test(Contacts::class)->call('archive', $books->id);
        Livewire::test(Contacts::class)->call('create')->set('contact.email', 'member@example.test')->set('contact.first_name', 'M')
            ->set('contact.email_preferences', ['invoice', 'domain'])->call('save')->assertHasNoErrors();
        $this->assertSame(['domain'], Contact::where('email', 'member@example.test')->first()->email_preferences);

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $this->invoice());
        $this->assertNotContains('member@example.test', $this->mailedTo());
    }

    /**
     * hasNestedSecret has no false positive on the data the account's mails pass today: an overdue notice with its
     * extra, a credit applied, a refund, a domain's renewal notice all reach a contact that takes the kind.
     */
    public function test_holds_no_secret_false_positive_on_todays_callers(): void
    {
        $this->contact('books@example.test', ['invoice', 'domain']);
        $invoice = $this->invoice();
        $client = $this->client->fresh();

        NotificationHelper::invoiceNotification($client, $invoice, 'invoice_overdue', ['overdue' => ['days' => 3, 'last' => false, 'suspend_on' => null]]);
        $transaction = InvoiceTransaction::query()->create(['invoice_id' => $invoice->id, 'amount' => 5, 'fee' => 0, 'transaction_id' => 'credit-1']);
        NotificationHelper::creditAppliedNotification($client, $invoice, $transaction);
        NotificationHelper::invoiceRefundedNotification($client, $invoice, 500, true, false);
        $domain = Domain::factory()->create(['client_id' => $this->client->id]);
        NotificationHelper::domainNotification($client, $domain, 'domain_renewal_notice', ['fqdn' => $domain->fqdn, 'days_left' => 30, 'invoice' => null, 'preferred_method' => '']);

        foreach (['invoice_overdue', 'credit_applied', 'invoice_refunded', 'domain_renewal_notice'] as $key) {
            $this->assertContains('books@example.test', $this->mailedTo($key), "{$key} reaches the contact taking its kind");
        }
    }

    /** A caller's own `user` never becomes the contact's greeting or recipient: the contact is. */
    public function test_holds_a_callers_user_key_never_redirects_a_contacts_copy(): void
    {
        $this->contact('books@example.test', ['domain']);
        $stranger = User::factory()->withoutClient()->create(['email' => 'stranger@example.test', 'first_name' => 'Stranger']);
        $domain = Domain::factory()->create(['client_id' => $this->client->id]);
        $this->clean();

        NotificationHelper::domainNotification($this->client->fresh(), $domain, 'domain_renewed', ['fqdn' => $domain->fqdn, 'expires' => '01/01/2030', 'user' => $stranger]);

        $log = EmailLog::where('to', 'books@example.test')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('Stranger', (string) $log->body);
        $this->assertNotContains('stranger@example.test', $this->mailedTo());
    }
}
