<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\ClientResource\RelationManagers\ContactsRelationManager;
use App\Classes\Settings;
use App\Exceptions\DisplayException;
use App\Livewire\Auth\Register;
use App\Livewire\Client\Account;
use App\Livewire\Client\Contacts;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Contacts (docs/accounts.md, PR 3a): the primary contact is the account's details (WHMCS tblclients); the other
 * contacts are kept, archived rather than deleted; what a contact needs filled in depends on its role.
 */
class ContactsTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<int, string>|null  $permissions */
    private function member(Client $client, ?array $permissions): User
    {
        $user = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $user->id, 'permissions' => $permissions, 'granted_at' => now()]);

        return $user;
    }

    private function workOn(User $user, Client $client): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $client->id]));
    }

    public function test_every_account_has_one_primary_contact_with_its_owners_name(): void
    {
        $owner = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'P', 'email' => 'maria@example.test']);
        $client = $owner->client();

        $this->assertSame(1, $client->contacts()->whereNotNull('primary_of_client_id')->count());
        $this->assertSame('maria@example.test', $client->primaryContact->email);
        $this->assertSame('Maria P', $client->name);

        $client->primaryContact->update(['type' => 'business', 'company_name' => 'Acme ΙΚΕ', 'email' => 'billing@example.test']);
        $client->unsetRelation('primaryContact');
        $this->assertSame('Acme ΙΚΕ', $client->name, 'a business is named by its company');
        $this->assertSame('billing@example.test', $client->email);
    }

    public function test_the_identity_properties_become_the_primary_contact_and_back(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Re-running a migration inside the test transaction needs transactional DDL (sqlite).');
        }
        $client = User::factory()->create(['first_name' => 'Nikos', 'last_name' => 'K'])->client();
        $client->primaryContact->update(['company_name' => 'Acme', 'address1' => 'Odos 1', 'city' => 'Athens', 'postcode' => '10431',
            'country' => 'GR', 'phone' => '+30 210 0000000']);
        $migration = require database_path('migrations/2026_10_30_000000_create_contacts_table.php');

        $migration->down();
        $this->assertSame('Greece', DB::table('properties')->where('model_id', $client->id)->where('key', 'country')->value('value'));
        $this->assertSame('Odos 1', DB::table('properties')->where('model_id', $client->id)->where('key', 'address')->value('value'));

        $migration->up();
        $contact = Contact::query()->where('primary_of_client_id', $client->id)->sole();
        $this->assertSame(['Nikos', 'K', 'Acme', 'business', 'Odos 1', 'Athens', '10431', 'GR', '+30 210 0000000'], [
            $contact->first_name, $contact->last_name, $contact->company_name, $contact->type, $contact->address1, $contact->city,
            $contact->postcode, $contact->country, $contact->phone,
        ]);
        $this->assertSame(0, DB::table('properties')->where('model_id', $client->id)->whereIn('key', ['country', 'address', 'zip'])->count());
    }

    public function test_tax_and_invoices_read_the_primary_contact(): void
    {
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['type' => 'business', 'company_name' => 'Acme ΙΚΕ', 'tax_id' => '123456789', 'tax_office' => 'Α\' Αθηνών',
            'address1' => 'Odos 1', 'city' => 'Athens', 'postcode' => '10431', 'country' => 'GR']);
        $client = $client->fresh();

        $this->assertEquals(24, Settings::tax($client)->rate);

        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $this->assertSame('Acme ΙΚΕ', $invoice->fresh()->user_name, 'a business is invoiced by its company (once)');
        $lines = $invoice->fresh()->user_properties;
        $this->assertContains('Odos 1', $lines);
        $this->assertContains('10431 Athens', $lines);
        $this->assertTrue(collect($lines)->contains(fn ($line) => str_contains((string) $line, '123456789')));
    }

    public function test_required_fields_depend_on_the_role(): void
    {
        $contact = new Contact(['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.test']);

        $this->assertTrue($contact->isCompleteFor('email'));
        $this->assertTrue($contact->isCompleteFor('invoice'), 'a receipt needs a name only');
        $this->assertContains('phone', $contact->missingFor('domain'));
        $this->assertNotContains('tax_id', $contact->missingFor('domain'), 'a domain contact needs no tax id');

        $contact->document_type = 'invoice';
        $this->assertEqualsCanonicalizing(['company_name', 'tax_id', 'tax_office', 'address1', 'city', 'postcode', 'country'], $contact->missingFor('invoice'));
    }

    public function test_one_primary_contact_and_the_primary_is_never_archived(): void
    {
        $client = User::factory()->create()->client();
        $old = $client->primaryContact;
        $other = $client->contacts()->create(['first_name' => 'Other', 'email' => 'other@example.test']);

        $other->makePrimary();

        $this->assertSame($other->id, $client->fresh()->primaryContact->id);
        $this->assertNull($old->fresh()->primary_of_client_id);
        $this->assertSame(1, $client->contacts()->whereNotNull('primary_of_client_id')->count());

        $this->expectException(DisplayException::class);
        $other->fresh()->archive();
    }

    public function test_the_account_page_saves_the_details_only_with_profile(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $member = $this->member($client, ['tickets']);

        $this->workOn($owner, $client);
        Livewire::test(Account::class)->set('contact.company_name', 'Owner Co')->set('contact.country', 'GR')->call('submit')->assertHasNoErrors();
        $this->assertSame(['Owner Co', 'GR'], [$client->primaryContact->fresh()->company_name, $client->primaryContact->fresh()->country]);

        $this->workOn($member, $client);
        Livewire::test(Account::class)->set('contact.company_name', 'Hijacked')->call('submit');
        $this->assertSame('Owner Co', $client->primaryContact->fresh()->company_name);
    }

    public function test_the_contacts_page_manages_only_the_accounts_other_contacts(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $foreign = User::factory()->create()->client()->contacts()->create(['first_name' => 'Foreign']);
        $ticketsOnly = $this->member($client, ['tickets']);

        $this->workOn($ticketsOnly, $client)->get(route('account.contacts'))->assertForbidden();

        $this->workOn($owner, $client);
        Livewire::test(Contacts::class)->call('create')
            ->set('contact.first_name', 'Billing')->set('contact.email', 'billing@example.test')->set('contact.document_type', 'invoice')
            ->call('save')->assertHasNoErrors();
        $contact = $client->contacts()->whereNull('primary_of_client_id')->sole();
        $this->assertSame('billing@example.test', $contact->email);

        foreach ([$client->primaryContact->id, $foreign->id] as $id) {
            Livewire::test(Contacts::class)->call('edit', $id)->assertNotFound(); // not one of the account's other contacts
        }

        Livewire::test(Contacts::class)->call('archive', $contact->id);
        $this->assertNotNull($contact->fresh()->archived_at);
        $this->assertNull($foreign->fresh()->archived_at);
    }

    public function test_registration_opens_the_account_with_its_details(): void
    {
        Livewire::test(Register::class)
            ->set('first_name', 'Eleni')->set('last_name', 'M')->set('email', 'eleni@example.test')
            ->set('password', 'secret-pass-1')->set('password_confirmation', 'secret-pass-1')
            ->set('contact.city', 'Thessaloniki')->set('contact.country', 'GR')
            ->call('submit')->assertHasNoErrors();

        $client = CurrentClient::defaultFor(User::query()->where('email', 'eleni@example.test')->sole());
        $this->assertSame(['Eleni', 'eleni@example.test', 'Thessaloniki', 'GR'], [
            $client->primaryContact->first_name, $client->primaryContact->email, $client->primaryContact->city, $client->primaryContact->country,
        ]);
    }

    public function test_staff_add_a_contact_and_make_it_primary(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $client = User::factory()->create()->client();
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.update'])->create();
        $page = fn () => $this->livewireAs($staff)->test(ContactsRelationManager::class, ['ownerRecord' => $client->fresh(), 'pageClass' => EditClient::class]);

        $page()->callTableAction('create', data: ['type' => 'business', 'company_name' => 'New Co', 'email' => 'new@example.test'])
            ->assertHasNoTableActionErrors();
        $contact = $client->contacts()->where('company_name', 'New Co')->sole();

        $page()->callTableAction('primary', $contact);
        $this->assertSame('New Co', $client->fresh()->name);
    }
}
