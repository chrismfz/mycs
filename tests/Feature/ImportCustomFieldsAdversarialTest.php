<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsCustomFieldImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of #544 (WHMCS's custom fields into MyCS's own fields), without the author's context. Each test is
 * a defect found: it fails until fixed. Invented data only.
 */
class ImportCustomFieldsAdversarialTest extends TestCase
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

    private function primary(int $clientId): Contact
    {
        return Contact::query()->where('primary_of_client_id', $clientId)->sole();
    }

    private function run544(): array
    {
        return (new WhmcsCustomFieldImport($this->whmcs))->run();
    }

    /**
     * ADV-544-1, Medium. Scenario: an account's «ΑΦΜ / Vies Vat No» holds more than contacts.tax_id's 50 characters
     * (a customer typing the ΑΦΜ with the ΔΟΥ and a note in one box). The import cuts at 255, not 50: on MariaDB strict
     * the UPDATE fails with «Data too long» and the whole import aborts (SQLite stores the 60 characters).
     * Expected: the value fits its column (cut, or kept out and listed for staff), and the import goes on.
     */
    public function test_adv_544_1_a_long_afm_fits_tax_id_column(): void
    {
        $client = User::factory()->create()->client();
        $afm = $this->field('client', 0, 'ΑΦΜ / Vies Vat No');
        $this->value($afm, $client->id, '099999999 ' . str_repeat('Χ', 50));

        $this->run544();

        $this->assertLessThanOrEqual(50, mb_strlen((string) $this->primary($client->id)->tax_id), 'contacts.tax_id is varchar(50)');
    }

    /**
     * ADV-544-2, Low (myip's rows are 'on' or '' today). Scenario: a tickbox stored as '1', 'yes' or 'On' (WHMCS's API,
     * an older version, a manual edit). Ekdosi's WHMCS path (PendingWhmcsInvoice::wantsInvoice / wantsImmediateInvoice)
     * reads on/1/yes/true/ναι/checked, case-insensitively, as ticked; the import reads only 'on'.
     * Expected: the same answer Ekdosi read from WHMCS: invoice, and immediate invoicing.
     */
    public function test_adv_544_2_tickbox_values_read_as_ekdosi_read_them(): void
    {
        $client = User::factory()->create()->client();
        $this->value($this->field('client', 0, 'Θα ήθελα να εκδοθεί τιμολόγιο', 'tickbox'), $client->id, '1');
        $this->value($this->field('client', 0, 'Γκρινιάρης', 'tickbox'), $client->id, 'Yes');

        $this->run544();

        $this->assertSame('invoice', $this->primary($client->id)->document_type);
        $this->assertTrue((bool) $client->fresh()->immediate_invoicing);
    }

    /**
     * ADV-544-3, Medium. Scenario: besides «ΑΦΜ / Vies Vat No» the account has another field whose name starts with
     * «VAT» («VAT exempt», a tickbox). The prefix match sends it to tax_id too, and the later row wins: the ΑΦΜ sent
     * to myDATA becomes «on». Expected: the ΑΦΜ the WHMCS plugin sent (its mapped field) stays the account's tax_id.
     */
    public function test_adv_544_3_another_vat_field_never_overwrites_the_afm(): void
    {
        $client = User::factory()->create()->client();
        $this->value($this->field('client', 0, 'ΑΦΜ / Vies Vat No'), $client->id, '099999999');
        $this->value($this->field('client', 0, 'VAT exempt', 'tickbox'), $client->id, 'on');

        $this->run544();

        $this->assertSame('099999999', $this->primary($client->id)->tax_id);
    }

    /**
     * ADV-544-4, High (secrets). Scenario: a service's secret fields not named «password»: a label that says it
     * («rootpw|Root Password»: only the part before | is checked), Greek («Κωδικός πρόσβασης»), «API Token», «Secret»,
     * «pass». Each goes into the service's notes in clear. Expected: never a secret in the notes (listed, not imported).
     */
    public function test_adv_544_4_secret_fields_not_named_password_stay_out_of_notes(): void
    {
        $client = User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $client->id]);
        $this->whmcs->exec("INSERT INTO tblproducts VALUES (50, '')");
        $this->whmcs->exec("INSERT INTO tblhosting VALUES ({$service->id}, 50)");
        $secrets = ['rootpw|Root Password' => 's3cret-a', 'Κωδικός πρόσβασης' => 's3cret-b', 'API Token' => 's3cret-c',
            'Secret' => 's3cret-d', 'pass' => 's3cret-e'];
        foreach ($secrets as $name => $value) {
            $this->value($this->field('product', 50, $name), $service->id, $value);
        }

        $this->run544();

        $notes = (string) $service->fresh()->notes;
        foreach ($secrets as $name => $value) {
            $this->assertStringNotContainsString($value, $notes, "«{$name}» is a secret");
        }
    }

    /**
     * ADV-544-5, Medium. Scenario: WHMCS has the «Θα ήθελα να εκδοθεί τιμολόγιο» field but the account never saved it
     * (no row: ~180 accounts on myip). Ekdosi's WHMCS path read that as unticked (wantsInvoice() false: a receipt); the
     * import leaves document_type null, which MycsPayload::wantsInvoice reads as null (unknown, the operator decides).
     * Expected: the same answer as before the cut-over: receipt (or none), so MycsPayload says false.
     */
    public function test_adv_544_5_no_row_for_the_invoice_tickbox_reads_as_unticked(): void
    {
        $client = User::factory()->create()->client();
        $afm = $this->field('client', 0, 'ΑΦΜ / Vies Vat No');
        $this->field('client', 0, 'Θα ήθελα να εκδοθεί τιμολόγιο', 'tickbox');
        $this->value($afm, $client->id, '099999999');

        $this->run544();

        $this->assertContains($this->primary($client->id)->document_type, ['receipt', 'none'], 'WHMCS → Ekdosi read it as false');
    }

    /**
     * ADV-544-6, Low. Scenario: a licensing product's custom field («comments»). The import leaves the fields its
     * licensing import keeps as properties, but ImportFromWhmcs::handle runs importCustomFields before importLicensing:
     * no property is there yet, so they are copied into the service's notes as well. Expected: a licensing product's
     * fields never in the notes, whatever the order.
     */
    public function test_adv_544_6_licensing_fields_stay_out_of_notes_in_the_importers_order(): void
    {
        $client = User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $client->id]);
        $this->whmcs->exec("INSERT INTO tblproducts VALUES (60, 'licensing')");
        $this->whmcs->exec("INSERT INTO tblhosting VALUES ({$service->id}, 60)");
        $this->value($this->field('product', 60, 'comments'), $service->id, 'licensed to example');

        $this->run544(); // before WhmcsLicensingImport, as handle() runs them

        $this->assertStringNotContainsString('licensed to example', (string) $service->fresh()->notes);
    }

    /**
     * ADV-544-7, Low. Scenario: WHMCS stores custom field values HTML-escaped (51 of myip's ΔΟΥ, 23 activities hold
     * an entity). The import keeps «Α&#039; Αθηνών» as it is: MyCS's forms, PDFs and mails show the entity, and a
     * save keeps it. Ekdosi decodes them when it creates the customer (WhmcsCustomerCreator), so decoding keeps parity.
     * Expected: the decoded text.
     */
    public function test_adv_544_7_html_entities_are_decoded(): void
    {
        $client = User::factory()->create()->client();
        $this->value($this->field('client', 0, 'ΔΟΥ'), $client->id, 'Α&#039; Αθηνών');
        $this->value($this->field('client', 0, 'Δραστηριότητα επιχείρησης'), $client->id, 'Web &amp; Hosting');

        $this->run544();

        $this->assertSame(["Α' Αθηνών", 'Web & Hosting'], [$this->primary($client->id)->tax_office, $this->primary($client->id)->activity]);
    }

    /**
     * ADV-544-8, Low. Scenario: an account field the map does not know («Πώς μας βρήκατε»). Services' unknown fields go
     * to notes; the account's are dropped without a word. Expected: kept for staff (a note) or at least listed.
     */
    public function test_adv_544_8_an_unknown_account_field_is_not_dropped_silently(): void
    {
        $client = User::factory()->create()->client();
        $this->value($this->field('client', 0, 'Πώς μας βρήκατε'), $client->id, 'From a forum');

        $result = $this->run544();

        $kept = DB::table('client_notes')->where('client_id', $client->id)->pluck('body')->implode("\n") . implode("\n", $result['notes']);
        $this->assertStringContainsString('Πώς μας βρήκατε', $kept);
    }
}
