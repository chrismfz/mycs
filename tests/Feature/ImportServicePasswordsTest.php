<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsServicePasswordImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * The live services' passwords through WHMCS's API (DecryptPassword: its key is not in the database), as MyCS keeps them
 * (encrypted under APP_KEY); an ended service's never asked; none without the API; a failure listed by id, never a
 * password anywhere.
 */
class ImportServicePasswordsTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, password TEXT)');
    }

    public function test_the_live_services_passwords_come_decrypted_by_whmcs(): void
    {
        $client = User::factory()->create()->client();
        $live = Service::factory()->create(['client_id' => $client->id, 'status' => Service::STATUS_ACTIVE]);
        $suspended = Service::factory()->create(['client_id' => $client->id, 'status' => Service::STATUS_SUSPENDED]);
        $ended = Service::factory()->create(['client_id' => $client->id, 'status' => Service::STATUS_TERMINATED]);
        $failing = Service::factory()->create(['client_id' => $client->id, 'status' => Service::STATUS_ACTIVE]);
        $this->whmcs->prepare('INSERT INTO tblhosting VALUES (?, ?), (?, ?), (?, ?), (?, ?)')
            ->execute([$live->id, 'enc-1', $suspended->id, 'enc-2', $ended->id, 'enc-3', $failing->id, 'enc-4']);
        $asked = [];
        Http::fake(['https://whmcs.example.test/includes/api.php' => function (Request $request) use (&$asked) {
            $asked[] = $request['password2'];

            return $request['password2'] === 'enc-4' ? Http::response(['result' => 'error', 'message' => 'bad'], 200)
                : Http::response(['result' => 'success', 'password' => 'Plain-' . $request['password2']]);
        }]);

        $result = (new WhmcsServicePasswordImport($this->whmcs, new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1')))->run();

        $this->assertSame(['Plain-enc-1', 'Plain-enc-2'], [$live->fresh()->password, $suspended->fresh()->password], 'decrypted, kept encrypted as MyCS keeps it');
        $this->assertNotSame('Plain-enc-1', DB::table('services')->where('id', $live->id)->value('password'), 'never plain in the database');
        $this->assertNull($ended->fresh()->password);
        $this->assertNotContains('enc-3', $asked, 'an ended service\'s is asked of no one');
        $this->assertNull($failing->fresh()->password);
        $this->assertSame(['services' => 3, 'imported' => 2, 'skipped' => 1], $result['counts']);
        $notes = implode("\n", $result['notes']);
        $this->assertStringContainsString("service #{$failing->id}", $notes);
        $this->assertStringNotContainsString('Plain-', $notes);
        $this->assertStringNotContainsString('enc-', $notes);
    }

    public function test_without_the_api_nothing_is_asked_and_it_says_so(): void
    {
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => Service::STATUS_ACTIVE]);
        $this->whmcs->prepare('INSERT INTO tblhosting VALUES (?, ?)')->execute([$service->id, 'enc-1']);

        $result = (new WhmcsServicePasswordImport($this->whmcs, null))->run();

        $this->assertNull($service->fresh()->password);
        $this->assertStringContainsString("WHMCS's API is needed", implode("\n", $result['notes']));
        Http::assertNothingSent();
    }

    /** A WHMCS that does not answer: 5 failures in a row, the rest not asked (code review M2), never a password logged. */
    public function test_a_whmcs_that_does_not_answer_stops_the_step(): void
    {
        $client = User::factory()->create()->client();
        foreach (range(1, 8) as $n) {
            $service = Service::factory()->create(['client_id' => $client->id, 'status' => Service::STATUS_ACTIVE]);
            $this->whmcs->prepare('INSERT INTO tblhosting VALUES (?, ?)')->execute([$service->id, "enc-{$n}"]);
        }
        Http::fake(['https://whmcs.example.test/includes/api.php' => fn () => throw new ConnectionException('timed out')]);

        $result = (new WhmcsServicePasswordImport($this->whmcs, new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1')))->run();

        $this->assertSame(['services' => 8, 'imported' => 0, 'skipped' => 8], $result['counts']);
        $this->assertStringContainsString('5 failed in a row: WHMCS is not answering, the other 3 not asked', implode("\n", $result['notes']));
    }
}
