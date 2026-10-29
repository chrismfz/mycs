<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Domain;
use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsEkdosiContactImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

/**
 * E4 of the Ekdosi bridge: Ekdosi's WHMCS plugin's contacts and assignments, so the feed sends the same recipient after
 * the cut-over. Only contacts with an assignment; one of an item now another customer's is skipped and listed (owner
 * 2026-10-30); receipt or invoice per contact; a WHMCS without the plugin imports nothing.
 */
class ImportEkdosiContactsTest extends TestCase
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

    private function contact(int $id, int $client, string $company, string $country = 'GR'): void
    {
        $this->whmcs->prepare("INSERT INTO mod_ekdosi_contacts (id, userid, company_name, gr_vatno, vies_vatno, tax_office, address1, city, postal_code, country,
            description, email, telephone, created_at) VALUES (?, ?, ?, '099999999', 'EL099999999', 'Α΄ Αθηνών', 'Οδός 1', 'Αθήνα', '10431', ?, 'Hosting', 'acc@example.test',
            '2100000000', '0000-00-00 00:00:00')")->execute([$id, $client, $company, $country]);
    }

    private function route(int $id, int $client, int $contact, string $type, int $item, bool $receipt = false): void
    {
        $this->whmcs->prepare('INSERT INTO mod_ekdosi_routing (id, userid, contactid, serviceid, service_type, is_receipt) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $client, $contact, $item, $type, (int) $receipt]);
    }

    public function test_the_contacts_and_their_assignments_come_as_the_plugin_had_them(): void
    {
        $client = User::factory()->create()->client();
        $other = User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $client->id]);
        $domain = Domain::factory()->create(['client_id' => $client->id]);
        $moved = Service::factory()->create(['client_id' => $other->id]); // moved to another customer in WHMCS
        $receipt = Service::factory()->create(['client_id' => $client->id]);
        $this->contact(1, $client->id, 'Alpha ΙΚΕ');
        $this->contact(2, $client->id, 'Never used ΑΕ'); // no assignment: stays in WHMCS's archive
        $this->contact(3, $client->id, 'Beta', 'Greece');
        $this->route(10, $client->id, 1, 'hosting', $service->id);
        $this->route(11, $client->id, 1, 'domain', $domain->id);
        $this->route(12, $client->id, 1, 'hosting', $moved->id);
        $this->route(13, $client->id, 3, 'hosting', $receipt->id, receipt: true);
        $this->route(14, $client->id, 99, 'hosting', $service->id); // a contact the plugin lost

        $result = (new WhmcsEkdosiContactImport($this->whmcs))->run();

        $alpha = Contact::query()->where('company_name', 'Alpha ΙΚΕ')->sole();
        $this->assertSame([$client->id, 'business', '099999999', 'EL099999999', 'Α΄ Αθηνών', 'Hosting', '10431', 'GR', '2100000000', 'invoice'],
            [$alpha->client_id, $alpha->type, $alpha->tax_id, $alpha->vat_number, $alpha->tax_office, $alpha->activity, $alpha->postcode, $alpha->country,
                $alpha->phone, $alpha->document_type]);
        $this->assertSame($alpha->id, $service->fresh()->contact_id);
        $this->assertSame($alpha->id, $domain->fresh()->contact_id);
        $this->assertNull($moved->fresh()->contact_id, 'another customer\'s now: never given this one\'s contact');
        $this->assertSame('receipt', Contact::query()->where('company_name', 'Beta')->value('document_type'), 'all its assignments receipts');
        $this->assertSame('GR', Contact::query()->where('company_name', 'Beta')->value('country'), 'a country\'s name, as the app knows it');
        $this->assertFalse(Contact::query()->where('company_name', 'Never used ΑΕ')->exists(), 'never assigned: not imported');
        $this->assertSame(['contacts' => 2, 'services' => 2, 'domains' => 1, 'skipped' => 2], $result['counts']);
        $notes = implode("\n", $result['notes']);
        $this->assertStringContainsString("hosting #{$moved->id} is customer #{$other->id}'s now", $notes);
        $this->assertStringContainsString('its contact #99 is not in mod_ekdosi_contacts', $notes);
    }

    public function test_a_contact_with_both_kinds_is_an_invoice_and_listed(): void
    {
        $client = User::factory()->create()->client();
        [$a, $b] = [Service::factory()->create(['client_id' => $client->id]), Service::factory()->create(['client_id' => $client->id])];
        $this->contact(1, $client->id, 'Gamma ΟΕ');
        $this->route(10, $client->id, 1, 'hosting', $a->id, receipt: true);
        $this->route(11, $client->id, 1, 'hosting', $b->id);

        $result = (new WhmcsEkdosiContactImport($this->whmcs))->run();

        $this->assertSame('invoice', Contact::query()->where('company_name', 'Gamma ΟΕ')->value('document_type'));
        $this->assertStringContainsString('1 of its 2 assignments are receipts', implode("\n", $result['notes']));
    }

    public function test_a_whmcs_without_the_plugin_imports_nothing_and_says_so(): void
    {
        $result = (new WhmcsEkdosiContactImport(new PDO('sqlite::memory:')))->run();

        $this->assertSame(0, $result['counts']['contacts']);
        $this->assertStringContainsString('no Ekdosi plugin tables', implode("\n", $result['notes']));
    }

    /** «ΕΛΛΑΔΑ», «Κύπρος» (126 on myip's copy) are countries; none given: its account's. */
    public function test_greek_country_names_and_none_given(): void
    {
        $client = User::factory()->create()->client();
        $client->contacts()->whereNotNull('primary_of_client_id')->update(['country' => 'GR']);
        [$a, $b, $c] = [Service::factory()->create(['client_id' => $client->id]), Service::factory()->create(['client_id' => $client->id]),
            Service::factory()->create(['client_id' => $client->id])];
        $this->contact(1, $client->id, 'Ελλάδα ΑΕ', 'ΕΛΛΆΔΑ');
        $this->contact(2, $client->id, 'Κύπρος Ltd', 'Κύπρος');
        $this->contact(3, $client->id, 'Χωρίς χώρα', '');
        foreach ([[10, 1, $a], [11, 2, $b], [12, 3, $c]] as [$id, $contact, $service]) {
            $this->route($id, $client->id, $contact, 'hosting', $service->id);
        }

        (new WhmcsEkdosiContactImport($this->whmcs))->run();

        $this->assertSame(['Ελλάδα ΑΕ' => 'GR', 'Κύπρος Ltd' => 'CY', 'Χωρίς χώρα' => 'GR'],
            Contact::query()->whereNull('primary_of_client_id')->orderBy('id')->pluck('country', 'company_name')->all());
    }
}
