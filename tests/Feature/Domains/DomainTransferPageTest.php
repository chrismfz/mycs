<?php

namespace Tests\Feature\Domains;

use App\Classes\Navigation;
use App\Livewire\DomainShop\Transfer;
use App\Models\CartItem;
use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Services\Domains\Lookup\LaravelTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** A domain transfer, its own page in the menu (owner 2026-10-20): whether a name comes to us, its price, the steps. */
class DomainTransferPageTest extends TestCase
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
        // Openprovider: nixpal.com is taken, every other name free.
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
                str_contains($url, '/domains/check') => Http::response(['data' => ['results' => array_map(fn ($d) => [
                    'domain' => $d['name'] . '.' . $d['extension'],
                    'status' => $d['name'] . '.' . $d['extension'] === 'nixpal.com' ? 'active' : 'free',
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

    public function test_the_menu_has_the_transfer_page_and_it_explains_the_steps(): void
    {
        $domains = collect(Navigation::getLinks())->firstWhere('name', __('navigation.domains'));
        $this->assertContains(route('domain.transfer'), array_column($domains['children'], 'url'));

        $this->get(route('domain.transfer'))->assertOk()->assertSee(__('domains.transfer.title'))->assertSee(__('domains.transfer.step_code'));
        $this->get(route('domain.search'))->assertOk()->assertSee(route('domain.transfer'));
    }

    public function test_a_registered_name_goes_to_the_cart_with_its_code_and_the_page_stays_for_another(): void
    {
        Livewire::test(Transfer::class)->set('query', 'https://www.nixpal.com/')->set('epp', ' Abc-123 ')->call('check')
            ->assertSee('nixpal.com')->assertSee(__('domains.transfer.can'))->assertSee('20,00')
            ->call('transfer')->assertNoRedirect()->assertDispatched('cartUpdated')->assertSet('query', '')->assertSet('epp', '')
            ->assertSee(trans_choice('domains.transfer.in_cart', 1, ['count' => 1]));

        $item = CartItem::query()->sole();
        $this->assertSame(['transfer', 'nixpal.com', 'Abc-123'], [$item->domain['operation'], $item->domain['fqdn'], $item->epp_code]);
    }

    public function test_many_at_once_each_with_its_code_and_those_refused_stay_with_why(): void
    {
        $page = Livewire::test(Transfer::class)->set('mode', 'bulk')
            ->set('lines', "nixpal.com  CODE-1\n\nmybusiness.com x\nnixpal.xyz\nNIXPAL.com, CODE-2")
            ->call('addMany')->assertDispatched('cartUpdated');

        $item = CartItem::query()->sole();
        $this->assertSame(['nixpal.com', 'CODE-2'], [$item->domain['fqdn'], $item->epp_code], 'one line a name: the last code typed');
        $page->assertSee(__('domains.order.not_registered', ['domain' => 'mybusiness.com']))->assertSee(__('domains.order.not_offered'))
            ->assertSet('lines', "mybusiness.com x\nnixpal.xyz");
    }

    public function test_at_most_fifteen_at_once(): void
    {
        Livewire::test(Transfer::class)->set('mode', 'bulk')->set('lines', implode("\n", array_map(fn ($i) => "name{$i}.com", range(1, 16))))
            ->call('addMany')->assertSee(__('domains.transfer.too_many_lines', ['count' => 15]));

        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_a_free_name_is_offered_for_registration_instead(): void
    {
        Livewire::test(Transfer::class)->set('query', 'mybusiness.com')->call('check')
            ->assertSee(__('domains.transfer.not_registered'))->assertDontSee(__('domains.transfer.can'))
            ->call('register')->assertNoRedirect();

        $this->assertSame('register', CartItem::query()->sole()->domain['operation']);
    }

    public function test_a_name_without_its_ending_or_of_an_ending_we_do_not_sell_is_said_so(): void
    {
        Livewire::test(Transfer::class)->set('query', 'mybusiness')->call('check')->assertSee(__('domains.shop.not_a_full_name'))->assertSet('result', null);
        Livewire::test(Transfer::class)->set('query', 'mybusiness.xyz')->call('check')->assertSee(__('domains.transfer.unsold', ['tld' => '.xyz']))->assertSet('result', null);
    }

    public function test_with_transfers_off_neither_the_menu_nor_the_button_offers_one(): void
    {
        config(['settings.domain_allow_transfer' => false]);

        $domains = collect(Navigation::getLinks())->firstWhere('name', __('navigation.domains'));
        $this->assertNotContains(route('domain.transfer'), array_column($domains['children'], 'url'));
        Livewire::test(Transfer::class)->set('query', 'nixpal.com')->call('check')->assertDontSee(__('domains.transfer.can'));
    }
}
