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
 * Adversarial review of #542 (E4: Ekdosi's plugin contacts and assignments). Invented data only: the shapes are those of
 * the plugin's tables (mod_ekdosi_contacts, mod_ekdosi_routing, which has no unique key on the item).
 */
class ImportEkdosiContactsAdversarialTest extends TestCase
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

    private function contact(int $id, int $client, string $company, ?string $country = 'ΕΛΛΑΔΑ', ?string $vies = null): void
    {
        $this->whmcs->prepare("INSERT INTO mod_ekdosi_contacts (id, userid, company_name, gr_vatno, vies_vatno, tax_office, address1, city, postal_code, country,
            created_at) VALUES (?, ?, ?, '099999999', ?, 'Α΄ Αθηνών', 'Οδός 1', 'Αθήνα', '10431', ?, '2024-01-01 00:00:00')")
            ->execute([$id, $client, $company, $vies, $country]);
    }

    private function route(int $id, int $client, int $contact, string $type, int $item, bool $receipt = false): void
    {
        $this->whmcs->prepare('INSERT INTO mod_ekdosi_routing (id, userid, contactid, serviceid, service_type, is_receipt) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $client, $contact, $item, $type, (int) $receipt]);
    }

    /**
     * Severity: high (fiscal: a receipt issued as an invoice).
     * Scenario: a contact's assignments are a receipt on its customer's service and an invoice on a service since moved to
     * another customer (skipped). The only assignment imported is a receipt, but the receipt/invoice count is taken over
     * all of them, skipped ones too: the contact becomes «invoice», and its service's document a τιμολόγιο.
     * Expected: document_type from the assignments actually imported: «receipt».
     */
    public function test_adv_542_1_a_skipped_assignment_does_not_turn_the_kept_receipt_into_an_invoice(): void
    {
        $client = User::factory()->create()->client();
        $other = User::factory()->create()->client();
        $kept = Service::factory()->create(['client_id' => $client->id]);
        $moved = Service::factory()->create(['client_id' => $other->id]);
        $this->contact(1, $client->id, 'Delta');
        $this->route(10, $client->id, 1, 'hosting', $kept->id, receipt: true);
        $this->route(11, $client->id, 1, 'hosting', $moved->id);

        (new WhmcsEkdosiContactImport($this->whmcs))->run();

        $contact = Contact::query()->where('company_name', 'Delta')->sole();
        $this->assertSame($contact->id, $kept->fresh()->contact_id);
        $this->assertSame('receipt', $contact->document_type, 'its only imported assignment is a receipt, as the plugin issued it');
    }

    /**
     * Severity: high (fiscal: a foreign EU company issued to as a Greek one).
     * Scenario: a contact with no country (23 assigned ones on myip's copy) whose VIES number is another member state's
     * («NL…»: there is one such on the copy). The importer gives it its account's country (the reseller's, GR): the feed
     * then sends a Dutch VAT number with country GR, where the plugin sent none.
     * Expected: the country of its VAT number (NL), or none and listed; never the account's GR silently.
     */
    public function test_adv_542_2_a_contact_without_country_and_a_foreign_vat_number_is_not_given_the_accounts_greece(): void
    {
        $client = User::factory()->create()->client();
        $client->contacts()->whereNotNull('primary_of_client_id')->update(['country' => 'GR']);
        $service = Service::factory()->create(['client_id' => $client->id]);
        $this->contact(1, $client->id, 'Dutch BV', null, 'NL123456789B01');
        $this->route(10, $client->id, 1, 'hosting', $service->id);

        $result = (new WhmcsEkdosiContactImport($this->whmcs))->run();

        $country = Contact::query()->where('company_name', 'Dutch BV')->value('country');
        $this->assertNotSame('GR', $country, 'a Dutch VAT number is not a Greek company: never the account\'s country');
        $this->assertTrue($country === 'NL' || ($country === null && str_contains(implode("\n", $result['notes']), 'Ekdosi contact #1')),
            'its VAT number\'s country, or none and listed');
    }

    /**
     * Severity: medium (an item silently given one of two recipients).
     * Scenario: mod_ekdosi_routing has no unique key on (serviceid, service_type): two rows for one service, to two of its
     * customer's contacts. The importer writes both, the later contact overwriting the earlier, says nothing, and counts
     * the service twice.
     * Expected: the conflict listed (the service named), and the service counted once.
     */
    public function test_adv_542_3_two_assignments_of_one_item_are_listed_not_settled_silently(): void
    {
        $client = User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $client->id]);
        $this->contact(1, $client->id, 'First ΑΕ');
        $this->contact(2, $client->id, 'Second ΑΕ');
        $this->route(10, $client->id, 1, 'hosting', $service->id);
        $this->route(11, $client->id, 2, 'hosting', $service->id);

        $result = (new WhmcsEkdosiContactImport($this->whmcs))->run();

        $this->assertStringContainsString("hosting #{$service->id}", implode("\n", $result['notes']), 'two recipients for one item: listed');
        $this->assertSame(1, $result['counts']['services'], 'one service assigned, not two');
    }

    /**
     * Severity: low (owner 2026-10-30: only contacts with an assignment).
     * Scenario: a contact whose only assignment is of a service now another customer's (skipped). It is imported anyway,
     * assigned to nothing, and counted as imported.
     * Expected: not imported (it has no assignment here), as the contacts never assigned.
     */
    public function test_adv_542_4_a_contact_whose_every_assignment_is_skipped_is_not_imported(): void
    {
        $client = User::factory()->create()->client();
        $other = User::factory()->create()->client();
        $moved = Service::factory()->create(['client_id' => $other->id]);
        $this->contact(1, $client->id, 'Orphan ΙΚΕ');
        $this->route(10, $client->id, 1, 'hosting', $moved->id);

        $result = (new WhmcsEkdosiContactImport($this->whmcs))->run();

        $this->assertFalse(Contact::query()->where('company_name', 'Orphan ΙΚΕ')->exists(), 'no assignment imported: stays in WHMCS\'s archive');
        $this->assertSame(0, $result['counts']['contacts']);
    }
}
