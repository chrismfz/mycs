<?php

namespace Tests\Feature;

use App\Admin\Resources\DomainRegistrarConnectionResource\Pages\EditDomainRegistrarConnection;
use App\Helpers\ExtensionHelper;
use App\Models\DomainRegistrarConnection;
use App\Models\Extension;
use App\Models\Gateway;
use App\Models\Role;
use App\Models\Staff;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsCredentialImport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of PR #561 (WhmcsCredentialImport). Failing tests = open findings. Invented values; every request
 * is faked and a stray one fails the test.
 */
class ImportCredentialsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://whmcs.example.test';

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        Http::preventStrayRequests();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE tblservers (id INTEGER PRIMARY KEY, name TEXT, type TEXT, accesshash TEXT)',
            'CREATE TABLE tblregistrars (registrar TEXT, setting TEXT, value TEXT)',
            'CREATE TABLE tblpaymentgateways (gateway TEXT, setting TEXT, value TEXT)',
        ] as $sql) {
            $this->whmcs->exec($sql);
        }
    }

    private function whmcsSet(string $table, string $column, string $name, array $set): void
    {
        foreach ($set as $setting => $plain) {
            $this->whmcs->prepare("INSERT INTO {$table} VALUES (?, ?, ?)")->execute([$name, $setting, "enc:{$plain}"]);
        }
    }

    /** WHMCS's DecryptPassword as the test fakes it: «enc:x» => x. */
    private function api(): WhmcsApi
    {
        Http::fake([self::URL . '/includes/api.php' => fn (Request $request) => Http::response(['result' => 'success',
            'password' => htmlspecialchars(substr((string) $request['password2'], 4), ENT_QUOTES)])]);

        return new WhmcsApi(self::URL, 'id-test', 'secret-test');
    }

    /** The gateway the way the importer installs it (ImportFromWhmcs::installedGateway): a base Extension row. */
    private function installedGateway(string $extension): int
    {
        return (int) Extension::create(['name' => $extension, 'type' => 'gateway', 'extension' => $extension])->id;
    }

    /**
     * ADV-561-1: a gateway's keys are read through the Gateway model (ExtensionHelper::bootable, GatewayBalances, the
     * admin's gateway page), whose settings are kept under its own morph type. The import writes them through the base
     * Extension model, under another type, so the gateway has none: «imported» is said, and staff find empty fields.
     */
    public function test_the_gateway_keys_are_where_the_gateway_reads_them(): void
    {
        $stripe = $this->installedGateway('Stripe');
        $paypal = $this->installedGateway('PayPal');
        $this->whmcsSet('tblpaymentgateways', 'gateway', 'stripe', ['secretKey' => 'sk_live_AbC123', 'publishableKey' => 'pk_live_XyZ789']);
        $this->whmcsSet('tblpaymentgateways', 'gateway', 'paypal_ppcpv', ['clientId' => 'LIVEID', 'clientSecret' => 'LIVESECRET', 'useSandbox' => '']);

        $result = (new WhmcsCredentialImport($this->whmcs, $this->api(), fn (string $module) => ['stripe' => $stripe, 'paypal_ppcpv' => $paypal][$module] ?? null))->run();

        $this->assertSame(2, $result['counts']['imported'], 'the import says both came');
        $seen = Gateway::query()->find($stripe)->settings->pluck('value', 'key')->all();
        $this->assertArrayHasKey('stripe_secret_key', $seen, 'Stripe: the key is not on the gateway the app reads');
        $this->assertSame('pk_live_XyZ789', $seen['stripe_publishable_key'] ?? null);
        $config = ExtensionHelper::getExtension('gateway', 'Stripe', Gateway::query()->find($stripe)->settings);
        $this->assertSame('sk_live_AbC123', $config->config('stripe_secret_key'));
        $this->assertSame('LIVEID', Gateway::query()->find($paypal)->settings->pluck('value', 'key')->get('client_id'));
        $this->assertSame(0, DB::table('settings')->where('settingable_type', Extension::class)->where('settingable_id', $stripe)->count(), 'nothing is left under the base Extension type');
    }

    /**
     * ADV-561-2: the imported login is the WHMCS LIVE account, in a connection whose mode is «off». Off counts as the test
     * system for credentials (sandbox = mode !== production), and «Test Connection» is offered in any mode: one click sends
     * the live Openprovider password in clear over HTTP to its sandbox host (the SA-6 rule the edit form keeps when the
     * mode changes, but the import and this button bypass).
     */
    public function test_test_connection_on_an_imported_live_login_does_not_send_its_password_over_plain_http(): void
    {
        $connection = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'Openprovider (from WHMCS)', 'is_active' => false, 'mode' => 'off']);
        $this->whmcsSet('tblregistrars', 'registrar', 'openprovider', ['Username' => 'live-reseller', 'Password' => 'Live-Reseller-Pa55', 'TestMode' => '']);
        (new WhmcsCredentialImport($this->whmcs, $this->api(), fn () => null))->run();
        $this->assertSame('Live-Reseller-Pa55', $connection->fresh()->credentials()['password']);

        Http::fake(['*' => Http::response(['data' => ['token' => 'tok']])]);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);
        $this->livewireAs($staff)->test(EditDomainRegistrarConnection::class, ['record' => $connection->getRouteKey()])->callAction('test');

        $plain = Http::recorded()->filter(fn (array $pair) => str_starts_with($pair[0]->url(), 'http://')
            && str_contains($pair[0]->body(), 'Live-Reseller-Pa55'));
        $this->assertCount(0, $plain, 'the live password went to ' . $plain->map(fn (array $pair) => $pair[0]->url())->implode(', '));
    }
}
