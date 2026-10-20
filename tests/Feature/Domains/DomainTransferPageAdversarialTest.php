<?php

namespace Tests\Feature\Domains;

use App\Livewire\DomainShop\Search;
use App\Livewire\DomainShop\Transfer;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Domains\Lookup\VisitorKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of #271 (the transfer page): failing tests for what was found. */
class DomainTransferPageAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        LaravelTransport::$resolve = fn (string $host) => ['93.184.216.34'];
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.domain_billing_enabled' => true]);
        session(['currency' => 'EUR']);
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $com = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $op->id, 'epp_code_required' => true]);
        foreach ([['register', 1, '23.33'], ['renew', 1, '23.33'], ['transfer', 1, '20.00']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $com->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
        // Openprovider: every name starting with «taken» is registered, every other free.
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
                str_contains($url, '/domains/check') => Http::response(['data' => ['results' => array_map(fn ($d) => [
                    'domain' => $d['name'] . '.' . $d['extension'],
                    'status' => str_starts_with($d['name'], 'taken') ? 'active' : 'free',
                ], $request->data()['domains'])]]),
                default => Http::response([], 404),
            };
        });
    }

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        parent::tearDown();
    }

    /**
     * A visitor without a cart adds several names at once: Cart::createCart() reads the cart from the request's cookie
     * (Cookie::has), never from the one it just queued, so each name makes a cart of its own and the visitor's cookie
     * names only the last. The page says all were added (its $added list), the cart holds one.
     */
    public function test_bulk_into_a_new_cart_puts_every_name_in_one_cart(): void
    {
        $page = Livewire::test(Transfer::class)->set('mode', 'bulk')
            ->set('lines', "taken1.com A-1\ntaken2.com B-2\ntaken3.com C-3")
            ->call('addMany');

        $this->assertSame(['taken1.com' => true, 'taken2.com' => true, 'taken3.com' => true], $page->get('bulk'));
        $this->assertSame(3, CartItem::query()->count());
        $this->assertSame(1, Cart::query()->count(), 'three carts made, the visitor\'s cookie reaches one of them');
    }

    /**
     * The bulk path is an availability check of up to 15 names a request that never spends the search's limit
     * (Search::LIMIT a visitor in 10 minutes): only the cart's 10 adds a minute, so 150 names a minute per visitor
     * are asked of the registrar, and the answer per line («not registered») tells which are free.
     */
    public function test_bulk_respects_the_search_limit_of_the_visitor(): void
    {
        $key = 'domain-search-hits:' . VisitorKey::of(request());
        for ($i = 0; $i < Search::LIMIT; $i++) {
            RateLimiter::hit($key, 600);
        }

        Livewire::test(Transfer::class)->set('mode', 'bulk')
            ->set('lines', implode("\n", array_map(fn ($i) => "free{$i}.com", range(1, 15))))
            ->call('addMany');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/domains/check'));
    }
}
