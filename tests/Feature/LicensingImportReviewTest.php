<?php

namespace Tests\Feature;

use App\Http\Controllers\ZlicController;
use App\Models\Client;
use App\Models\License;
use App\Models\LicenseAccessLog;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Services\Licensing\MycsLicenseRepository;
use App\Services\Licensing\WhmcsLicensingImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Nixpal\Licensing\Tests\WhmcsSchema;
use Nixpal\Licensing\WhmcsLicenseRepository;
use Nixpal\Licensing\Zlic;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Adversarial review of PR #131 (WhmcsLicensingImport). Each test is a finding; it fails on the PR as submitted.
 * Setup mirrors LicensingImportTest (WhmcsSchema + services imported with the same ids).
 */
class LicensingImportReviewTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = 1791540000;

    private const TOKEN = '1791540000abcdef0123456789abcdef01234567';

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        WhmcsSchema::create($this->whmcs);
        foreach (['servertype', 'configoption1', 'configoption2', 'configoption3'] as $column) {
            $this->whmcs->exec("ALTER TABLE tblproducts ADD COLUMN {$column} TEXT NOT NULL DEFAULT ''");
        }
        $this->whmcs->exec("UPDATE tblproducts SET servertype = 'licensing', configoption1 = '16', configoption3 = 'on'");
        $this->whmcs->exec("INSERT INTO tblcustomfieldsvalues (fieldid, relid, value) VALUES (1, 1003, 'host one & two')");

        $client = Client::factory()->create(['id' => 1]);
        $client->contacts()->make(['first_name' => 'Test', 'last_name' => 'Client', 'company_name' => 'Test Company', 'email' => 'client@example.com'])
            ->forceFill(['primary_of_client_id' => $client->id])->save();
        $plans = [];
        foreach ([8 => ['DEVNODE', 'free'], 5 => ['Zonecloud Agent', 'recurring'], 4 => ['Zonecloud Web Controller', 'recurring']] as $id => [$name, $type]) {
            Product::factory()->create(['id' => $id, 'name' => $name]);
            $plan = Plan::factory()->create(['priceable_id' => $id, 'priceable_type' => Product::class, 'name' => $type, 'type' => $type, 'billing_unit' => 'month', 'billing_period' => 1]);
            if ($type !== 'free') {
                Price::factory()->create(['plan_id' => $plan->id, 'price' => 0, 'currency_code' => 'USD']);
            }
            $plans[$id] = $plan->id;
        }
        foreach ([[1001, 8, '2023-10-23', null, 'active'], [1002, 5, '2023-07-25', '2025-05-25', 'cancelled'],
            [1003, 4, '2024-02-01', '2026-09-01', 'suspended']] as [$id, $productId, $regdate, $due, $status]) {
            Service::factory()->create(['id' => $id, 'client_id' => 1, 'product_id' => $productId, 'plan_id' => $plans[$productId], 'status' => $status,
                'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'expires_at' => $due, 'domain' => 'x']);
            Service::query()->whereKey($id)->update(['created_at' => $regdate . ' 00:00:00']);
        }
    }

    private function import(int $logDays = 0): array
    {
        return (new WhmcsLicensingImport($this->whmcs))->run($logDays);
    }

    private function assertParity(string $key, string $ip = '198.51.100.7'): void
    {
        $clock = fn () => self::NOW;
        $params = ['license' => $key, 'license_PSK' => WhmcsSchema::SECRET];
        $whmcs = (new Zlic(new WhmcsLicenseRepository($this->whmcs, true, $clock), fn () => 'rdns.example.net', $clock, fn () => self::TOKEN, ZlicController::DIRECTORY))->handle($params, $ip);
        $mycs = (new Zlic(new MycsLicenseRepository, fn () => 'rdns.example.net', $clock, fn () => self::TOKEN, ZlicController::DIRECTORY))->handle($params, $ip);
        $this->assertSame($whmcs->json(), $mycs->json());
    }

    /** R-1: text() trims the bindings; WHMCS prints them as stored (in the answer and in the localkey). */
    public function test_bindings_with_edge_whitespace_answer_as_whmcs(): void
    {
        $this->whmcs->exec("UPDATE mod_licensing SET validip = '198.51.100.7 ', validdomain = 'host.example.com,www.host.example.com\n' WHERE id = 1");
        $this->import();

        $this->assertParity('ZCNode-00000000000000aa');
    }

    /** @return array<string, array{string}> */
    public static function oddFieldNames(): array
    {
        return ['comma in the name' => ['hosts, extra'], 'trailing space' => ['notes ']];
    }

    /** R-2: fieldNames() drops names with a comma and MyCS trims them; WHMCS reports them as they are. */
    #[DataProvider('oddFieldNames')]
    public function test_custom_field_names_are_reported_as_on_whmcs(string $name): void
    {
        $this->whmcs->exec("INSERT INTO tblcustomfields (id, type, relid, fieldname, sortorder) VALUES (9, 'product', 4, '{$name}', 5)");
        $this->whmcs->exec("INSERT INTO tblcustomfieldsvalues (fieldid, relid, value) VALUES (9, 1003, 'v')");
        $this->import();

        $this->assertParity('ZCWeb-00000000000000cc');
    }

    /** R-3: a value emptied in WHMCS after a first run stays in MyCS (empty values are skipped, never cleared). */
    public function test_a_rerun_clears_a_custom_field_value_emptied_on_whmcs(): void
    {
        $this->import();
        $this->whmcs->exec("UPDATE tblcustomfieldsvalues SET value = '' WHERE fieldid = 1 AND relid = 1003");
        $this->import();

        $this->assertParity('ZCWeb-00000000000000cc');
    }

    /** R-4: rows are matched by key; a key edited on WHMCS collides with services.service_id unique (SQLite: exception; MariaDB: ON DUPLICATE KEY updates the row by service_id and keeps the OLD key). */
    public function test_a_rerun_after_a_key_edit_on_whmcs_follows_the_license_by_whmcs_id(): void
    {
        $this->import();
        $this->whmcs->exec("UPDATE mod_licensing SET licensekey = 'ZCNode-0000000000000eee' WHERE id = 1");

        $this->import();

        $this->assertSame('ZCNode-0000000000000eee', License::query()->where('whmcs_id', 1)->value('license_key'));
        $this->assertParity('ZCNode-0000000000000eee');
    }

    /** R-5: an empty tblhosting.billingcycle is printed empty by WHMCS; MyCS skips the property and falls back to the plan. */
    public function test_an_empty_whmcs_billing_cycle_answers_as_whmcs(): void
    {
        $this->whmcs->exec("UPDATE tblhosting SET billingcycle = '' WHERE id = 1001");
        $this->import();

        $this->assertParity('ZCNode-00000000000000aa');
    }

    /** R-6: the new once-a-day Cache::add makes a cache outage change an Expired answer into «Remote Check Failed». */
    public function test_a_cache_outage_does_not_change_the_expired_answer(): void
    {
        $this->import();
        Cache::partialMock()->shouldReceive('add')->andThrow(new RuntimeException('cache down'));

        $answer = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post('/zlic.php', ['license' => 'ZCAgent-00000000000000bb', 'license_PSK' => WhmcsSchema::SECRET])->json('status');

        $this->assertSame('Expired', $answer);
    }

    /** R-7: the re-run delete has no lower bound and no origin: MyCS's own rows (DNS switch-over overlap) go too. */
    public function test_a_rerun_keeps_the_rows_mycs_logged_itself(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->whmcs->exec("INSERT INTO mod_licensinglog (licenseid, domain, ip, path, message, datetime) VALUES
            (2, '', '198.51.100.7', '/x', 'License Expired', '2026-10-19 08:00:00')");
        $this->import(90);
        $license = License::query()->where('whmcs_id', 2)->sole();
        LicenseAccessLog::query()->create(['license_id' => $license->id, 'domain' => '', 'ip' => '203.0.113.9', 'directory' => '/x',
            'message' => 'IP Address Invalid', 'created_at' => '2026-10-19 07:00:00']);

        $this->import(90);

        $this->assertTrue(LicenseAccessLog::query()->where('message', 'IP Address Invalid')->exists());
        Carbon::setTestNow();
    }

    /** R-7b: a re-run with fewer --log-days deletes the older history it does not bring back. */
    public function test_a_rerun_with_fewer_log_days_keeps_the_older_history(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->whmcs->exec("INSERT INTO mod_licensinglog (licenseid, domain, ip, path, message, datetime) VALUES
            (2, '', '198.51.100.7', '/x', 'License Expired', '2026-10-19 08:00:00'),
            (2, '', '198.51.100.7', '/x', 'License Expired', '2026-08-01 08:00:00')");
        $this->import(90);

        $this->import(7);

        $this->assertSame(2, LicenseAccessLog::query()->count());
        Carbon::setTestNow();
    }
}
