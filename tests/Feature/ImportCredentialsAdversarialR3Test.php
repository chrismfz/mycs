<?php

namespace Tests\Feature;

use App\Models\DomainRegistrarConnection;
use App\Models\Extension;
use App\Models\Gateway;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsCredentialImport;
use App\Services\Import\WhmcsSecondFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * Round 3 on #561 (commit 31df5415, the newer scheme read with WHMCS_CC_ENCRYPTION_HASH). Invented values only.
 */
class ImportCredentialsAdversarialR3Test extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://whmcs.example.test';

    private const KEY = 'invented-cc-hash-r3';

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Exceptions::fake();
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

    private function sealed(string $plain, string $hash = self::KEY): string
    {
        $iv = random_bytes(16);

        return bin2hex($iv) . '.' . bin2hex((string) openssl_encrypt($plain, 'aes-256-cbc', substr(hash('sha256', $hash), 0, 32), OPENSSL_RAW_DATA, $iv));
    }

    private function gatewayRow(string $gateway, string $setting, string $stored): void
    {
        $this->whmcs->prepare('INSERT INTO tblpaymentgateways VALUES (?, ?, ?)')->execute([$gateway, $setting, $stored]);
    }

    private function registrarRow(string $module, string $setting, string $stored): void
    {
        $this->whmcs->prepare('INSERT INTO tblregistrars VALUES (?, ?, ?)')->execute([$module, $setting, $stored]);
    }

    private function gateway(string $extension): Gateway
    {
        $id = Extension::query()->create(['name' => $extension, 'type' => 'gateway', 'extension' => $extension])->id;

        return Gateway::query()->findOrFail($id);
    }

    private function run3(?string $hash, ?WhmcsApi $api): array
    {
        $scheme = new WhmcsSecondFactor($hash === null ? null : fn (string $stored) => WhmcsSecondFactor::decryptWith($hash, $stored));
        $stripe = $this->gateway('Stripe');

        return (new WhmcsCredentialImport($this->whmcs, $api, fn (string $m) => $m === 'stripe' ? $stripe->id : null, $scheme))->run();
    }

    /** An API whose DecryptPassword answers «success» with no password (a value it could not turn into text). */
    private function apiAnsweringNothing(): WhmcsApi
    {
        Http::fake([self::URL . '/includes/api.php' => fn (Request $r) => Http::response(['result' => 'success'])]);

        return new WhmcsApi(self::URL, 'id-test', 'secret-test');
    }

    /**
     * ADV3-561-1 (Medium): the missing-key closing note replaces every «was not read: enter it by hand» note, the registrars'
     * too, though a registrar is read through the API and its failure has nothing to do with the key. The registrar is
     * skipped (counted) and nothing says which one or why: the only note left blames the gateways' key.
     */
    public function test_a_missing_key_does_not_hide_why_a_registrar_was_not_read(): void
    {
        DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => false, 'mode' => 'off']);
        $this->registrarRow('openprovider', 'Username', 'enc:opuser');
        $this->registrarRow('openprovider', 'Password', 'enc:oppass');
        $this->gatewayRow('stripe', 'secretKey', $this->sealed('sk_live_R3one'));
        $this->gatewayRow('stripe', 'publishableKey', $this->sealed('pk_live_R3one'));

        $result = $this->run3(null, $this->apiAnsweringNothing());

        $this->assertSame(0, $result['counts']['imported']);
        $this->assertStringContainsString('openprovider', implode("\n", $result['notes']), 'the registrar that was skipped is named');
    }

    /**
     * ADV3-561-2 (Medium): with the key set and no API, the gateways are read; but the registrars' need for the API
     * (needsApi) drops the gateway's own «not a secret and a publishable key of the same mode» note, and the closing note
     * says the gateways were not imported for want of the API. A live secret with a test publishable key is skipped in silence.
     */
    public function test_a_gateway_read_with_the_key_that_fails_its_shape_check_still_says_so_when_the_api_is_missing(): void
    {
        DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => false, 'mode' => 'off']);
        $this->registrarRow('openprovider', 'Username', 'enc:opuser');
        $this->registrarRow('openprovider', 'Password', 'enc:oppass');
        $this->gatewayRow('stripe', 'secretKey', $this->sealed('sk_live_R3two'));
        $this->gatewayRow('stripe', 'publishableKey', $this->sealed('pk_test_R3two'));

        $result = $this->run3(self::KEY, null);

        $this->assertSame(0, DB::table('settings')->where('key', 'stripe_secret_key')->count());
        $this->assertStringContainsString('stripe', strtolower(implode("\n", $result['notes'])), 'the mismatching Stripe keys are named');
        $this->assertStringNotContainsString('registrars and gateways not imported', implode("\n", $result['notes']), 'the gateways were read with the key, not the API');
    }
}
