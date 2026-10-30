<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\ClientResource\RelationManagers\ContactsRelationManager;
use App\Helpers\NotificationHelper;
use App\Livewire\Client\Account;
use App\Livewire\Client\Contacts;
use App\Livewire\Client\Users;
use App\Models\BulkMail;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Mail\BulkMailer;
use App\Support\CurrentClient;
use App\Support\EmailKinds;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The account's mails to its other contacts (owner 2026-10-30, WHMCS's tblcontacts flags): each its own copy of the
 * kinds it takes; one that never chose takes none (a domain's registrant, an invoice's recipient); never the owner's
 * alone (a password, a ticket's thread), never a mass mail, never a template's CC twice.
 */
class ContactMailsTest extends TestCase
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
        $contact = $this->client->contacts()->create($extra + ['type' => 'individual', 'first_name' => 'Eleni', 'email' => $email]);
        $contact->forceFill(['email_preferences' => $kinds])->save();

        return $contact;
    }

    /** @return list<string> the addresses mailed */
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

    public function test_each_contact_gets_its_own_copy_of_the_kinds_it_takes(): void
    {
        $this->contact('billing@example.test', ['invoice']);
        $this->contact('dns@example.test', ['domain']);
        $this->contact('registrant@example.test', null); // never chose: a domain's or an invoice's contact
        $this->contact('old@example.test', ['invoice'])->forceFill(['archived_at' => now()])->save();
        $this->contact('OWNER@example.test', ['invoice']); // the owner's own address: once
        $this->contact('Billing@example.test', ['invoice']); // the same address twice: once
        $this->contact('', ['invoice']);
        $invoice = $this->invoice();

        NotificationHelper::invoiceCreatedNotification($this->client, $invoice);

        $this->assertSame(['owner@example.test', 'billing@example.test'], $this->mailedTo());
        $log = EmailLog::query()->where('to', 'billing@example.test')->sole();
        $this->assertSame([$this->client->id, 'new_invoice_created'], [$log->client_id, $log->template_key], 'logged on the account');
        $this->assertSame(0, Notification::query()->where('user_id', '!=', $this->owner->id)->count(), 'no in-app: contacts have no login');
    }

    public function test_a_contact_gets_its_kind_even_when_the_owner_does_not_take_it(): void
    {
        $this->client->primaryContact->forceFill(['email_preferences' => ['general']])->save();
        $this->client = $this->client->fresh();
        $this->contact('billing@example.test', ['invoice']);
        $invoice = $this->invoice();

        NotificationHelper::invoiceCreatedNotification($this->client, $invoice);

        $this->assertSame(['billing@example.test'], $this->mailedTo(), 'the accountant keeps getting the invoices');
    }

    public function test_never_the_owners_alone_nor_a_mass_mail(): void
    {
        $this->contact('tech@example.test', Contact::EMAIL_PREFERENCES);
        $service = $this->service();

        NotificationHelper::serverCreatedNotification($this->client, $service);
        NotificationHelper::serverSuspendedNotification($this->client, $service);
        $mail = BulkMail::query()->create(['name' => 'Notice', 'kind' => BulkMail::KIND_IMPORTANT, 'subject' => 'Notice', 'body' => 'Hello', 'filters' => ['target' => 'all']]);
        app(BulkMailer::class)->start($mail, null);
        while (app(BulkMailer::class)->sendBatch($mail->refresh(), 100) > 0);

        $this->assertSame([['owner@example.test', 'new_server_created'], ['owner@example.test', 'server_suspended'], ['tech@example.test', 'server_suspended'],
            ['owner@example.test', 'announcement']], EmailLog::query()->orderBy('id')->get()->map(fn ($log) => [$log->to, $log->template_key])->all());
        $this->assertContains('new_ticket_message', EmailKinds::OWNER_ONLY, 'a ticket\'s thread is its opener\'s');
    }

    public function test_the_templates_cc_gets_the_owners_mail_only(): void
    {
        NotificationTemplate::query()->where('key', 'new_invoice_created')->update(['cc' => ['books@example.test']]);
        $this->contact('billing@example.test', ['invoice']);
        $invoice = $this->invoice();

        NotificationHelper::invoiceCreatedNotification($this->client, $invoice);

        $this->assertSame(['books@example.test'], EmailLog::query()->where('to', 'owner@example.test')->sole()->cc);
        $this->assertNull(EmailLog::query()->where('to', 'billing@example.test')->sole()->cc);
        Mail::assertQueued(\App\Mail\Mail::class, fn ($mail) => $mail->hasTo('billing@example.test') && $mail->cc === []);
    }

    // ---- the forms: another contact's null is none, never every kind ------------------------------------------

    public function test_another_contacts_none_chosen_is_shown_unticked_and_saving_keeps_it_none(): void
    {
        $contact = $this->contact('registrant@example.test', null, ['country' => 'GR']);
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->client->id]));

        Livewire::test(Contacts::class)->call('edit', $contact->id)->assertSet('contact.email_preferences', [])->call('save')->assertHasNoErrors();

        $this->assertFalse($contact->fresh()->takesEmail('invoice'));
        $this->assertTrue($this->client->primaryContact->takesEmail('invoice'), 'the primary\'s null stays every kind');
    }

    public function test_the_admin_shows_another_contacts_none_chosen_unticked_and_saving_keeps_it_none(): void
    {
        $contact = $this->contact('registrant@example.test', null, ['country' => 'GR']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->livewireAs(Staff::factory()->admin()->create())
            ->test(ContactsRelationManager::class, ['ownerRecord' => $this->client->fresh(), 'pageClass' => EditClient::class])
            ->mountTableAction('edit', $contact)->assertTableActionDataSet(['email_preferences' => []])
            ->callMountedTableAction()->assertHasNoTableActionErrors();

        $this->assertFalse($contact->fresh()->takesEmail('invoice'));
        $this->assertSame(Contact::EMAIL_PREFERENCES, $this->client->primaryContact->emailPreferencesShown());
    }

    /** A member with «contacts» but not «invoices» never reads the invoices by a contact of their own address. */
    public function test_a_member_ticks_only_the_kinds_they_may_see(): void
    {
        $member = User::factory()->withoutClient()->create(['email' => 'member@example.test']);
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $member->id, 'permissions' => ['contacts', 'domains'], 'granted_at' => now()]);
        $kept = $this->contact('books@example.test', ['invoice']);
        app(CurrentClient::class)->forget();
        $this->actingAs($member)->withSession($this->loginUser($member, [CurrentClient::SESSION_KEY => $this->client->id]));

        Livewire::test(Contacts::class)->call('create')
            ->set('contact.type', 'individual')->set('contact.first_name', 'Me')->set('contact.email', 'member@example.test')->set('contact.country', 'GR')
            ->set('contact.email_preferences', ['invoice', 'domain'])->call('save')->assertHasNoErrors()
            ->call('edit', $kept->id)->set('contact.email_preferences', ['domain'])->call('save')->assertHasNoErrors();

        $this->assertSame(['domain'], Contact::query()->where('email', 'member@example.test')->sole()->email_preferences, 'invoice is not theirs to tick');
        $this->assertSame(['invoice', 'domain'], $kept->fresh()->email_preferences, 'nor to untick');
    }

    /** Code review 2 and 5 of #550: a module's secret at any depth stays the owner's; a company is greeted by its name. */
    public function test_a_nested_secret_stays_the_owners_and_a_company_is_greeted_by_name(): void
    {
        $this->contact('ops@example.test', ['product'], ['first_name' => null, 'type' => 'business', 'company_name' => 'Gamma Ltd']);
        $service = $this->service();

        NotificationHelper::serverUnsuspendedNotification($this->client, $service, ['account' => ['password' => 'S3cret!']]);
        NotificationHelper::serverSuspendedNotification($this->client, $service);

        $this->assertSame([['owner@example.test', 'server_unsuspended'], ['owner@example.test', 'server_suspended'], ['ops@example.test', 'server_suspended']],
            EmailLog::query()->orderBy('id')->get()->map(fn ($log) => [$log->to, $log->template_key])->all());
        $this->assertStringContainsString('Gamma Ltd', (string) EmailLog::query()->where('to', 'ops@example.test')->sole()->body);
    }

    /** Owner 2026-10-30: removing a member whose address is still a mailed contact tells whoever removes it. */
    public function test_removing_a_member_warns_of_a_contact_still_mailed_at_their_address(): void
    {
        $member = User::factory()->withoutClient()->create(['email' => 'member@example.test']);
        $grant = ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $member->id, 'permissions' => ['invoices'], 'granted_at' => now()]);
        $this->contact('Member@example.test', ['invoice'], ['first_name' => 'Nikos']);

        $this->livewireAs($this->owner)->test(Users::class)->call('revoke', $grant->id)
            ->assertDispatched('notify', fn ($name, $params) => ($params[0]['type'] ?? null) === 'warning' && str_contains($params[0]['message'], 'Nikos'));

        $this->assertSame([], $this->client->contactsMailedAt('other@example.test')->all());
    }

    /** ADV-550-R2-4: the primary's own address is mailed as the account's; a member without every kind never moves it. */
    public function test_a_member_without_every_kind_never_moves_the_primarys_address(): void
    {
        $member = User::factory()->withoutClient()->create(['email' => 'member@example.test']);
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $member->id, 'permissions' => ['profile'], 'granted_at' => now()]);
        $this->client->primaryContact->update(['email' => 'books@example.test', 'country' => 'GR']);
        app(CurrentClient::class)->forget();
        $this->actingAs($member)->withSession($this->loginUser($member, [CurrentClient::SESSION_KEY => $this->client->id]));

        Livewire::test(Account::class)->set('contact.email', 'member@example.test')->call('submit')
            ->assertHasErrors(['contact.email']);

        $this->assertSame('books@example.test', $this->client->primaryContact->fresh()->email);
    }

    /** Owner 2026-10-30, as WHMCS (tblemails: To the account's email): the account's address, the login only without one. */
    public function test_the_accounts_mails_go_to_its_primary_contacts_address(): void
    {
        $this->client->primaryContact->update(['email' => 'accounts@example.test']);
        $invoice = $this->invoice();

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $invoice);
        $this->assertSame(['accounts@example.test'], $this->mailedTo());
        $this->assertSame(1, Notification::query()->where('user_id', $this->owner->id)->count(), 'the in-app is the owner\'s');

        $this->clean();
        $this->client->primaryContact->update(['email' => null]);
        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $invoice);
        $this->assertSame(['owner@example.test'], $this->mailedTo(), 'none on the account: the owner\'s login');
    }
}
