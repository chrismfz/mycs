<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsServicePasswordImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * Round-2 review of #547 (e828b6e8), without the author's context. Each test is a defect found: it fails until fixed.
 * Invented data only; no real WHMCS is called and no real password is decrypted.
 */
class ImportServicePasswordsRound2Test extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://whmcs.example.test';

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, password TEXT)');
    }

    private function service(): Service
    {
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => Service::STATUS_ACTIVE]);
        $this->whmcs->prepare('INSERT INTO tblhosting VALUES (?, ?)')->execute([$service->id, 'enc-' . $service->id]);

        return $service;
    }

    /**
     * ADV-547-R2-1 (Low). REFUSED is matched against every Throwable of the loop, not only WhmcsApi's answer: an error
     * of MyCS's own database while storing one password («Access denied for user …», e.g. a reconnect after «server has
     * gone away», or a trigger/privilege error) is reported as «WHMCS refused the API's call», staff are sent to check
     * the API credentials, and the rest of the step is abandoned. Its message is the QueryException's, so the note
     * printed to the console carries the SQL with its bindings: the start of the stored ciphertext (eyJpdiI6…).
     * Expected: only the API call's own refusal stops the step; a MyCS error is that service's failure (ids only), and
     * nothing of the SQL or its bindings reaches a note.
     */
    public function test_r2_547_1_a_mycs_database_error_is_not_whmcs_refusing_nor_printed(): void
    {
        $first = $this->service();
        $second = $this->service();
        DB::unprepared("CREATE TRIGGER deny_first BEFORE UPDATE OF password ON services WHEN NEW.id = {$first->id}
            BEGIN SELECT RAISE(ABORT, 'Access denied for user'); END");
        Http::fake([self::URL . '/includes/api.php' => Http::response(['result' => 'success', 'password' => 'Invented-9'])]);

        $result = (new WhmcsServicePasswordImport($this->whmcs, new WhmcsApi(self::URL, 'id-test', 'secret-test')))->run();
        $notes = implode("\n", $result['notes']);

        $this->assertStringNotContainsString('WHMCS refused', $notes);
        $this->assertStringNotContainsString('eyJ', $notes, 'the stored ciphertext is printed');
        $this->assertStringNotContainsString('SQL', $notes);
        $this->assertSame('Invented-9', $second->fresh()->password, 'the rest of the step was abandoned');
        $this->assertSame(['services' => 2, 'imported' => 1, 'skipped' => 1], $result['counts']);
    }
}
