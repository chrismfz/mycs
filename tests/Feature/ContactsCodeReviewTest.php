<?php

namespace Tests\Feature;

use App\Admin\Components\ClientComponent;
use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Import\WhmcsTicketImport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Code review of PR 3a (contacts): the findings' tests stay as regression coverage. */
class ContactsCodeReviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * C1: the importer's ticket-guest path opened an account with no primary contact. Since PR Δ (docs/tickets.md §6ζ)
     * the ticket import opens no account at all: a WHMCS client's ticket goes to the account its login owns, anything
     * else is a guest's.
     */
    public function test_the_ticket_import_opens_no_account(): void
    {
        $user = User::factory()->withoutClient()->create();
        $whmcs = new \PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tbltickets (id INTEGER PRIMARY KEY, tid TEXT, did INT, userid INT, name TEXT, email TEXT, title TEXT, message TEXT, status TEXT,
            urgency TEXT, date TEXT, lastreply TEXT, admin TEXT, attachment TEXT)');
        $whmcs->exec("INSERT INTO tbltickets VALUES (1, '111111', 0, {$user->id}, '', '', 'Hi', 'Hello', 'Open', 'Medium', '2026-01-01 10:00:00', '', '', '')");
        $whmcs->exec('CREATE TABLE tblticketreplies (id INTEGER PRIMARY KEY, tid INT, userid INT, admin TEXT, message TEXT, date TEXT, attachment TEXT)');
        $clients = Client::query()->count();

        (new WhmcsTicketImport($whmcs))->run();

        $this->assertSame($clients, Client::query()->count());
        $this->assertNull(Ticket::query()->sole()->client_id, 'a login that owns no account: a guest\'s ticket');
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
