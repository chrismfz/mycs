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
 * Round-3 review of #542 (after a1450e51). Invented data only, in the shapes of the plugin's tables.
 */
class ImportEkdosiContactsRound3Test extends TestCase
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

    /** A foreign company with the given WHMCS country text, routed on one service of its customer's. */
    private function foreignCompanyRouted(string $country): Service
    {
        $client = User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $client->id]);
        $this->whmcs->prepare("INSERT INTO mod_ekdosi_contacts (id, userid, company_name, vies_vatno, address1, city, postal_code, country, created_at)
            VALUES (1, ?, 'Foreign Co', 'XX00000000', 'Street 1', 'Town', '00100', ?, '2024-01-01 00:00:00')")
            ->execute([$client->id, $country]);
        $this->whmcs->prepare("INSERT INTO mod_ekdosi_routing (id, userid, contactid, serviceid, service_type, is_receipt) VALUES (10, ?, 1, ?, 'hosting', 0)")
            ->execute([$client->id, $service->id]);

        return $service;
    }

    /**
     * Severity: low (fiscal: a foreign recipient issued as a Greek one; none on myip's copy today).
     * Scenario: a contact whose WHMCS country is a Greek name other than «ΕΛΛΑΔΑ/ΕΛΛΑΣ/ΚΥΠΡΟΣ» («ΙΤΑΛΙΑ»: the plugin's
     * form takes free text). The plugin sends «ΙΤΑΛΙΑ» and Ekdosi resolves it (IsoCountry: IT), or holds an unknown one
     * for the operator. The importer knows only three Greek names: it imports the contact with no country and routes the
     * service to it, so MyCS's feed sends `country: ''`, which Ekdosi's ContactCustomerResolver reads as «GR»
     * (`?: 'GR'`): an Italian company issued as a domestic one, with no hold. The import note does not stop the feed.
     * Expected: a country the plugin would have sent and Ekdosi resolves is resolved here too (IT), never turned into
     * none that downstream reads as Greece.
     */
    public function test_r3_542_1_a_greek_country_name_beyond_the_three_is_not_sent_as_none_which_ekdosi_reads_as_greece(): void
    {
        $service = $this->foreignCompanyRouted('ΙΤΑΛΙΑ');

        (new WhmcsEkdosiContactImport($this->whmcs))->run();

        $contact = Contact::query()->where('company_name', 'Foreign Co')->first();
        $this->assertNotNull($contact);
        $this->assertSame($contact->id, $service->fresh()->contact_id, 'routed, as the plugin');
        $this->assertSame('IT', $contact->country, "«ΙΤΑΛΙΑ» is Italy (Ekdosi's IsoCountry resolves it); none would be read as GR by Ekdosi");
    }

    /**
     * Severity: low (as R3-542-1).
     * Scenario: the WHMCS country is «UK» (the common form; Ekdosi's IsoCountry aliases it to GB). EuVat::iso takes a
     * two-letter value as a code only, and «UK» is none: the contact gets no country, the feed sends '', Ekdosi reads GR.
     * Expected: GB, as Ekdosi would have resolved what the plugin sent.
     */
    public function test_r3_542_2_uk_is_great_britain_not_none(): void
    {
        $this->foreignCompanyRouted('UK');

        (new WhmcsEkdosiContactImport($this->whmcs))->run();

        $this->assertSame('GB', Contact::query()->where('company_name', 'Foreign Co')->value('country'));
    }
}
