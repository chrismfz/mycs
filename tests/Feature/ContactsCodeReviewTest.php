<?php

namespace Tests\Feature;

use App\Admin\Components\ClientComponent;
use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Console\Commands\ImportFromWhmcs;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/** Code review of PR 3a (contacts): the findings' tests stay as regression coverage. */
class ContactsCodeReviewTest extends TestCase
{
    use RefreshDatabase;

    /** C1: the importer's ticket-guest path opened an account with no primary contact. */
    public function test_every_account_opened_by_the_importer_has_a_primary_contact(): void
    {
        $user = User::factory()->withoutClient()->create();
        $method = new ReflectionMethod(ImportFromWhmcs::class, 'clientIdOfUser');
        $clientId = $method->invoke(app(ImportFromWhmcs::class), $user->id);

        $this->assertNotNull(Client::query()->findOrFail($clientId)->primaryContact, 'an account without its primary contact');
    }

    /** C3: saving the details of an account without a primary contact crashed (client_id NOT NULL). */
    public function test_staff_can_save_the_details_of_an_account_without_a_primary_contact(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $client = User::factory()->create()->client();
        $client->primaryContact()->delete(); // as an account opened by a raw insert
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.view', 'admin.clients.update'])->create();

        $this->livewireAs($staff)->test(EditClient::class, ['record' => $client->getRouteKey()])
            ->fillForm(['primaryContact.company_name' => 'Acme', 'primaryContact.type' => 'business'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Acme', $client->fresh()->primaryContact?->company_name);
        $this->assertSame($client->id, $client->fresh()->primaryContact?->client_id);
    }

    /** C4: a country name the migration could not convert was dropped without a trace. */
    public function test_the_migration_does_not_silently_lose_a_country_it_cannot_convert(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Re-running a migration inside the test transaction needs transactional DDL (sqlite).');
        }
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['country' => 'GR', 'city' => 'Athens']);
        $migration = require database_path('migrations/2026_10_30_000000_create_contacts_table.php');
        $migration->down();
        DB::table('properties')->where('model_id', $client->id)->where('key', 'country')->update(['value' => 'greece']);

        $migration->up();

        $contact = DB::table('contacts')->where('primary_of_client_id', $client->id)->first();
        $this->assertTrue($contact->country === 'GR' || str_contains((string) $contact->notes, 'greece'),
            'the unconvertible country «greece» was dropped without a trace');
    }

    /** C2: a business's company was printed twice on its invoices. */
    public function test_an_invoice_to_a_business_shows_its_company_once(): void
    {
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['type' => 'business', 'company_name' => 'Acme ΙΚΕ', 'address1' => 'Odos 1']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD'])->fresh();

        $lines = array_merge([$invoice->user_name], array_values($invoice->user_properties));

        $this->assertSame(1, count(array_keys($lines, 'Acme ΙΚΕ', true)), 'the company is printed twice: ' . implode(' | ', $lines));
    }

    /** C5: searching for an account by its company (its name now) found nothing in the admin lists. */
    public function test_a_client_search_finds_the_company_and_the_primary_contact(): void
    {
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['type' => 'business', 'company_name' => 'Zeta Hosting', 'email' => 'billing@zeta.example']);
        Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        Invoice::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);

        foreach (['Zeta', 'billing@zeta'] as $search) {
            $this->assertSame([$client->id], ClientComponent::search(Invoice::query(), $search)->pluck('client_id')->all());
        }
    }
}
