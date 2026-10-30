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
 * WHMCS's custom fields into MyCS's own fields (owner 2026-10-30): the account's fiscal details (myip kept them there,
 * not in tblclients), its «γκρινιάρης» and notes; a service's IP, Centova's fields; never a password; the rest in the
 * service's notes for staff.
 */
class ImportCustomFieldsTest extends TestCase
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

    public function test_the_accounts_fiscal_details_go_to_its_primary_contact(): void
    {
        $a = User::factory()->create()->client();
        $b = User::factory()->create()->client();
        $c = User::factory()->create()->client();
        $afm = $this->field('client', 0, 'ΑΦΜ / Vies Vat No');
        $doy = $this->field('client', 0, 'ΔΟΥ');
        $activity = $this->field('client', 0, 'Δραστηριότητα επιχείρησης');
        $invoice = $this->field('client', 0, 'Θα ήθελα να εκδοθεί τιμολόγιο', 'tickbox');
        $nag = $this->field('client', 0, 'Γκρινιάρης', 'tickbox');
        $notes = $this->field('client', 0, 'Σημειώσεις', 'textarea');
        foreach ([[$afm, '099999999'], [$doy, 'Α΄ Αθηνών'], [$activity, 'Web design'], [$invoice, 'on'], [$nag, 'on'], [$notes, 'Pays late']] as [$field, $value]) {
            $this->value($field, $a->id, $value);
        }
        $this->value($invoice, $b->id, ''); // chosen, unticked: a receipt
        DB::table('contacts')->where('primary_of_client_id', $c->id)->update(['tax_id' => '011111111']);
        $this->value($afm, $c->id, '022222222'); // tblclients' own stays

        $result = (new WhmcsCustomFieldImport($this->whmcs))->run();

        $primary = fn ($client) => Contact::query()->where('primary_of_client_id', $client->id)->sole();
        $this->assertSame(['099999999', 'Α΄ Αθηνών', 'Web design', 'invoice'],
            [$primary($a)->tax_id, $primary($a)->tax_office, $primary($a)->activity, $primary($a)->document_type]);
        $this->assertTrue((bool) $a->fresh()->immediate_invoicing);
        $this->assertStringContainsString('Pays late', (string) DB::table('client_notes')->where('client_id', $a->id)->value('body'));
        $this->assertSame('receipt', $primary($b)->document_type);
        $this->assertFalse((bool) $b->fresh()->immediate_invoicing);
        $this->assertSame('011111111', $primary($c)->tax_id, 'a value the account has stays');
        $this->assertSame(1, $result['counts']['accounts'], 'only the first got fiscal details; a receipt alone is not counted');
    }

    public function test_a_services_ip_centova_fields_and_the_rest(): void
    {
        $client = User::factory()->create()->client();
        [$radio, $vps, $other] = [Service::factory()->create(['client_id' => $client->id]), Service::factory()->create(['client_id' => $client->id, 'dedicated_ip' => null]),
            Service::factory()->create(['client_id' => $client->id, 'dedicated_ip' => null])];
        $this->whmcs->exec("INSERT INTO tblproducts VALUES (148, 'centovacast'), (253, 'pvewhmcs'), (454, '')");
        $this->whmcs->exec("INSERT INTO tblhosting VALUES ({$radio->id}, 148), ({$vps->id}, 253), ({$other->id}, 454)");
        $this->whmcs->exec("INSERT INTO tblhostingaddons VALUES (7, {$other->id})");
        foreach (['username' => 'radio1', 'port' => '8000', 'servertype' => 'IceCast', 'sourcepassword' => 'secret-1', 'transferlimit' => '1000'] as $name => $value) {
            $this->value($this->field('product', 148, $name), $radio->id, $value);
        }
        $this->value($this->field('product', 253, 'os|Operating System'), $vps->id, 'Debian 12');
        $this->value($this->field('product', 454, 'IP'), $other->id, '192.0.2.10');
        $this->value($this->field('addon', 26, 'Dedicated IP'), 7, '192.0.2.11'); // an addon's: its service's, which has one now

        $result = (new WhmcsCustomFieldImport($this->whmcs))->run();

        $properties = DB::table('properties')->where('model_type', Service::class)->where('model_id', $radio->id)->pluck('value', 'key')->all();
        $this->assertEquals(['centova_port' => '8000', 'centova_servertype' => 'IceCast', 'centova_username' => 'radio1'],
            array_intersect_key($properties, array_flip(['centova_port', 'centova_servertype', 'centova_username'])));
        $this->assertArrayNotHasKey('centova_sourcepassword', $properties);
        $this->assertStringNotContainsString('secret-1', (string) $radio->fresh()->notes, 'never a password in notes');
        $this->assertStringContainsString('Operating System: Debian 12', (string) $vps->fresh()->notes);
        $this->assertSame('192.0.2.10', $other->fresh()->dedicated_ip);
        $this->assertStringContainsString('Dedicated IP: 192.0.2.11', (string) $other->fresh()->notes, 'a second address: for staff');
        $this->assertStringContainsString('may be a secret: not imported', implode("\n", $result['notes']));
    }

    public function test_a_whmcs_without_custom_fields_imports_nothing(): void
    {
        $result = (new WhmcsCustomFieldImport(new PDO('sqlite::memory:')))->run();

        $this->assertStringContainsString('no tblcustomfields', implode("\n", $result['notes']));
    }

    /** A foreign EU VAT number in the ΑΦΜ field is the VIES one too (code review 3 of #544); a Greek one only the ΑΦΜ. */
    public function test_a_foreign_vies_number_is_the_contacts_vat_number_too(): void
    {
        [$de, $gr] = [User::factory()->create()->client(), User::factory()->create()->client()];
        $afm = $this->field('client', 0, 'ΑΦΜ / Vies Vat No');
        $this->value($afm, $de->id, 'DE123456789');
        $this->value($afm, $gr->id, 'EL099999999');

        (new WhmcsCustomFieldImport($this->whmcs))->run();

        $contact = fn ($client) => Contact::query()->where('primary_of_client_id', $client->id)->sole();
        $this->assertSame(['DE123456789', 'DE123456789'], [$contact($de)->tax_id, $contact($de)->vat_number]);
        $this->assertSame(['EL099999999', null], [$contact($gr)->tax_id, $contact($gr)->vat_number]);
    }
}
