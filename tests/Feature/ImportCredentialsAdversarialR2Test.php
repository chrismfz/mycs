<?php

namespace Tests\Feature;

use App\Models\Extension;
use App\Models\Gateway;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsCredentialImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review, round 2, of PR #561 (WhmcsCredentialImport). Failing tests = open findings. Invented values;
 * every request is faked and a stray one fails the test.
 */
class ImportCredentialsAdversarialR2Test extends TestCase
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
        foreach ([
            'CREATE TABLE tblservers (id INTEGER PRIMARY KEY, name TEXT, type TEXT, accesshash TEXT)',
            'CREATE TABLE tblregistrars (registrar TEXT, setting TEXT, value TEXT)',
            'CREATE TABLE tblpaymentgateways (gateway TEXT, setting TEXT, value TEXT)',
            'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, registrar TEXT)',
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    private function whmcsSet(string $table, string $name, array $set): void
    {
        foreach ($set as $setting => $plain) {
            $this->whmcs->prepare("INSERT INTO {$table} VALUES (?, ?, ?)")->execute([$name, $setting, "enc:{$plain}"]);
        }
    }

    private function installedGateway(string $extension): int
    {
        return (int) Extension::create(['name' => $extension, 'type' => 'gateway', 'extension' => $extension])->id;
    }

    /** @param  ?callable(Request): mixed  $answer  overrides WHMCS's answer for a request (null: the default «enc:x» => x) */
    private function api(?callable $answer = null): WhmcsApi
    {
        Http::fake([self::URL . '/includes/api.php' => function (Request $request) use ($answer) {
            $custom = $answer === null ? null : $answer($request);

            return $custom ?? Http::response(['result' => 'success', 'password' => htmlspecialchars(substr((string) $request['password2'], 4), ENT_QUOTES)]);
        }]);

        return new WhmcsApi(self::URL, 'id-test', 'secret-test');
    }

    private function run2(WhmcsApi $api): array
    {
        $ids = ['stripe' => $this->installedGateway('Stripe'), 'paypal_ppcpv' => $this->installedGateway('PayPal')];

        return (new WhmcsCredentialImport($this->whmcs, $api, fn (string $module) => $ids[$module] ?? null))->run();
    }

    /**
     * ADV2-561-1 (Medium): PayPal's «sandbox or live» flag is the one value whose failed read is taken as an answer: a
     * flag that could not be read (a hiccup of WHMCS on that single call; the registrar path skips in the same case)
     * is «live», and the LIVE app's client id and secret are stored with test_mode 0 and said to be «the live app's»,
     * although WHMCS runs the sandbox app. The gateway is then enabled by staff on the word of that note.
     */
    public function test_paypal_whose_sandbox_flag_could_not_be_read_is_not_stored_as_live(): void
    {
        $this->whmcsSet('tblpaymentgateways', 'paypal_ppcpv', [
            'useSandbox' => 'on', 'clientId' => 'LIVEID', 'clientSecret' => 'LIVESECRET', 'sandboxClientId' => 'TESTID', 'sandboxClientSecret' => 'TESTSECRET',
        ]);
        $paypal = null;
        $api = $this->api(fn (Request $request) => str_ends_with((string) $request['password2'], ':on')
            ? Http::response(['result' => 'error', 'message' => 'Decryption failed'], 500) : null);

        $result = $this->run2($api);

        $paypal = Gateway::query()->where('extension', 'PayPal')->first();
        $stored = $paypal->settings->pluck('value', 'key')->all();
        $this->assertArrayNotHasKey('client_id', $stored, 'live ids stored for a WHMCS that runs the sandbox; notes: ' . implode(' | ', $result['notes']));
    }
}
