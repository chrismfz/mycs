<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\DomainRegistrarConnection;
use App\Models\Extension;
use App\Models\Gateway;
use App\Models\Server;
use App\Models\Setting;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsCredentialImport;
use App\Services\Import\WhmcsSecondFactor;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The logins WHMCS keeps for servers, registrars and gateways (WhmcsCredentialImport): read through its API
 * (DecryptPassword), stored encrypted, nothing switched on, nothing asked of Stripe, PayPal or a registrar. Invented
 * values; no real WHMCS is called (every request is faked, a stray one fails the test).
 */
class ImportCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://whmcs.example.test';

    private const TOKEN = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345';

    private PDO $whmcs;

    /** @var list<string> the ciphertexts WHMCS was asked to decrypt */
    private array $asked = [];

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

    private function server(int $whmcsId, string $accesshash, string $type = 'cpanel'): Server
    {
        $this->whmcs->prepare('INSERT INTO tblservers VALUES (?, ?, ?, ?)')->execute([$whmcsId, "Srv{$whmcsId}", $type, $accesshash]);
        $server = new Server;
        $server->forceFill(['whmcs_id' => $whmcsId, 'name' => "Srv{$whmcsId}", 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true])->save();

        return $server;
    }

    /** @param  array<string, string>  $set  setting => the plain value (stored as «enc:value») */
    private function registrar(string $module, array $set): void
    {
        foreach ($set as $setting => $plain) {
            $this->whmcs->prepare('INSERT INTO tblregistrars VALUES (?, ?, ?)')->execute([$module, $setting, "enc:{$plain}"]);
        }
    }

    private function gatewaySet(string $gateway, array $set): void
    {
        foreach ($set as $setting => $plain) {
            $this->whmcs->prepare('INSERT INTO tblpaymentgateways VALUES (?, ?, ?)')->execute([$gateway, $setting, "enc:{$plain}"]);
        }
    }

    private function connection(string $registrar): DomainRegistrarConnection
    {
        return DomainRegistrarConnection::query()->create(['registrar' => $registrar, 'label' => "{$registrar} (from WHMCS)", 'is_active' => false, 'mode' => 'off']);
    }

    /** As the app holds a gateway: the Gateway model, whose Settings are the ones its admin and ExtensionHelper read (ADV-561-1). */
    private function gateway(string $extension): Gateway
    {
        $id = Extension::query()->create(['name' => $extension, 'type' => 'gateway', 'extension' => $extension])->id;

        return Gateway::query()->findOrFail($id);
    }

    /** WHMCS's DecryptPassword as the test fakes it: «enc:x» => x (HTML-escaped as WHMCS keeps it when asked). */
    private function api(): WhmcsApi
    {
        Http::fake([self::URL . '/includes/api.php' => function (Request $request) {
            $this->asked[] = (string) $request['password2'];

            return Http::response(['result' => 'success', 'password' => htmlspecialchars(substr((string) $request['password2'], 4), ENT_QUOTES)]);
        }]);

        return new WhmcsApi(self::URL, 'id-test', 'secret-test');
    }

    /** @return array{counts: array<string, int>, notes: list<string>} */
    private function import(?WhmcsApi $api = null): array
    {
        $gateways = ['stripe' => 'Stripe', 'paypal_ppcpv' => 'PayPal'];

        return (new WhmcsCredentialImport($this->whmcs, $api, fn (string $module) => isset($gateways[$module])
            ? (int) (DB::table('extensions')->where('type', 'gateway')->where('extension', $gateways[$module])->value('id') ?? $this->gateway($gateways[$module])->id)
            : null))->run();
    }

    private function raw(object $model, string $key): ?string
    {
        return DB::table('settings')->where(['settingable_type' => $model->getMorphClass(), 'settingable_id' => $model->id, 'key' => $key])->value('value');
    }

    // ---- servers ----------------------------------------------------------------------------------------------------

    public function test_a_cpanel_servers_api_token_comes_encrypted_without_the_api(): void
    {
        $server = $this->server(1, '  ' . substr(self::TOKEN, 0, 16) . "\n" . substr(self::TOKEN, 16) . ' ');

        $result = $this->import(null);

        $this->assertSame(1, $result['counts']['imported']);
        $this->assertNotSame(self::TOKEN, $this->raw($server, 'apikey'), 'not stored in clear');
        $this->assertSame(self::TOKEN, Crypt::decryptString($this->raw($server, 'apikey')));
        $this->assertTrue((bool) Setting::query()->where('key', 'apikey')->value('encrypted'));
        Http::assertNothingSent();
        $this->assertStringNotContainsString(self::TOKEN, implode("\n", $result['notes']));
    }

    public function test_a_server_that_logs_in_with_a_password_gets_no_key_and_staff_are_told_to_make_a_token(): void
    {
        $server = $this->server(1, '');

        $result = $this->import(null);

        $this->assertNull($this->raw($server, 'apikey'));
        $this->assertSame(1, $result['counts']['skipped']);
        $this->assertStringContainsString('Manage API Tokens', implode("\n", $result['notes']));
    }

    public function test_an_access_hash_that_is_not_a_token_is_not_stored(): void
    {
        $server = $this->server(1, str_repeat('ab12', 40)); // the old multi-line access hash

        $result = $this->import(null);

        $this->assertNull($this->raw($server, 'apikey'));
        $this->assertStringContainsString('not the shape of an API token', implode("\n", $result['notes']));
    }

    public function test_a_key_staff_set_here_stays(): void
    {
        $server = $this->server(1, self::TOKEN);
        $server->settings()->create(['key' => 'apikey', 'value' => 'STAFFTOKEN0123456789ABCDEF', 'type' => 'string', 'encrypted' => true]);

        $this->import(null);

        $this->assertSame('STAFFTOKEN0123456789ABCDEF', Crypt::decryptString($this->raw($server, 'apikey')));
    }

    public function test_a_server_of_another_module_is_said_not_carried(): void
    {
        $this->server(1, self::TOKEN, 'centovacast');

        $result = $this->import(null);

        $this->assertSame(0, $result['counts']['imported']);
        $this->assertStringContainsString('enter it by hand', implode("\n", $result['notes']));
    }

    // ---- registrars -------------------------------------------------------------------------------------------------

    public function test_a_registrars_login_comes_with_the_password_encrypted_and_the_connection_stays_off(): void
    {
        $connection = $this->connection('openprovider');
        $this->registrar('openprovider', ['Username' => 'op&user', 'Password' => 'P&amp;ss<1>', 'TestMode' => '']);

        $result = $this->import($this->api());

        $credentials = $connection->fresh()->credentials();
        $this->assertSame('op&user', $credentials['username']);
        $this->assertSame('P&amp;ss<1>', $credentials['password'], 'WHMCS hands the text decoded once: &amp;amp; => &amp;');
        $this->assertNotSame('P&amp;ss<1>', $this->raw($connection, 'password'));
        $this->assertFalse((bool) Setting::query()->where(['key' => 'username', 'settingable_id' => $connection->id])->value('encrypted'));
        $this->assertTrue((bool) Setting::query()->where(['key' => 'password', 'settingable_id' => $connection->id])->value('encrypted'));
        $this->assertSame(['mode' => 'production', 'is_active' => false], $connection->fresh()->only('mode', 'is_active'), 'its account\'s mode, and not active');
        $this->assertFalse($connection->fresh()->isUsable(), 'nothing calls it');
        $this->assertSame(1, $result['counts']['imported']);
        $this->assertStringContainsString('live account', implode("\n", $result['notes']));
    }

    public function test_a_test_account_is_stored_in_sandbox_mode_and_said(): void
    {
        $connection = $this->connection('openprovider');
        $this->registrar('openprovider', ['Username' => 'u', 'Password' => 'p', 'test_mode' => 'on']);

        $result = $this->import($this->api());

        $this->assertSame('sandbox', $connection->fresh()->mode);
        $this->assertStringContainsString('TEST account', implode("\n", $result['notes']));
    }

    /** ADV-561-2: when it cannot be told whether the login is the live or the test account, it is not stored. */
    public function test_a_login_whose_mode_could_not_be_read_is_not_stored(): void
    {
        $connection = $this->connection('openprovider');
        $this->registrar('openprovider', ['Username' => 'u', 'Password' => 'p', 'TestMode' => 'x']);
        Http::fake([self::URL . '/includes/api.php' => function (Request $request) {
            return str_ends_with((string) $request['password2'], ':x')
                ? Http::response(['result' => 'error', 'message' => 'Decryption failed'], 500)
                : Http::response(['result' => 'success', 'password' => 'v']);
        }]);
        Exceptions::fake();

        $result = $this->import(new WhmcsApi(self::URL, 'id-test', 'secret-test'));

        $this->assertSame([], $connection->fresh()->credentials());
        $this->assertSame('off', $connection->fresh()->mode);
        $this->assertSame(1, $result['counts']['skipped']);
    }

    /** ADV-561-5: both WHMCS modules have a login: the one most domains name is the account in use. */
    public function test_the_module_most_domains_use_is_the_account_taken(): void
    {
        $connection = $this->connection('cnic');
        $this->registrar('cnic', ['Username' => 'cnic-user', 'Password' => 'cnic-pass']);
        $this->registrar('ispapi', ['Username' => 'isp-user', 'Password' => 'isp-pass']);
        foreach ([1 => 'ispapi', 2 => 'ispapi', 3 => 'cnic'] as $id => $module) {
            $this->whmcs->prepare('INSERT INTO tbldomains VALUES (?, ?)')->execute([$id, $module]);
        }

        $result = $this->import($this->api());

        $this->assertSame('isp-user', $connection->fresh()->credentials()['username']);
        $this->assertStringContainsString('most domains use', implode("\n", $result['notes']));
    }

    public function test_cnic_takes_the_settings_of_cnic_or_of_ispapi_its_older_name(): void
    {
        $connection = $this->connection('cnic');
        $this->registrar('ispapi', ['Username' => 'isp-user', 'Password' => 'isp-pass']);

        $this->import($this->api());

        $this->assertSame('isp-user', $connection->fresh()->credentials()['username']);
    }

    public function test_when_whmcs_has_both_cnic_takes_its_own_and_says_so(): void
    {
        $connection = $this->connection('cnic');
        $this->registrar('cnic', ['Username' => 'cnic-user', 'Password' => 'cnic-pass']);
        $this->registrar('ispapi', ['Username' => 'isp-user', 'Password' => 'isp-pass']);

        $result = $this->import($this->api());

        $this->assertSame('cnic-user', $connection->fresh()->credentials()['username']);
        $this->assertStringContainsString('both', implode("\n", $result['notes']));
    }

    public function test_a_login_staff_set_here_stays_and_nothing_is_asked(): void
    {
        $connection = $this->connection('cnic');
        $connection->storeCredentials(['username' => 'mine', 'password' => 'mine-pass'], ['password']);
        $this->registrar('cnic', ['Username' => 'other', 'Password' => 'other-pass']);

        $this->import($this->api());

        $this->assertSame('mine', $connection->fresh()->credentials()['username']);
        Http::assertNothingSent();
    }

    public function test_without_the_api_nothing_encrypted_is_imported_and_it_says_why(): void
    {
        $this->connection('cnic');
        $this->registrar('cnic', ['Username' => 'u', 'Password' => 'p']);
        $this->gateway('Stripe');
        $this->gatewaySet('stripe', ['secretKey' => 'sk_live_abc', 'publishableKey' => 'pk_live_abc']);

        $result = $this->import(null);

        $this->assertSame(0, $result['counts']['imported']);
        $this->assertStringContainsString("WHMCS's API is needed", implode("\n", $result['notes']));
        $this->assertSame([], DB::table('settings')->where('key', 'like', 'stripe_%')->pluck('key')->all());
    }

    public function test_a_registrar_without_a_connection_here_is_said_not_carried(): void
    {
        $this->registrar('enom', ['Username' => 'u', 'Password' => 'p']);

        $result = $this->import($this->api());

        $this->assertStringContainsString('«enom» in WHMCS has no connection here', implode("\n", $result['notes']));
        Http::assertNothingSent();
    }

    // ---- gateways ---------------------------------------------------------------------------------------------------

    public function test_stripes_keys_come_and_stripe_is_not_asked_nor_enabled(): void
    {
        $stripe = $this->gateway('Stripe');
        $this->gatewaySet('stripe', ['secretKey' => 'rk_live_AbC123', 'publishableKey' => 'pk_live_XyZ789', 'webhookEndpointSecret' => 'whsec_old']);

        $result = $this->import($this->api());

        $this->assertSame('rk_live_AbC123', Crypt::decryptString($this->raw($stripe, 'stripe_secret_key')));
        $this->assertSame('pk_live_XyZ789', $this->raw($stripe, 'stripe_publishable_key'));
        $this->assertNull($this->raw($stripe, 'stripe_webhook_secret'), 'the old endpoint\'s secret is not this one\'s: MyCS makes its own');
        $this->assertFalse((bool) $stripe->fresh()->enabled);
        $this->assertSame(1, $result['counts']['imported']);
        // Only WHMCS's API was called (a stray request to Stripe would have failed the test).
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::URL));
    }

    public function test_stripe_keys_of_two_modes_are_not_stored(): void
    {
        $stripe = $this->gateway('Stripe');
        $this->gatewaySet('stripe', ['secretKey' => 'sk_live_AbC123', 'publishableKey' => 'pk_test_XyZ789']);

        $result = $this->import($this->api());

        $this->assertNull($this->raw($stripe, 'stripe_secret_key'));
        $this->assertSame(1, $result['counts']['skipped']);
    }

    public function test_paypals_client_id_and_secret_come_and_its_webhook_id_does_not(): void
    {
        $paypal = $this->gateway('PayPal');
        $this->gatewaySet('paypal_ppcpv', ['clientId' => 'LIVEID', 'clientSecret' => 'LIVESECRET', 'sandboxClientId' => 'SBID', 'sandboxClientSecret' => 'SBSECRET',
            'useSandbox' => '', 'webhook-identifier' => 'WH-OLD']);

        $this->import($this->api());

        $this->assertSame('LIVEID', $this->raw($paypal, 'client_id'));
        $this->assertSame('LIVESECRET', Crypt::decryptString($this->raw($paypal, 'client_secret')));
        $this->assertNull($this->raw($paypal, 'webhook_id'));
        $this->assertFalse((bool) $paypal->fresh()->enabled);
        $this->assertFalse((bool) Setting::query()->where(['settingable_id' => $paypal->id, 'key' => 'test_mode'])->first()->value);
    }

    public function test_paypal_in_sandbox_takes_the_sandbox_app(): void
    {
        $paypal = $this->gateway('PayPal');
        $this->gatewaySet('paypal_ppcpv', ['clientId' => 'LIVEID', 'clientSecret' => 'LIVESECRET', 'sandboxClientId' => 'SBID', 'sandboxClientSecret' => 'SBSECRET', 'useSandbox' => 'on']);

        $result = $this->import($this->api());

        $this->assertSame('SBID', $this->raw($paypal, 'client_id'));
        $this->assertTrue((bool) Setting::query()->where(['settingable_id' => $paypal->id, 'key' => 'test_mode'])->first()->value);
        $this->assertStringContainsString('SANDBOX', implode("\n", $result['notes']));
    }

    public function test_the_classic_paypal_api_is_said_not_carried(): void
    {
        $this->gatewaySet('paypal', ['apiusername' => 'u', 'apipassword' => 'p', 'apisignature' => 's']);

        $result = $this->import($this->api());

        $this->assertStringContainsString('classic API', implode("\n", $result['notes']));
        Http::assertNothingSent();
    }

    // ---- what must never happen -------------------------------------------------------------------------------------

    public function test_no_value_reaches_a_note_nor_a_reported_error(): void
    {
        Exceptions::fake();
        $this->connection('openprovider');
        $this->registrar('openprovider', ['Username' => 'uniq-user-77', 'Password' => 'uniq-pass-77']);
        $this->gateway('Stripe');
        $this->gatewaySet('stripe', ['secretKey' => 'sk_live_uniqSECRET77', 'publishableKey' => 'pk_live_uniqPUB77']);
        $this->server(1, self::TOKEN);

        $result = $this->import($this->api());

        $text = json_encode($result['notes']);
        foreach (['uniq-user-77', 'uniq-pass-77', 'uniqSECRET77', 'uniqPUB77', self::TOKEN] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        Exceptions::assertNothingReported();
    }

    public function test_a_refusal_by_whmcs_stops_at_the_first_call_and_says_why(): void
    {
        $this->connection('openprovider');
        $this->registrar('openprovider', ['Username' => 'u', 'Password' => 'p']);
        $this->gateway('Stripe');
        $this->gatewaySet('stripe', ['secretKey' => 'sk_live_a', 'publishableKey' => 'pk_live_a']);
        Http::fake([self::URL . '/includes/api.php' => Http::response(['result' => 'error', 'message' => 'Invalid Permissions: API action "decryptpassword" is not allowed'])]);

        $result = $this->import(new WhmcsApi(self::URL, 'id-test', 'secret-test'));

        Http::assertSentCount(1);
        $this->assertSame(0, $result['counts']['imported']);
        $this->assertStringContainsString('Invalid Permissions', implode("\n", $result['notes']));
    }

    public function test_a_rerun_writes_nothing_twice(): void
    {
        $this->gateway('Stripe');
        $this->gatewaySet('stripe', ['secretKey' => 'sk_live_a1', 'publishableKey' => 'pk_live_a1']);
        $this->server(1, self::TOKEN);

        $this->import($this->api());
        $first = DB::table('settings')->count();
        $this->import($this->api());

        $this->assertSame($first, DB::table('settings')->count());
    }

    public function test_a_wrong_key_s_ciphertext_is_never_stored(): void
    {
        $this->connection('openprovider');
        $this->registrar('openprovider', ['Username' => 'u', 'Password' => 'p']);
        Http::fake([self::URL . '/includes/api.php' => Http::response(['result' => 'success', 'password' => "bin\0ary"])]);
        Exceptions::fake();

        $result = $this->import(new WhmcsApi(self::URL, 'id-test', 'secret-test'));

        $this->assertSame(0, $result['counts']['imported']);
        $this->assertSame([], DB::table('settings')->where('key', 'password')->pluck('id')->all());
    }

    /** ADV-561-4: the step is opt-in; without --with-logins a database never gets a live token. */
    public function test_the_command_brings_the_logins_only_when_asked(): void
    {
        $server = $this->server(1, self::TOKEN);
        $run = function (array $input) {
            $command = app(ImportFromWhmcs::class);
            (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
            $arguments = new ArrayInput($input, $command->getDefinition());
            $command->setInput($arguments);
            $output = new BufferedOutput;
            $command->setOutput(new OutputStyle($arguments, $output));
            (new ReflectionMethod($command, 'importCredentials'))->invoke($command);

            return $output->fetch();
        };

        $said = $run(['dbname' => 'whmcs_copy']);
        $this->assertNull($this->raw($server, 'apikey'));
        $this->assertStringContainsString('--with-logins', $said);

        $said = $run(['dbname' => 'whmcs_copy', '--with-logins' => true]);
        $this->assertSame(self::TOKEN, Crypt::decryptString($this->raw($server, 'apikey')));
        $this->assertStringContainsString('now holds live logins', $said);
        $this->assertStringNotContainsString(self::TOKEN, $said);
    }

    /** ADV2-561-1: a switch that says something unrecognised is not guessed live or test. */
    public function test_a_registrar_flag_that_says_something_else_is_not_guessed(): void
    {
        $connection = $this->connection('openprovider');
        $this->registrar('openprovider', ['Username' => 'u', 'Password' => 'p', 'TestMode' => 'maybe']);

        $result = $this->import($this->api());

        $this->assertSame([], $connection->fresh()->credentials());
        $this->assertSame(1, $result['counts']['skipped']);
    }

    // ---- WHMCS's newer scheme: the gateways' settings ---------------------------------------------------------------------

    private const KEY = 'test-cc-encryption-hash';

    /** A value as WHMCS 8 stores a gateway setting: `<iv hex>.<ciphertext hex>` under its cc_encryption_hash. */
    private function sealed(string $plain, string $hash = self::KEY): string
    {
        $iv = random_bytes(16);

        return bin2hex($iv) . '.' . bin2hex((string) openssl_encrypt($plain, 'aes-256-cbc', substr(hash('sha256', $hash), 0, 32), OPENSSL_RAW_DATA, $iv));
    }

    private function sealedGateway(string $gateway, array $set): void
    {
        foreach ($set as $setting => $plain) {
            $this->whmcs->prepare('INSERT INTO tblpaymentgateways VALUES (?, ?, ?)')->execute([$gateway, $setting, $this->sealed($plain)]);
        }
    }

    private function importWithKey(?string $hash, ?WhmcsApi $api = null): array
    {
        $gateways = ['stripe' => 'Stripe', 'paypal_ppcpv' => 'PayPal'];
        $scheme = new WhmcsSecondFactor($hash === null ? null : fn (string $stored) => WhmcsSecondFactor::decryptWith($hash, $stored));

        return (new WhmcsCredentialImport($this->whmcs, $api, fn (string $module) => isset($gateways[$module])
            ? (int) (DB::table('extensions')->where('type', 'gateway')->where('extension', $gateways[$module])->value('id') ?? $this->gateway($gateways[$module])->id)
            : null, $scheme))->run();
    }

    public function test_gateway_settings_in_the_newer_scheme_are_read_with_the_key_and_never_ask_the_api(): void
    {
        $stripe = $this->gateway('Stripe');
        $paypal = $this->gateway('PayPal');
        $this->sealedGateway('stripe', ['secretKey' => 'sk_live_NewScheme1', 'publishableKey' => 'pk_live_NewScheme2']);
        $this->sealedGateway('paypal_ppcpv', ['useSandbox' => '', 'clientId' => 'LIVEID', 'clientSecret' => 'LIVESECRET']);

        $result = $this->importWithKey(self::KEY, null);

        $this->assertSame('sk_live_NewScheme1', Crypt::decryptString($this->raw($stripe, 'stripe_secret_key')));
        $this->assertSame('LIVESECRET', Crypt::decryptString($this->raw($paypal, 'client_secret')));
        $this->assertSame(2, $result['counts']['imported']);
        Http::assertNothingSent();
    }

    public function test_without_the_key_the_newer_scheme_is_not_read_and_the_note_names_it(): void
    {
        $stripe = $this->gateway('Stripe');
        $this->sealedGateway('stripe', ['secretKey' => 'sk_live_a', 'publishableKey' => 'pk_live_a']);

        $result = $this->importWithKey(null, $this->api());

        $this->assertNull($this->raw($stripe, 'stripe_secret_key'));
        $this->assertStringContainsString('WHMCS_CC_ENCRYPTION_HASH', implode("\n", $result['notes']));
        Http::assertNothingSent(); // DecryptPassword is not asked about a value it answers with garbage
    }

    public function test_a_wrong_key_stores_nothing_and_leaks_nothing(): void
    {
        Exceptions::fake();
        $stripe = $this->gateway('Stripe');
        $this->sealedGateway('stripe', ['secretKey' => 'sk_live_Secret99', 'publishableKey' => 'pk_live_Public99']);

        $result = $this->importWithKey('another-hash');

        $this->assertNull($this->raw($stripe, 'stripe_secret_key'));
        $this->assertStringNotContainsString('Secret99', json_encode($result['notes']));
        $this->assertStringContainsString('did not open', implode("\n", $result['notes']));
        Exceptions::assertReported(fn (\RuntimeException $e) => !str_contains($e->getMessage(), 'Secret99'));
    }
}
