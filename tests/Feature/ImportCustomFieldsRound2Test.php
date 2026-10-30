<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsCustomFieldImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

/**
 * Round-2 review of #544 (after d8afc3d5), without the author's context. Each test is a defect found: it fails until
 * fixed. Invented data only.
 */
class ImportCustomFieldsRound2Test extends TestCase
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

    /**
     * R2-544-1, Medium (secrets; none on the copies today). Scenario: a provisioning module's API key field named the
     * way modules name their system fields, «api_key» or «api-key», with no «|label». The fix's SECRET pattern has
     * «api ?key»: a space or nothing between the words, never «_» or «-», so the key goes into the service's notes in
     * clear, where every staff member with the service reads it. Expected: never a secret in the notes (listed).
     */
    public function test_r2_544_1_an_api_key_written_with_underscore_or_dash_stays_out_of_notes(): void
    {
        $client = User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $client->id]);
        $this->whmcs->exec("INSERT INTO tblproducts VALUES (70, '')");
        $this->whmcs->exec("INSERT INTO tblhosting VALUES ({$service->id}, 70)");
        $secrets = ['api_key' => 'k3y-under', 'api-key' => 'k3y-dash'];
        foreach ($secrets as $name => $value) {
            $this->value($this->field('product', 70, $name), $service->id, $value);
        }

        (new WhmcsCustomFieldImport($this->whmcs))->run();

        $notes = (string) $service->fresh()->notes;
        foreach ($secrets as $name => $value) {
            $this->assertStringNotContainsString($value, $notes, "«{$name}» is a secret");
        }
    }
}
