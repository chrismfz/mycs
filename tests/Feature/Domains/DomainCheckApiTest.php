<?php

namespace Tests\Feature\Domains;

use App\Http\Controllers\Api\DomainCheckController;
use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Domains\Lookup\PublicLookups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * WG-20: one name's availability for the company site's live search, in the shape its WHMCS proxy gave, only for the
 * site's server with its key.
 */
class DomainCheckApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'site-key-0123456789abcdef0123456789';

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        LaravelTransport::$resolve = fn (string $host) => ['93.184.216.34'];
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->storeSettings(['default_currency' => 'EUR', 'domain_check_site_key' => self::KEY]);
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        foreach (['com' => 1, 'net' => 2] as $tld => $sort) {
            $model = DomainTld::query()->create(['tld' => $tld, 'is_active' => true, 'sort' => $sort, 'registrar_connection_id' => $op->id]);
            foreach (['register', 'renew', 'transfer'] as $operation) {
                DomainTldPrice::query()->create(['domain_tld_id' => $model->id, 'operation' => $operation, 'years' => 1, 'currency_code' => 'EUR', 'price' => '12.00']);
            }
        }
        DomainTld::query()->create(['tld' => 'io', 'is_active' => true, 'sort' => 3]); // no price: not sold
    }

    /** Openprovider: nixpal.com taken, gold.com premium, down.com a failure, the rest free. */
    private function fake(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if (str_contains($request->url(), '/domains/check')) {
                $names = array_map(fn ($d) => $d['name'] . '.' . $d['extension'], $request->data()['domains']);
                if (in_array('down.com', $names, true)) {
                    return Http::response(['desc' => 'Internal error'], 500);
                }

                return Http::response(['data' => ['results' => array_map(fn (string $name) => ['domain' => $name,
                    'status' => $name === 'nixpal.com' ? 'active' : 'free', 'is_premium' => $name === 'gold.com'], $names)]]);
            }

            return Http::response([], 404);
        });
    }

    /** As the site's server asks: with its key. */
    private function check(string $domain, ?string $key = self::KEY)
    {
        return $this->getJson(route('api.v1.domain-check', ['domain' => $domain]), $key === null ? [] : ['X-Site-Key' => $key]);
    }

    public function test_free_taken_and_premium_in_the_sites_shape(): void
    {
        $this->fake();

        $this->check('Fresh-Name.com')->assertOk()
            ->assertExactJson(['domain' => 'fresh-name.com', 'available' => true, 'premium' => false, 'status' => 'free'])
            ->assertHeader('Cache-Control', 'max-age=300, private');
        $this->check('nixpal.com')->assertOk()->assertJson(['available' => false, 'premium' => false]);
        $this->check('gold.com')->assertOk()->assertJson(['available' => false, 'premium' => true]);
    }

    public function test_a_failed_check_is_null_and_not_cached(): void
    {
        $this->fake();

        $response = $this->check('down.com')->assertOk()->assertJson(['domain' => 'down.com', 'available' => null]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_what_is_not_a_whole_name_or_not_sold_is_said_without_a_lookup(): void
    {
        Http::fake(fn () => throw new \RuntimeException('no lookup expected'));

        foreach (['', 'nixpal', '-bad-.com'] as $input) {
            $this->check($input)->assertStatus(400)->assertExactJson(['error' => 'invalid_domain']);
        }
        $this->getJson('/api/v1/domain-check?domain[]=x.com', ['X-Site-Key' => self::KEY])->assertStatus(400);
        $this->check('nixpal.io')->assertOk()
            ->assertExactJson(['domain' => 'nixpal.io', 'available' => null, 'error' => 'tld_not_supported']);
    }

    /** Owner 2026-11-05: never a free whois for anyone who finds it. Without the key, nothing is there. */
    public function test_without_the_sites_key_there_is_nothing_and_nothing_is_looked_up(): void
    {
        Http::fake(fn () => throw new \RuntimeException('no lookup expected'));

        $this->check('fresh-name.com', null)->assertNotFound();
        $this->check('fresh-name.com', 'wrong-' . self::KEY)->assertNotFound();
        $this->storeSettings(['domain_check_site_key' => 'short']);
        $this->check('fresh-name.com', 'short')->assertNotFound();
        $this->storeSettings(['domain_check_site_key' => '']);
        $this->check('fresh-name.com', '')->assertNotFound();
    }

    public function test_the_sites_bucket_and_each_addresss_are_limited(): void
    {
        for ($i = 0; $i < DomainCheckController::SITE_PER_MINUTE; $i++) {
            $this->check('nixpal')->assertStatus(400);
        }
        $this->check('nixpal')->assertStatus(429);

        for ($i = 0; $i < DomainCheckController::PER_MINUTE; $i++) {
            $this->check('nixpal', null)->assertNotFound();
        }
        $this->check('nixpal', null)->assertStatus(429);
    }

    /** Review of #667: two-character .gr names (staff priced) are not sold at the catalog's price, as the cart says. */
    public function test_a_staff_priced_name_is_premium(): void
    {
        $this->fake();
        $gr = DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'sort' => 4, 'registrar_connection_id' => DomainRegistrarConnection::query()->value('id')]);
        foreach (['register', 'renew'] as $operation) { // a registration is sold for years it renews for
            DomainTldPrice::query()->create(['domain_tld_id' => $gr->id, 'operation' => $operation, 'years' => 2, 'currency_code' => 'EUR', 'price' => '24.00']);
        }

        $this->check('ab.gr')->assertOk()->assertJson(['available' => false, 'premium' => true]);
    }

    /** Review of #667: the site's checks spend the public pool, a cached answer does not, and an empty pool says busy. */
    public function test_the_public_pool_is_spent_only_by_lookups_and_an_empty_one_is_busy(): void
    {
        $this->fake();
        $this->check('fresh-name.com')->assertOk();
        $this->assertSame(1, RateLimiter::attempts('domain-lookups:public'));
        $this->check('fresh-name.com')->assertOk()->assertJson(['available' => true]);
        $this->assertSame(1, RateLimiter::attempts('domain-lookups:public'), 'from the cache: nothing spent');
        $this->check('fresh-name.com', null)->assertNotFound();
        $this->assertSame(1, RateLimiter::attempts('domain-lookups:public'), 'a keyless caller spends nothing');

        RateLimiter::increment('domain-lookups:public', 600, PublicLookups::GLOBAL);
        $this->check('other-name.com')->assertStatus(429)->assertJson(['error' => 'busy']);
        $this->check('fresh-name.com')->assertOk()->assertJson(['available' => true]);
    }
}
