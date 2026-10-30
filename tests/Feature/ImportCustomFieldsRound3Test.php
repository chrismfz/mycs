<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsCustomFieldImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

/**
 * Round-3 review of #544 (after 60a76692), code and adversarial, without the author's context. Each test is a defect
 * found: it fails until fixed. Invented data only.
 */
class ImportCustomFieldsRound3Test extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private int $field = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblcustomfields (id INTEGER PRIMARY KEY, type TEXT, relid INT, fieldname TEXT, fieldtype TEXT)');
        $this->whmcs->exec('CREATE TABLE tblcustomfieldsvalues (id INTEGER PRIMARY KEY, fieldid INT, relid INT, value TEXT)');
        $this->whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT)');
        $this->whmcs->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, servertype TEXT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, packageid INT)');
    }

    private function field(string $type, int $relid, string $name, string $kind = 'text'): int
    {
        $this->whmcs->prepare('INSERT INTO tblcustomfields VALUES (?, ?, ?, ?, ?)')->execute([++$this->field, $type, $relid, $name, $kind]);

        return $this->field;
    }

    private function value(int $field, int $relid, string $value): void
    {
        $this->whmcs->prepare('INSERT INTO tblcustomfieldsvalues (fieldid, relid, value) VALUES (?, ?, ?)')->execute([$field, $relid, $value]);
    }

    private function service(): Service
    {
        $client = User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $client->id]);
        $this->whmcs->exec("INSERT INTO tblproducts VALUES (70, '')");
        $this->whmcs->exec("INSERT INTO tblhosting VALUES ({$service->id}, 70)");

        return $service;
    }

    /**
     * R3-544-1, Medium (secrets; none on the copies today). Scenario: WHMCS has a «Password» field type
     * (tblcustomfields.fieldtype = 'password', shown masked in WHMCS), and a product's field of that type can be named
     * anything («Plesk login», «Πρόσβαση»). The importer reads fieldtype but only matches the name against SECRET, so
     * the masked value goes into the service's notes in clear. Expected: a field WHMCS itself masks never reaches the
     * notes (listed, as the other secrets).
     */
    public function test_r3_544_1_a_field_of_whmcs_password_type_stays_out_of_notes(): void
    {
        $service = $this->service();
        $this->value($this->field('product', 70, 'Plesk login', 'password'), $service->id, 'masked-s3cr3t');

        (new WhmcsCustomFieldImport($this->whmcs))->run();

        $this->assertStringNotContainsString('masked-s3cr3t', (string) $service->fresh()->notes);
    }

    /**
     * R3-544-2, Medium (secrets; none on the copies today). Scenario: modules' system names glue the word on
     * («rootpass», «dbpass», «adminpw»), or call the secret something else («Passcode», «Auth Key», cPanel's «Access
     * Hash», «Κλειδί API», «Κωδ. πρόσβασης»). R2 made «pass»/«pw» whole words only (for «Passport»), so none of these
     * match SECRET and each value goes into the notes in clear. Expected: never a secret in the notes.
     */
    public function test_r3_544_2_secrets_with_glued_or_other_names_stay_out_of_notes(): void
    {
        $service = $this->service();
        $secrets = ['rootpass' => 'v-rootpass', 'dbpass' => 'v-dbpass', 'adminpw' => 'v-adminpw', 'Passcode' => 'v-passcode',
            'Auth Key' => 'v-authkey', 'Access Hash' => 'v-accesshash', 'Κλειδί API' => 'v-kleidi', 'Κωδ. πρόσβασης' => 'v-kod'];
        foreach ($secrets as $name => $value) {
            $this->value($this->field('product', 70, $name), $service->id, $value);
        }

        (new WhmcsCustomFieldImport($this->whmcs))->run();

        $notes = (string) $service->fresh()->notes;
        foreach ($secrets as $name => $value) {
            $this->assertStringNotContainsString($value, $notes, "«{$name}» is a secret");
        }
    }

    /**
     * R3-544-3, Low (not on the copies: myip has only «ΑΦΜ / Vies Vat No»). Scenario: an instance with both account
     * fields CLIENT_FIELDS knows for the ΑΦΜ, «ΑΦΜ / Vies Vat No» with a foreign number and a later «ΑΦΜ» with a Greek
     * one. The second overwrites tax_id, but the vat_number set from the first stays: the contact's VIES number (reverse
     * charge) is not the ΑΦΜ it sends to Ekdosi, which R2 meant to prevent («only with the ΑΦΜ it comes with").
     * Expected: vat_number is set only from the value that ends up in tax_id.
     */
    public function test_r3_544_3_vat_number_never_from_another_afm_field_than_the_tax_id(): void
    {
        $client = User::factory()->create()->client();
        $this->value($this->field('client', 0, 'ΑΦΜ / Vies Vat No'), $client->id, 'DE123456789');
        $this->value($this->field('client', 0, 'ΑΦΜ'), $client->id, '099999999');

        (new WhmcsCustomFieldImport($this->whmcs))->run();

        $contact = Contact::query()->where('primary_of_client_id', $client->id)->sole();
        $this->assertTrue($contact->vat_number === null || $contact->vat_number === $contact->tax_id,
            "vat_number {$contact->vat_number} beside tax_id {$contact->tax_id}");
    }
}
