<?php

namespace Tests\Feature;

use App\Http\Controllers\ZlicController;
use App\Models\Client;
use App\Models\License;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Property;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Licensing\MycsLicenseRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nixpal\Licensing\Tests\WhmcsSchema;
use Nixpal\Licensing\WhmcsLicenseRepository;
use Nixpal\Licensing\Zlic;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * zlic.php on MyCS answers byte-for-byte as on WHMCS (CLAUDE.md contract): the same licenses, once in WHMCS's tables
 * (packages/nixpal-licensing's WhmcsSchema, the anonymised fixtures) and once in MyCS's, through the same Zlic with a
 * fixed clock, token and reverse DNS: every answer, md5hash and localkey included, is identical, and so is what each
 * writes back (bindings, status).
 */
class LicensingParityTest extends TestCase
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
        $this->mycs();
    }

    /** WhmcsSchema's rows, as MyCS keeps them (the importer's job, L3). */
    private function mycs(): void
    {
        $client = Client::factory()->create();
        $client->contacts()->make(['first_name' => 'Test', 'last_name' => 'Client', 'company_name' => 'Test Company', 'email' => 'client@example.com'])
            ->forceFill(['primary_of_client_id' => $client->id])->save();

        $products = [];
        foreach ([8 => ['DEVNODE', true, ''], 5 => ['Zonecloud Agent', false, 'comments'], 4 => ['Zonecloud Web Controller', false, 'comments']] as $id => [$name, $ipConflict, $fields]) {
            $product = Product::factory()->create(['id' => $id, 'name' => $name]);
            foreach (['secret_key' => [WhmcsSchema::SECRET, true], 'allow_ip_conflict' => [$ipConflict ? '1' : '0', false], 'custom_fields' => [$fields, false]] as $key => [$value, $encrypted]) {
                (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $encrypted, 'type' => $key === 'allow_ip_conflict' ? 'boolean' : 'string',
                    'settingable_id' => $product->id, 'settingable_type' => $product->getMorphClass()])->save();
            }
            $products[$id] = $product;
        }
        $plan = fn (Product $product, string $type) => tap(Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class,
            'name' => 'Plan', 'type' => $type, 'billing_unit' => 'month', 'billing_period' => 1]), fn ($plan) => $type === 'free' ? null
            : Price::factory()->create(['plan_id' => $plan->id, 'price' => 0, 'currency_code' => 'USD']));

        $bind = ['valid_domain' => 'host.example.com,www.host.example.com', 'valid_ip' => '198.51.100.7', 'valid_directory' => '/home/clnixpal/public_html'];
        foreach ([
            [1001, 8, 'free', '2023-10-23', null, 'ZCNode-00000000000000aa', 3, 'Active', '2023-11-04 12:30:57', 'active'],
            [1002, 5, 'recurring', '2023-07-25', '2025-05-25', 'ZCAgent-00000000000000bb', 0, 'Expired', '2026-10-08 18:54:04', 'cancelled'],
            [1003, 4, 'recurring', '2024-02-01', '2026-09-01', 'ZCWeb-00000000000000cc', 0, 'Suspended', '2026-10-08 18:18:09', 'suspended'],
        ] as [$id, $productId, $type, $created, $expires, $key, $reissues, $status, $access, $serviceStatus]) {
            $service = Service::factory()->create(['id' => $id, 'client_id' => $client->id, 'product_id' => $productId,
                'plan_id' => $plan($products[$productId], $type)->id, 'status' => $serviceStatus, 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1,
                'expires_at' => $expires]);
            Service::query()->whereKey($id)->update(['created_at' => $created . ' 10:00:00']);
            License::query()->create($bind + ['service_id' => $service->id, 'license_key' => $key, 'reissues' => $reissues, 'status' => $status, 'last_access' => $access]);
        }
    }

    private function zlic(bool $mycs): Zlic
    {
        $clock = fn () => self::NOW;

        return new Zlic(
            $mycs ? new MycsLicenseRepository : new WhmcsLicenseRepository($this->whmcs, false, $clock),
            fn ($ip) => 'rdns.example.net',
            $clock,
            fn () => self::TOKEN,
            ZlicController::DIRECTORY,
        );
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function requests(): array
    {
        $psk = WhmcsSchema::SECRET;

        return [
            'active, its IP' => [['license' => 'ZCNode-00000000000000aa', 'license_PSK' => $psk], '198.51.100.7'],
            'active, another IP (IP conflict allowed)' => [['license' => 'ZCNode-00000000000000aa', 'license_PSK' => $psk], '203.0.113.9'],
            'active, wrong PSK' => [['license' => 'ZCNode-00000000000000aa', 'license_PSK' => 'wrong'], '198.51.100.7'],
            'expired' => [['license' => 'ZCAgent-00000000000000bb', 'license_PSK' => $psk], '198.51.100.7'],
            'expired, wrong PSK' => [['license' => 'ZCAgent-00000000000000bb', 'license_PSK' => 'wrong'], '198.51.100.7'],
            'suspended (custom fields)' => [['license' => 'ZCWeb-00000000000000cc', 'license_PSK' => $psk], '198.51.100.7'],
            'unknown key' => [['license' => 'ZCAgent-ffffffffffffffff', 'license_PSK' => $psk], '198.51.100.7'],
            'missing PSK' => [['license' => 'ZCNode-00000000000000aa'], '198.51.100.7'],
        ];
    }

    /** @param array<string, string> $params */
    #[DataProvider('requests')]
    public function test_mycs_answers_as_whmcs(array $params, string $ip): void
    {
        $whmcs = $this->zlic(false)->handle($params, $ip);
        $mycs = $this->zlic(true)->handle($params, $ip);

        $this->assertSame($whmcs->httpStatus, $mycs->httpStatus);
        $this->assertSame($whmcs->json(), $mycs->json());
    }

    public function test_an_active_license_from_another_ip_is_refused_on_both(): void
    {
        // Zonecloud Web Controller allows no IP conflict: make it Active to see the IP check.
        $this->whmcs->exec("UPDATE mod_licensing SET status = 'Active' WHERE id = 3");
        License::query()->where('license_key', 'ZCWeb-00000000000000cc')->update(['status' => 'Active']);
        Service::query()->whereKey(1003)->update(['status' => 'active']);
        $params = ['license' => 'ZCWeb-00000000000000cc', 'license_PSK' => WhmcsSchema::SECRET];

        $this->assertSame($this->zlic(false)->handle($params, '203.0.113.9')->json(), $this->zlic(true)->handle($params, '203.0.113.9')->json());
        $this->assertSame($this->zlic(false)->handle($params, '198.51.100.7')->json(), $this->zlic(true)->handle($params, '198.51.100.7')->json());
    }

    public function test_a_reissued_license_binds_to_the_next_caller_on_both(): void
    {
        $this->whmcs->exec("UPDATE mod_licensing SET status = 'Reissued', validip = '', validdomain = '', validdirectory = '' WHERE id = 1");
        License::query()->where('license_key', 'ZCNode-00000000000000aa')->update(['status' => 'Reissued', 'valid_ip' => null, 'valid_domain' => null, 'valid_directory' => null]);
        $params = ['license' => 'ZCNode-00000000000000aa', 'license_PSK' => WhmcsSchema::SECRET];

        $this->assertSame($this->zlic(false)->handle($params, '192.0.2.44')->json(), $this->zlic(true)->handle($params, '192.0.2.44')->json());

        $row = $this->whmcs->query('SELECT status, validip, validdomain, validdirectory FROM mod_licensing WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $license = License::query()->where('license_key', 'ZCNode-00000000000000aa')->first();
        $this->assertSame([$row['status'], $row['validip'], $row['validdomain'], $row['validdirectory']],
            [$license->status, $license->valid_ip, $license->valid_domain, $license->valid_directory]);
    }

    public function test_the_custom_field_value_is_reported_as_whmcs_does(): void
    {
        $this->whmcs->exec("INSERT INTO tblcustomfieldsvalues (fieldid, relid, value) VALUES (1, 1003, 'host one & two')");
        Property::query()->create(['model_type' => Service::class, 'model_id' => 1003, 'key' => 'comments', 'name' => 'comments', 'value' => 'host one & two']);
        $params = ['license' => 'ZCWeb-00000000000000cc', 'license_PSK' => WhmcsSchema::SECRET];

        $this->assertSame($this->zlic(false)->handle($params, '198.51.100.7')->json(), $this->zlic(true)->handle($params, '198.51.100.7')->json());
    }

    public function test_the_http_endpoint_answers_post_and_get_without_a_session(): void
    {
        $params = ['license' => 'ZCAgent-ffffffffffffffff', 'license_PSK' => 'x'];

        $post = $this->post('/zlic.php', $params);
        $get = $this->get('/zlic.php?' . http_build_query($params));
        $missing = $this->post('/zlic.php', []);

        $post->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertSame('{"status":"Invalid","remotecheck":true}', $post->getContent());
        $this->assertSame($post->getContent(), $get->getContent());
        $this->assertSame(400, $missing->getStatusCode());
        $this->assertSame('{"status":"Invalid","description":"Missing license or license_PSK"}', $missing->getContent());
        $post->assertCookieMissing(config('session.cookie'));
    }
}
