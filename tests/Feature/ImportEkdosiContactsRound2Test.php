<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsEkdosiContactImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

/**
 * Round-2 review of #542 (after bd68ea3c). Invented data only, in the shapes of the plugin's tables.
 */
class ImportEkdosiContactsRound2Test extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE mod_ekdosi_contacts (id INTEGER PRIMARY KEY, legacy_contact_id INT, userid INT, company_name TEXT, gr_vatno TEXT,
            vies_vatno TEXT, tax_office TEXT, address1 TEXT, address2 TEXT, city TEXT, postal_code TEXT, country TEXT, description TEXT, email TEXT,
            telephone TEXT, comments TEXT, source TEXT, created_at TEXT, updated_at TEXT)');
        $this->whmcs->exec('CREATE TABLE mod_ekdosi_routing (id INTEGER PRIMARY KEY, legacy_routing_id INT, userid INT, contactid INT, serviceid INT,
            service_type TEXT, is_receipt INT, source TEXT, created_at TEXT, updated_at TEXT)');
    }

    private function contact(int $id, int $client, string $company): void
    {
        $this->whmcs->prepare("INSERT INTO mod_ekdosi_contacts (id, userid, company_name, gr_vatno, tax_office, address1, city, postal_code, country,
            created_at) VALUES (?, ?, ?, '099999999', 'Α΄ Αθηνών', 'Οδός 1', 'Αθήνα', '10431', 'ΕΛΛΑΔΑ', '2024-01-01 00:00:00')")
            ->execute([$id, $client, $company]);
    }

    private function route(int $id, int $client, int $contact, string $type, int $item): void
    {
        $this->whmcs->prepare('INSERT INTO mod_ekdosi_routing (id, userid, contactid, serviceid, service_type, is_receipt) VALUES (?, ?, ?, ?, ?, 0)')
            ->execute([$id, $client, $contact, $item, $type]);
    }

    /**
     * Severity: medium (fiscal: a routed item issued to the account instead of its contact).
     * Scenario: a service moved in WHMCS from customer A to customer B. A's old assignment (#10, userid A) stayed, and B
     * assigned it to its own contact (#11, userid B); the routing table has no unique key on the item. The plugin's
     * resolveInvoice looks the route up by (the invoice's userid, serviceid, service_type), so B's invoices go to B's
     * contact. The importer keeps «the first assignment» per (type, item) only, across customers: #10 is skipped as
     * moved, and #11 is then skipped as «has another one already (#10), which it keeps», though #10 was kept nowhere.
     * B's contact is not imported and the service's documents go to B's account.
     * Expected: the first assignment per (customer, type, item), as the plugin's lookup: the service gets B's contact.
     */
    public function test_r2_542_1_a_moved_items_new_owners_assignment_is_not_shadowed_by_the_old_owners(): void
    {
        $old = User::factory()->create()->client();
        $new = User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $new->id]);
        $this->contact(1, $old->id, 'Old Owner Contact');
        $this->contact(2, $new->id, 'New Owner Contact');
        $this->route(10, $old->id, 1, 'hosting', $service->id);
        $this->route(11, $new->id, 2, 'hosting', $service->id);

        $result = (new WhmcsEkdosiContactImport($this->whmcs))->run();

        $contact = Contact::query()->where('company_name', 'New Owner Contact')->first();
        $this->assertNotNull($contact, "the new owner's contact has an assignment the plugin applies: imported");
        $this->assertSame($contact->id, $service->fresh()->contact_id, "as the plugin: B's invoices route to B's contact");
        $this->assertSame(1, $result['counts']['skipped'], "only the old owner's assignment is skipped");
    }
}
