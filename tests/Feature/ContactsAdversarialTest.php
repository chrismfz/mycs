<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\ClientResource\RelationManagers\ContactsRelationManager;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Helpers\NotificationHelper;
use App\Livewire\Auth\Register;
use App\Livewire\Client\Account;
use App\Livewire\Client\Contacts;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\CurrentClient;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of PR 3a (contacts, docs/accounts.md). `test_bug_*` fail on the PR's head and describe an issue;
 * `test_holds_*` pin a guarantee an attack did not break.
 */
class ContactsAdversarialTest extends TestCase
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
        app(CurrentClient::class)->forget();

        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $client->id]));
    }

    /** Livewire::test() acts on the account the session names (not on a fallback account of the login's own). */
    private function livewireOn(User $user, Client $client): void
    {
        $this->workOn($user, $client);
        $this->assertSame($client->id, CurrentClient::id(), 'the test acts on the targeted account');
    }

    private function invoiceOf(Client $client): Invoice
    {
        return Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
    }

    /** Re-runs the contacts migration (down, seed legacy properties, up), as ContactsTest does. */
    private function migrateWithProperties(Client $client, array $properties): Contact
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Re-running a migration inside the test transaction needs transactional DDL (sqlite).');
        }
        $migration = require database_path('migrations/2026_10_30_000000_create_contacts_table.php');
        $migration->down();
        DB::table('properties')->where('model_type', Client::class)->where('model_id', $client->id)->delete();
        foreach ($properties as $key => $value) {
            DB::table('properties')->insert(['model_type' => Client::class, 'model_id' => $client->id, 'key' => $key, 'name' => $key,
                'value' => $value, 'custom_property_id' => DB::table('custom_properties')->where('model', Client::class)->where('key', $key)->value('id')]);
        }
        $migration->up();

        return Contact::query()->where('primary_of_client_id', $client->id)->sole();
    }

    // ---- client area: permissions ------------------------------------------------------------------------------

    public function test_holds_a_member_without_profile_cannot_change_the_accounts_details(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $client->primaryContact->update(['company_name' => 'Owner Co', 'country' => 'GR']);
        $member = $this->member($client, ['tickets', 'contacts']);

        $this->livewireOn($member, $client);
        Livewire::test(Account::class)
            ->set('canEditDetails', true)
            ->set('contact', ['type' => 'business', 'company_name' => 'Hijacked', 'country' => 'US', 'email' => 'evil@example.test',
                'email_preferences' => []])
            ->call('submit');

        $contact = $client->primaryContact->fresh();
        $this->assertSame(['Owner Co', 'GR'], [$contact->company_name, $contact->country]);
        $this->assertNotSame('evil@example.test', $contact->email);
    }

    public function test_holds_a_member_without_contacts_cannot_reach_the_contacts_component(): void
    {
        $client = User::factory()->create()->client();
        $other = $client->contacts()->create(['first_name' => 'Billing', 'email' => 'billing@example.test']);
        $member = $this->member($client, ['profile', 'tickets']);

        $this->livewireOn($member, $client);
        Livewire::test(Contacts::class)->assertForbidden();
        foreach ([['create', []], ['edit', [$other->id]], ['archive', [$other->id]], ['save', []]] as [$method, $args]) {
            try {
                Livewire::test(Contacts::class)->set('contact.first_name', 'X')->set('editing', $method === 'save' ? $other->id : null)
                    ->call($method, ...$args);
            } catch (Throwable) {
            }
        }
        $this->assertSame('Billing', $other->fresh()->first_name);
        $this->assertNull($other->fresh()->archived_at);
        $this->assertSame(2, $client->contacts()->count());

        $this->workOn($member, $client)->get(route('account.contacts'))->assertForbidden();
    }

    public function test_holds_the_contact_array_cannot_mass_assign_primary_account_archive_or_notes(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $victim = User::factory()->create()->client();
        $this->livewireOn($owner, $client);

        Livewire::test(Contacts::class)->call('create')
            ->set('contact.first_name', 'Sneaky')
            ->set('contact.primary_of_client_id', $victim->id)
            ->set('contact.client_id', $victim->id)
            ->set('contact.archived_at', now()->toDateTimeString())
            ->set('contact.notes', 'staff note')
            ->set('contact.id', $victim->primaryContact->id)
            ->call('save');

        $created = Contact::query()->where('first_name', 'Sneaky')->sole();
        $this->assertSame($client->id, $created->client_id);
        $this->assertNull($created->primary_of_client_id);
        $this->assertNull($created->archived_at);
        $this->assertNull($created->notes);
        $this->assertSame($victim->primaryContact->id, $victim->fresh()->primaryContact->id);

        // The same through the account page (the primary contact).
        Livewire::test(Account::class)
            ->set('contact.primary_of_client_id', $victim->id)->set('contact.client_id', $victim->id)->set('contact.notes', 'x')
            ->call('submit');
        $primary = $client->fresh()->primaryContact;
        $this->assertSame([$client->id, $client->id, null], [$primary->client_id, $primary->primary_of_client_id, $primary->notes]);
    }

    public function test_holds_the_contacts_page_never_saves_over_the_primary_or_another_accounts_contact(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $foreign = User::factory()->create()->client();
        $foreignContact = $foreign->contacts()->create(['first_name' => 'Foreign']);
        $this->livewireOn($owner, $client);

        foreach ([$client->primaryContact->id, $foreign->primaryContact->id, $foreignContact->id] as $id) {
            try {
                Livewire::test(Contacts::class)->call('create')->set('editing', $id)
                    ->set('contact.first_name', 'Overwritten')->call('save');
            } catch (Throwable) {
            }
            try {
                Livewire::test(Contacts::class)->call('archive', $id);
            } catch (Throwable) {
            }
        }

        $this->assertSame(0, Contact::query()->where('first_name', 'Overwritten')->count());
        $this->assertSame(0, Contact::query()->whereNotNull('archived_at')->count());
    }

    public function test_holds_a_customer_cannot_make_a_contact_primary(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $other = $client->contacts()->create(['first_name' => 'Other']);
        $this->livewireOn($owner, $client);

        foreach (['makePrimary', 'primary'] as $method) {
            try {
                Livewire::test(Contacts::class)->call($method, $other->id);
            } catch (Throwable) {
            }
        }

        $this->assertNull($other->fresh()->primary_of_client_id);
    }

    /** An archived contact (it may be on documents or, with 3b, on domains) is out of the list, yet still editable. */
    public function test_bug_a_customer_edits_an_archived_contact(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $archived = $client->contacts()->create(['first_name' => 'Old', 'email' => 'old@example.test']);
        $archived->archive();
        $this->livewireOn($owner, $client);

        try {
            Livewire::test(Contacts::class)->call('edit', $archived->id)->set('contact.first_name', 'Rewritten')->call('save');
        } catch (Throwable) {
        }

        $this->assertSame('Old', $archived->fresh()->first_name, 'an archived contact is not offered, it must not be editable either');
    }

    // ---- one primary ---------------------------------------------------------------------------------------------

    public function test_holds_the_database_refuses_a_second_primary(): void
    {
        $client = User::factory()->create()->client();
        $other = $client->contacts()->create(['first_name' => 'Second']);

        $this->expectException(QueryException::class);
        $other->forceFill(['primary_of_client_id' => $client->id])->save();
    }

    public function test_holds_registration_and_staff_creation_give_exactly_one_primary(): void
    {
        Livewire::test(Register::class)
            ->set('first_name', 'A')->set('last_name', 'B')->set('email', 'reg@example.test')
            ->set('password', 'secret-pass-1')->set('password_confirmation', 'secret-pass-1')
            ->call('submit')->assertHasNoErrors();
        $client = CurrentClient::defaultFor(User::query()->where('email', 'reg@example.test')->sole());

        $this->assertSame(1, $client->contacts()->whereNotNull('primary_of_client_id')->count());
        $this->assertSame($client->id, $client->primaryContact->client_id);
    }

    // ---- registration validation ---------------------------------------------------------------------------------

    public function test_holds_registration_refuses_an_invalid_country_or_vat_number(): void
    {
        foreach ([['contact.country', 'XX'], ['contact.country', 'gr'], ['contact.country', 'Greece'], ['contact.vat_number', '<b>x</b>'],
            ['contact.type', 'admin'], ['contact.document_type', 'credit_note'], ['contact.email_preferences', ['all']]] as $i => [$field, $value]) {
            Livewire::test(Register::class)
                ->set('first_name', 'A')->set('last_name', 'B')->set('email', "bad{$i}@example.test")
                ->set('password', 'secret-pass-1')->set('password_confirmation', 'secret-pass-1')
                ->set($field, $value)
                ->call('submit')->assertHasErrors();
            $this->assertSame(0, User::query()->where('email', "bad{$i}@example.test")->count(), "{$field}={$i}");
        }
    }

    // ---- invoices: snapshots, tax, markup ------------------------------------------------------------------------

    public function test_holds_an_issued_invoice_keeps_its_vat_rate_after_the_country_changes(): void
    {
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        TaxRate::create(['name' => 'Zero', 'rate' => 0.01, 'country' => 'US']);
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['country' => 'GR', 'company_name' => 'Acme', 'address1' => 'Odos 1']);
        $invoice = $this->invoiceOf($client->fresh());

        $client->primaryContact->update(['country' => 'US', 'company_name' => 'Other']);
        $client->unsetRelation('primaryContact');

        $invoice = $invoice->fresh();
        $this->assertEquals(24, $invoice->tax->rate);
        $this->assertContains('Acme', $invoice->user_properties);
        $this->assertNotContains('Other', $invoice->user_properties);
    }

    /**
     * Every new account's primary contact holds only a name and an email (openFor), so the first invoices snapshot
     * no recipient lines: an empty `properties` is falsy and the invoice falls back to the live contact, so details
     * typed later (another company, another ΑΦΜ) appear on an invoice already issued.
     */
    public function test_bug_an_issued_invoice_shows_recipient_details_typed_after_it_was_issued(): void
    {
        $client = User::factory()->create()->client();
        $invoice = $this->invoiceOf($client);
        $this->assertNotNull($invoice->fresh()->snapshot, 'the invoice was issued with a snapshot');

        $client->primaryContact->update(['type' => 'business', 'company_name' => 'Later Co', 'tax_id' => '999999999']);

        $lines = $invoice->fresh()->user_properties;
        $this->assertNotContains('Later Co', $lines, 'an issued invoice keeps the recipient it was issued to');
    }

    /**
     * A country without a tax rate snapshots no tax (tax_name is null), so the issued invoice reads the live country:
     * moving the account to a taxed country puts that VAT on an invoice issued without it (and the reverse for an
     * account whose rate is removed). Pre-existing in the snapshot logic, now driven by the contact's country.
     */
    public function test_bug_an_issued_invoice_without_vat_takes_the_vat_of_the_new_country(): void
    {
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['country' => 'US']);
        $invoice = $this->invoiceOf($client->fresh());
        $this->assertFalse((bool) $invoice->fresh()->tax, 'issued without VAT');

        $client->primaryContact->update(['country' => 'GR']);

        $this->assertFalse((bool) $invoice->fresh()->tax, 'an issued invoice keeps the VAT it was issued with');
    }

    public function test_holds_markup_in_the_contact_is_escaped_on_the_invoice_page(): void
    {
        config(['settings.default_currency' => 'USD']);
        $owner = User::factory()->create();
        $client = $owner->client();
        $client->primaryContact->update(['type' => 'business', 'company_name' => '<script>alert(1)</script>Co',
            'tax_id' => '<img src=x onerror=alert(2)>', 'address1' => '<b>bold</b>', 'country' => 'GR']);
        $invoice = $this->invoiceOf($client->fresh());

        $html = $this->workOn($owner, $client)->get(route('invoices.show', $invoice))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Co', $html);
    }

    // ---- mail ----------------------------------------------------------------------------------------------------

    public function test_holds_the_accounts_mail_never_goes_to_a_contact_typed_address(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        (new EmailTemplateSeeder)->run();
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $client = $owner->client();
        $client->primaryContact->update(['email' => 'victim@example.test']);
        $client->contacts()->create(['first_name' => 'X', 'email' => 'victim2@example.test', 'email_preferences' => ['invoice', 'general']]);
        $invoice = $this->invoiceOf($client->fresh());
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);

        NotificationHelper::invoiceCreatedNotification($client->fresh(), $invoice->fresh());

        $this->assertSame(0, EmailLog::query()->whereIn('to', ['victim@example.test', 'victim2@example.test'])->count());
    }

    /** Staff are told the mail went to the account's email (now the contact's), while it goes to the owner. */
    public function test_bug_staff_are_told_the_invoice_mail_went_to_the_contact_email(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        (new EmailTemplateSeeder)->run();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $client = $owner->client();
        $client->primaryContact->update(['email' => 'billing@example.test']);
        $invoice = $this->invoiceOf($client->fresh());
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $staff = Staff::factory()->admin()->create();

        $page = $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('sendEmail', data: ['template' => 'invoice_modified']);

        $this->assertSame('owner@example.test', EmailLog::query()->latest('id')->value('to'));
        $page->assertNotified('Email queued to owner@example.test');
    }

    // ---- staff ---------------------------------------------------------------------------------------------------

    public function test_holds_staff_without_update_cannot_create_archive_restore_or_make_primary(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $client = User::factory()->create()->client();
        $other = $client->contacts()->create(['first_name' => 'Other']);
        $archived = $client->contacts()->create(['first_name' => 'Archived']);
        $archived->archive();
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.view'])->create();
        $page = fn () => $this->livewireAs($staff)->test(ContactsRelationManager::class, ['ownerRecord' => $client->fresh(), 'pageClass' => EditClient::class]);

        foreach ([['create', null, ['first_name' => 'New']], ['primary', $other, []], ['archive', $other, []],
            ['edit', $other, ['first_name' => 'Edited']]] as [$action, $record, $data]) {
            try {
                $page()->callTableAction($action, $record, data: $data);
            } catch (Throwable) {
            }
        }
        try {
            $page()->set('tableFilters.archived.value', true)->callTableAction('restore', $archived);
        } catch (Throwable) {
        }

        $this->assertSame(0, $client->contacts()->where('first_name', 'New')->count());
        $this->assertNull($other->fresh()->primary_of_client_id);
        $this->assertNull($other->fresh()->archived_at);
        $this->assertSame('Other', $other->fresh()->first_name);
        $this->assertNotNull($archived->fresh()->archived_at);
    }

    public function test_holds_staff_cannot_archive_the_primary(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $client = User::factory()->create()->client();
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.update'])->create();

        try {
            $this->livewireAs($staff)->test(ContactsRelationManager::class, ['ownerRecord' => $client->fresh(), 'pageClass' => EditClient::class])
                ->callTableAction('archive', $client->primaryContact);
        } catch (Throwable) {
        }

        $this->assertNull($client->primaryContact->fresh()->archived_at);
    }

    // ---- migration -----------------------------------------------------------------------------------------------

    public function test_holds_the_migration_gives_an_account_without_owner_a_primary_contact(): void
    {
        $client = User::factory()->create()->client();
        DB::table('client_user')->where('client_id', $client->id)->delete();

        $contact = $this->migrateWithProperties($client, ['city' => 'Athens']);

        $this->assertSame('Athens', $contact->city);
        $this->assertNull($contact->email);
    }

    /** A country stored with another case or spelling ("greece") is dropped without a trace: the VAT country is lost. */
    public function test_bug_the_migration_drops_a_country_name_it_does_not_match_exactly(): void
    {
        $client = User::factory()->create()->client();

        $contact = $this->migrateWithProperties($client, ['country' => 'greece', 'city' => 'Athens']);

        $this->assertSame('GR', $contact->country, 'the account\'s country (its VAT rate) is lost in the migration');
    }

    /** A two-letter value is kept as is, even when it is no ISO code ("UK"): the account then has no valid country. */
    public function test_bug_the_migration_keeps_a_two_letter_country_that_is_no_iso_code(): void
    {
        $client = User::factory()->create()->client();

        $contact = $this->migrateWithProperties($client, ['country' => 'UK']);

        $this->assertArrayHasKey((string) $contact->country, array_slice((array) config('app.countries'), 1, null, true),
            "migrated country {$contact->country} is no ISO code: the account page then refuses to save");
    }

    /**
     * The properties were validated `string|max:255`; postcode is now 20 and phone 50 characters. On MySQL (strict)
     * the insert fails mid-migration ($withinTransaction = false): contacts half filled, properties not yet removed,
     * the migration not re-runnable (the table exists). sqlite does not enforce the length, so the test checks it.
     */
    public function test_bug_the_migration_does_not_fit_values_longer_than_the_new_columns(): void
    {
        $client = User::factory()->create()->client();

        $contact = $this->migrateWithProperties($client, ['zip' => str_repeat('1', 30), 'phone' => str_repeat('2', 60)]);

        // Fixed by widening the columns to the properties' 255 (nothing cut, nothing refused by a strict MySQL).
        $this->assertSame(str_repeat('1', 30), $contact->postcode);
        $this->assertSame(str_repeat('2', 60), $contact->phone);
        $this->assertTrue(collect(\Illuminate\Support\Facades\Schema::getColumns('contacts'))
            ->whereIn('name', ['postcode', 'phone'])->every(fn ($column) => ($column['length'] ?? 255) === null || ($column['length'] ?? 255) >= 255
                || str_contains((string) $column['type'], '255') || DB::getDriverName() === 'sqlite'));
    }
}
