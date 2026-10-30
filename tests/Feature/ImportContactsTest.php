<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use App\Services\Import\WhmcsContactImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * WHMCS's contacts as the account's, in MyCS's own fields (owner 2026-10-30); one that is an Ekdosi contact imported
 * already completes it, never a second copy.
 */
class ImportContactsTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblcontacts (id INTEGER PRIMARY KEY, userid INT, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT,
            address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, phonenumber TEXT, tax_id TEXT, subaccount INT,
            domainemails INT, generalemails INT, invoiceemails INT, productemails INT, supportemails INT, affiliateemails INT, created_at TEXT)');
        $this->whmcs->exec('CREATE TABLE mod_ekdosi_contacts (id INTEGER PRIMARY KEY, legacy_contact_id INT, userid INT, company_name TEXT, email TEXT)');
    }

    private function contact(int $id, int $client, array $fields): void
    {
        $row = array_merge(['firstname' => 'Maria', 'lastname' => 'Papa', 'companyname' => '', 'email' => "c{$id}@example.test", 'address1' => 'Odos 1',
            'address2' => '', 'city' => 'Athina', 'state' => '', 'postcode' => '10431', 'country' => 'GR', 'phonenumber' => '+30.2100000000', 'tax_id' => '',
            'subaccount' => 0, 'domainemails' => 0, 'generalemails' => 0, 'invoiceemails' => 0, 'productemails' => 0, 'supportemails' => 0,
            'affiliateemails' => 0, 'created_at' => '2019-04-01 10:00:00'], $fields);
        $this->whmcs->prepare('INSERT INTO tblcontacts VALUES (' . implode(', ', array_fill(0, 22, '?')) . ')')
            ->execute([$id, $client, ...array_values($row)]);
    }

    public function test_the_contacts_come_in_mycs_fields_and_an_ekdosi_one_is_completed_not_copied(): void
    {
        $client = User::factory()->create()->client();
        $ekdosi = $client->contacts()->create(['type' => 'business', 'company_name' => 'Alpha ΙΚΕ', 'tax_id' => '099999999',
            'email' => 'acc@example.test', 'document_type' => 'invoice']);
        $this->contact(1, $client->id, ['firstname' => 'Nikos', 'companyname' => 'Beta &amp; Co', 'invoiceemails' => 1, 'domainemails' => 1, 'tax_id' => 'EL012345678']);
        $this->contact(2, $client->id, ['companyname' => 'Alpha ΙΚΕ', 'email' => 'ACC@example.test', 'phonenumber' => '+30.2109999999']); // the Ekdosi one
        $this->contact(3, 424242, []); // a customer not here

        $result = (new WhmcsContactImport($this->whmcs))->run();

        $beta = Contact::query()->where('company_name', 'Beta & Co')->sole();
        $this->assertSame([$client->id, 'business', 'Nikos', 'Papa', 'EL012345678', 'GR', '10431', ['invoice', 'domain'], '2019-04-01 10:00:00'],
            [$beta->client_id, $beta->type, $beta->first_name, $beta->last_name, $beta->tax_id, $beta->country, $beta->postcode, $beta->email_preferences,
                $beta->created_at->toDateTimeString()]);
        $this->assertSame(1, Contact::query()->where('company_name', 'Alpha ΙΚΕ')->count(), 'the Ekdosi contact is not copied');
        $ekdosi->refresh();
        $this->assertSame(['Maria', '+30.2109999999', '099999999', 'invoice', []], [$ekdosi->first_name, $ekdosi->phone, $ekdosi->tax_id,
            $ekdosi->document_type, $ekdosi->email_preferences], 'only what it lacked; its fiscal details stay');
        $this->assertSame(['contacts' => 1, 'merged' => 1, 'skipped' => 1], $result['counts']);
    }

    public function test_the_ekdosi_one_linked_by_its_legacy_id(): void
    {
        $client = User::factory()->create()->client();
        $ekdosi = $client->contacts()->create(['type' => 'business', 'company_name' => 'Gamma ΟΕ', 'email' => 'gamma@example.test']);
        $this->whmcs->prepare("INSERT INTO mod_ekdosi_contacts VALUES (1, 7, ?, 'Gamma ΟΕ', 'gamma@example.test')")->execute([$client->id]);
        $this->contact(7, $client->id, ['companyname' => 'Gamma O.E.', 'email' => 'other@example.test', 'city' => 'Patra']);

        (new WhmcsContactImport($this->whmcs))->run();

        $this->assertSame(1, DB::table('contacts')->where('client_id', $client->id)->whereNull('primary_of_client_id')->count());
        $this->assertSame('Patra', $ekdosi->fresh()->city);
    }

    public function test_a_whmcs_without_contacts_imports_nothing(): void
    {
        $result = (new WhmcsContactImport(new PDO('sqlite::memory:')))->run();

        $this->assertSame(['contacts' => 0, 'merged' => 0, 'skipped' => 0], $result['counts']);
    }
}
