<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsServicePasswordImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of #547 (the live services' passwords through WHMCS's DecryptPassword), without the author's
 * context. Each test is a defect found: it fails until fixed. Invented data only; no real WHMCS is called.
 */
class ImportServicePasswordsAdversarialTest extends TestCase
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

    private function service(string $status = Service::STATUS_ACTIVE, string $encrypted = 'enc-x'): Service
    {
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => $status]);
        $this->whmcs->prepare('INSERT INTO tblhosting VALUES (?, ?)')->execute([$service->id, $encrypted]);

        return $service;
    }

    /** @param  callable(Request): mixed  $answer */
    private function import(callable $answer): array
    {
        Http::fake([self::URL . '/includes/api.php' => $answer]);

        return (new WhmcsServicePasswordImport($this->whmcs, new WhmcsApi(self::URL, 'id-test', 'secret-test')))->run();
    }

    /**
     * ADV-547-1 (Medium). WHMCS keeps what staff typed HTML-escaped (its input sanitising: the notes of #546 needed
     * html_entity_decode for the same reason), and hands a module the password decoded. DecryptPassword returns the
     * stored form as is: a password with & < > " ' comes back as «Pa&amp;ss&lt;…».
     * Expected: MyCS keeps the password the panel accepts, decoded as WHMCS decodes it for its modules; today the
     * escaped text is stored, so the welcome mail and the customer's page show a password that does not log in.
     */
    public function test_adv_547_1_an_html_escaped_password_is_stored_decoded(): void
    {
        $service = $this->service();

        $this->import(fn () => Http::response(['result' => 'success', 'password' => 'Pa&amp;ss&lt;w&gt;&quot;rd&#039;9']));

        $this->assertSame('Pa&ss<w>"rd\'9', $service->fresh()->password);
    }

    /**
     * ADV-547-2 (Low). A service with no password in WHMCS (cleared by staff, a product without a panel) still has an
     * encrypted value in tblhosting.password, and DecryptPassword answers success with an empty password.
     * Expected: nothing to import, not a failure: today it is reported as an exception and listed as «could not be
     * decrypted by WHMCS … set them on their pages», which sends staff after passwords that do not exist.
     */
    public function test_adv_547_2_an_empty_password_is_not_a_decryption_failure(): void
    {
        Exceptions::fake();
        $service = $this->service();

        $result = $this->import(fn () => Http::response(['result' => 'success', 'password' => '']));

        $this->assertNull($service->fresh()->password);
        Exceptions::assertNothingReported();
        $this->assertStringNotContainsString("#{$service->id}", implode("\n", $result['notes']), 'not listed as one WHMCS could not decrypt');
    }

    /**
     * ADV-547-3 (Low). The API credentials' role without DecryptPassword (docs/importer.md lists it as a prerequisite)
     * or wrong credentials: WHMCS answers the same error to every call. The step goes on asking for each of the 356/715
     * live services (each with its retries and a report() line), and the note says only «could not be decrypted», never
     * why.
     * Expected: an error that is the API's, not the service's (authentication, permissions), stops the step at the
     * first answer and the note says so.
     */
    public function test_adv_547_3_a_permission_error_stops_at_the_first_call_and_says_why(): void
    {
        foreach (range(1, 5) as $i) {
            $this->service(encrypted: "enc-{$i}");
        }

        $result = $this->import(fn () => Http::response(['result' => 'error', 'message' => 'Invalid Permissions: API action "decryptpassword" is not allowed']));

        Http::assertSentCount(1);
        $this->assertStringContainsString('Invalid Permissions', implode("\n", $result['notes']));
    }
}
