<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Http\Controllers\ZlicController;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseAccessLog;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Services\Licensing\MycsLicenseRepository;
use App\Services\Licensing\WhmcsLicensingImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nixpal\Licensing\Tests\WhmcsSchema;
use Nixpal\Licensing\WhmcsLicenseRepository;
use Nixpal\Licensing\Zlic;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * L3 (docs/licensing.md): the licensing import tool. WhmcsSchema's licenses, products and custom fields go through
 * WhmcsLicensingImport onto services imported as app:import-from-whmcs brings them (same ids); zlic.php then answers
 * every request byte-for-byte as WHMCS does. The PSK is stored encrypted; a re-run changes nothing; the log is brought
 * for its last days.
 */
class LicensingImportTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = 1791540000; // 2026-10-09 09:20:00 UTC

    private const TOKEN = '1791540000abcdef0123456789abcdef01234567';

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        WhmcsSchema::create($this->whmcs);
        // The module columns WhmcsSchema's tblproducts leaves out (docs/history/blesta-modules/licensing.md §2).
        foreach (['servertype', 'configoption1', 'configoption2', 'configoption3'] as $column) {
            $this->whmcs->exec("ALTER TABLE tblproducts ADD COLUMN {$column} TEXT NOT NULL DEFAULT ''");
        }
        $this->whmcs->exec("UPDATE tblproducts SET servertype = 'licensing', configoption1 = '16', configoption3 = 'on'");
        $this->whmcs->exec("UPDATE tblproducts SET configoption2 = CASE id WHEN 8 THEN 'ZCNode-' WHEN 5 THEN 'ZCAgent-' ELSE 'ZCWeb-' END");
        $this->whmcs->exec("INSERT INTO tblcustomfieldsvalues (fieldid, relid, value) VALUES (1, 1003, 'host one & two')");
        $this->importedServices();
    }

    /** WhmcsSchema's client, products and services as app:import-from-whmcs brings them: no license data. */
    private function importedServices(): void
    {
        $client = Client::factory()->create(['id' => 1]);
        $client->contacts()->make(['first_name' => 'Test', 'last_name' => 'Client', 'company_name' => 'Test Company', 'email' => 'client@example.com'])
            ->forceFill(['primary_of_client_id' => $client->id])->save();
        foreach ([8 => ['DEVNODE', 'free', 'Free'], 5 => ['Zonecloud Agent', 'recurring', 'Monthly'], 4 => ['Zonecloud Web Controller', 'recurring', 'Monthly']] as $id => [$name, $type, $planName]) {
            $product = Product::factory()->create(['id' => $id, 'name' => $name]);
            $plan = Plan::factory()->create(['priceable_id' => $id, 'priceable_type' => Product::class, 'name' => $planName, 'type' => $type, 'billing_unit' => 'month', 'billing_period' => 1]);
            if ($type !== 'free') {
                Price::factory()->create(['plan_id' => $plan->id, 'price' => 0, 'currency_code' => 'USD']);
            }
            $plans[$id] = $plan->id;
        }
        foreach ([[1001, 8, '2023-10-23', null, 'active', 'ZCNode-00000000000000aa'], [1002, 5, '2023-07-25', '2025-05-25', 'cancelled', 'ZCAgent-00000000000000bb'],
            [1003, 4, '2024-02-01', '2026-09-01', 'suspended', 'ZCWeb-00000000000000cc']] as [$id, $productId, $regdate, $due, $status, $domain]) {
            Service::factory()->create(['id' => $id, 'client_id' => 1, 'product_id' => $productId, 'plan_id' => $plans[$productId], 'status' => $status,
                'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'expires_at' => $due, 'domain' => $domain]);
            Service::query()->whereKey($id)->update(['created_at' => $regdate . ' 00:00:00']);
        }
    }

    private function import(int $logDays = 0): array
    {
        return (new WhmcsLicensingImport($this->whmcs))->run($logDays);
    }

    private function zlic(bool $mycs): Zlic
    {
        $clock = fn () => self::NOW;

        return new Zlic($mycs ? new MycsLicenseRepository : new WhmcsLicenseRepository($this->whmcs, false, $clock),
            fn ($ip) => 'rdns.example.net', $clock, fn () => self::TOKEN, ZlicController::DIRECTORY);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function requests(): array
    {
        return LicensingParityTest::requests() + [
            'suspended, another IP' => [['license' => 'ZCWeb-00000000000000cc', 'license_PSK' => WhmcsSchema::SECRET], '203.0.113.9'],
        ];
    }

    /** @param array<string, string> $params */
    #[DataProvider('requests')]
    public function test_imported_licenses_answer_as_whmcs(array $params, string $ip): void
    {
        $this->import();

        $whmcs = $this->zlic(false)->handle($params, $ip);
        $mycs = $this->zlic(true)->handle($params, $ip);

        $this->assertSame($whmcs->httpStatus, $mycs->httpStatus);
        $this->assertSame($whmcs->json(), $mycs->json());
    }

    public function test_an_imported_reissued_license_binds_to_the_next_caller_as_on_whmcs(): void
    {
        $this->whmcs->exec("UPDATE mod_licensing SET status = 'Reissued', validip = '', validdomain = '', validdirectory = '' WHERE id = 1");
        $this->import();
        $params = ['license' => 'ZCNode-00000000000000aa', 'license_PSK' => WhmcsSchema::SECRET];

        $this->assertSame($this->zlic(false)->handle($params, '192.0.2.44')->json(), $this->zlic(true)->handle($params, '192.0.2.44')->json());
        $row = $this->whmcs->query('SELECT status, validip FROM mod_licensing WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $license = License::query()->where('whmcs_id', 1)->sole();
        $this->assertSame([$row['status'], $row['validip']], [$license->status, $license->valid_ip]);
    }

    public function test_the_products_get_their_module_settings_and_the_psk_only_encrypted(): void
    {
        $counts = $this->import();

        $this->assertSame(['products' => 3, 'licenses' => 3, 'skipped' => 0, 'fields' => 1, 'logs' => 0], $counts);
        $server = Server::query()->where('extension', 'NixpalLicensing')->sole();
        $product = Product::query()->find(5);
        $this->assertSame($server->id, $product->server_id);
        $settings = ExtensionHelper::settingsToArray($product->settings);
        $this->assertSame(['ZCAgent-', '16', true, false, 'comments', WhmcsSchema::SECRET],
            array_map(fn ($key) => $settings[$key] ?? null, ['key_prefix', 'key_length', 'allow_reissue', 'allow_ip_conflict', 'custom_fields', 'secret_key']));
        $stored = DB::table('settings')->where('settingable_id', 5)->where('key', 'secret_key')->sole();
        $this->assertTrue((bool) $stored->encrypted);
        $this->assertStringNotContainsString(WhmcsSchema::SECRET, (string) $stored->value);
        $this->assertSame('', ExtensionHelper::settingsToArray(Product::query()->find(8)->settings)['custom_fields'], 'DEVNODE reports none');
        $this->assertSame($server->id, Service::query()->find(1002)->server_id);
    }

    public function test_the_command_never_prints_the_psk(): void
    {
        config(['audit.console' => true]); // off in the console by default: on, so an audit could catch it
        $this->assertStringNotContainsString(WhmcsSchema::SECRET, json_encode($this->import()));
        $this->assertFalse(DB::table('audits')->where('new_values', 'like', '%' . WhmcsSchema::SECRET . '%')->exists());
    }

    public function test_a_rerun_changes_nothing_and_keeps_a_psk_staff_typed_over_an_empty_one(): void
    {
        $this->import();
        $before = [License::query()->orderBy('id')->get()->toArray(), DB::table('properties')->count(), DB::table('settings')->count()];
        $this->whmcs->exec("UPDATE tblproducts SET configoption8 = '' WHERE id = 5");

        $this->import();

        $this->assertEquals($before[0], License::query()->orderBy('id')->get()->toArray());
        $this->assertSame([$before[1], $before[2]], [DB::table('properties')->count(), DB::table('settings')->count()]);
        $this->assertSame(WhmcsSchema::SECRET, ExtensionHelper::settingsToArray(Product::query()->find(5)->settings)['secret_key']);
    }

    /**
     * A rerun writes nothing, not even updated_at: the second run a minute later leaves every row it reaches as it was
     * (once flaky on CI when the two runs fell on different seconds).
     */
    public function test_a_rerun_a_minute_later_leaves_every_row_untouched(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->import();
        $rows = fn () => [License::query()->orderBy('id')->get()->toArray(), DB::table('properties')->orderBy('id')->get()->all(),
            DB::table('settings')->orderBy('id')->get()->all(), DB::table('products')->orderBy('id')->get()->all(),
            DB::table('services')->orderBy('id')->get()->all(), Server::query()->orderBy('id')->get()->toArray()];
        $before = $rows();

        Carbon::setTestNow('2026-10-20 10:01:00');
        $this->import();

        $this->assertEquals($before, $rows());
        Carbon::setTestNow();
    }

    public function test_a_license_without_an_imported_service_is_skipped_and_an_empty_domain_takes_the_key(): void
    {
        $this->whmcs->exec("INSERT INTO mod_licensing VALUES (4, 9999, 'ZCAgent-00000000000000dd', '', '', '', 0, 'Active', '2026-01-01 00:00:00')");
        Service::query()->whereKey(1002)->update(['domain' => null]);

        $counts = $this->import();

        $this->assertSame(1, $counts['skipped']);
        $this->assertNull(License::query()->where('license_key', 'ZCAgent-00000000000000dd')->first());
        $this->assertSame('ZCAgent-00000000000000bb', Service::query()->find(1002)->domain);
        $this->assertSame('Monthly', DB::table('properties')->where('model_id', 1002)->where('key', 'whmcs_billingcycle')->value('value'));
    }

    public function test_the_log_is_brought_for_its_last_days_and_a_rerun_does_not_double_it(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->whmcs->exec("INSERT INTO mod_licensinglog (licenseid, domain, ip, path, message, datetime) VALUES
            (2, '', '198.51.100.7', '/x', 'License Expired', '2026-10-19 08:00:00'),
            (3, '', '198.51.100.7', '/x', 'License Suspended', '2026-10-18 08:00:00'),
            (2, '', '198.51.100.7', '/x', 'License Expired', '2026-06-01 08:00:00')");

        $this->assertSame(2, $this->import(90)['logs']);
        $this->import(90);

        $this->assertSame(['License Suspended', 'License Expired'], LicenseAccessLog::query()->orderBy('created_at')->pluck('message')->all());
        $this->assertSame(License::query()->where('whmcs_id', 2)->value('id'), LicenseAccessLog::query()->where('message', 'License Expired')->value('license_id'));
        Carbon::setTestNow();
    }

    public function test_dead_agents_are_logged_once_a_day_and_still_counted(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->import();
        $params = ['license' => 'ZCAgent-00000000000000bb', 'license_PSK' => WhmcsSchema::SECRET];

        foreach (range(1, 3) as $ignored) {
            $answer = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->post('/zlic.php', $params)->json('status');
            $this->assertSame('Expired', $answer);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post('/zlic.php', $params);

        $license = License::query()->where('whmcs_id', 2)->sole();
        $this->assertSame(2, LicenseAccessLog::query()->where('license_id', $license->id)->count(), 'once per address');
        $this->assertSame(4, (int) DB::table('license_check_days')->where('license_id', $license->id)->value('failed'));
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->post('/zlic.php', $params);
        $this->assertSame(3, LicenseAccessLog::query()->where('license_id', $license->id)->count(), 'the next day again');
        Carbon::setTestNow();
    }
}
