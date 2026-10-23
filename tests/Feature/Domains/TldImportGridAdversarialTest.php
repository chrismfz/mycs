<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainTldResource\Pages\ImportTlds;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Role;
use App\Models\Staff;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Livewire\Features\SupportTesting\Testable;
use RuntimeException;
use Tests\TestCase;

/**
 * Adversarial review of #422 (TLD Import & Pricing Sync as a grid): written without the author's context, each test
 * fails on the PR's code for a defect found.
 */
class TldImportGridAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const PROD = 'https://api.openprovider.eu';

    private DomainRegistrarConnection $op;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        Http::preventStrayRequests();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->storeSettings(['default_currency' => 'EUR']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);
    }

    /** @return array<string, mixed> one TLD as Openprovider answers it */
    private function tld(string $name, mixed $register, mixed $renew): array
    {
        $q = fn (mixed $price) => ['reseller' => ['price' => $price, 'currency' => 'EUR']];

        return ['name' => $name, 'status' => 'ACT', 'prices' => ['create_price' => $q($register), 'renew_price' => $q($renew), 'transfer_price' => $q($renew)]];
    }

    /** @param  array<string, mixed>  $routes */
    private function fake(array $routes): void
    {
        Http::fake([self::PROD . '/v1/auth/login' => Http::response(['data' => ['token' => 'tok']])] + $routes);
    }

    private function adapter(): OpenproviderRegistrar
    {
        /** @var OpenproviderRegistrar */
        return app(DomainRegistrarFactory::class)->for($this->op);
    }

    private function list(): array
    {
        return $this->adapter()->listTldPricing(app(DomainRegistrarFactory::class)->credentialsFor($this->op));
    }

    private function page(): Testable
    {
        return $this->livewireAs($this->staff())->test(ImportTlds::class)
            ->fillForm(['connection_id' => $this->op->id, 'margin_type' => 'percent', 'margin' => '20', 'rounding' => 'none', 'years' => 1]);
    }

    /**
     * F1: a page of the list answered 2xx, code 0, but without its results (a maintenance answer, a garbled body) is
     * skipped in silence: «All» shows 700 of 1,200 TLDs as if that were everything. The interface promises all or
     * nothing («a partial list would hide TLDs without saying so»).
     */
    public function test_f1_a_page_of_the_list_without_results_fails_the_list_instead_of_shrinking_it(): void
    {
        $entries = array_map(fn (int $i) => $this->tld('t' . $i, '1.00', '2.00'), range(1, 1200));
        $page = fn (int $offset) => ['code' => 0, 'data' => ['total' => 1200, 'results' => array_slice($entries, $offset, 500)]];
        $this->fake([
            self::PROD . '/v1/tlds?with_price=true&limit=500&offset=0' => Http::response($page(0)),
            self::PROD . '/v1/tlds?with_price=true&limit=500&offset=500' => Http::response(['code' => 0, 'data' => ['total' => 1200]]),
            self::PROD . '/v1/tlds?with_price=true&limit=500&offset=1000' => Http::response($page(1000)),
        ]);

        $all = null;
        try {
            $all = $this->list();
        } catch (RuntimeException) {
            // refused: right
        }
        $this->assertNull($all === null ? null : count($all), 'a page without results was skipped: ' . count($all ?? []) . ' of 1200 TLDs returned as the whole list');
    }

    /**
     * F2: past MAX_TLD_PAGES (40 × 500) the list is cut at 20,000 in silence, against the same all-or-nothing contract.
     * The cap is right (never an endless loop); quietly returning a partial list is not.
     */
    public function test_f2_a_list_longer_than_the_page_cap_fails_instead_of_being_cut_in_silence(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/v1/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $offset = (int) $query['offset'];
            $results = array_map(fn (int $i) => $this->tld('t' . $i, '1.00', '1.00'), range($offset, $offset + 499));

            return Http::response(['code' => 0, 'data' => ['total' => 20500, 'results' => $results]]);
        });

        $all = null;
        try {
            $all = $this->list();
        } catch (RuntimeException) {
            // refused: right
        }
        $this->assertNull($all === null ? null : count($all), 'the list was cut at ' . count($all ?? []) . ' of 20500 TLDs without saying so');
    }

    /**
     * F3: a boolean price is «scalar», and (string) true is «1»: a garbled `"price": true` becomes a cost of 1.00, and
     * with a 20% margin a selling price of 1.20 for .com. A cost that is not a number must be no cost.
     */
    public function test_f3_a_boolean_price_is_no_cost_not_one_euro(): void
    {
        $this->fake([self::PROD . '/v1/tlds?with_price=true&limit=500&offset=0' => Http::response(['code' => 0, 'data' => ['total' => 1, 'results' => [$this->tld('com', true, '9.80')]]])]);

        $com = $this->list()['com'];

        $this->assertArrayNotHasKey('register', $com->costs, 'cost read from a boolean: ' . json_encode($com->costs['register'] ?? null));
    }

    /**
     * F4: the names of Openprovider's list are only trimmed and lowercased, never checked as TLDs (the single fetch goes
     * through Domain::normalise). A garbled name («Bad TLD!») or one in another form than MyCS keeps (a leading dot, an
     * IDN in Unicode, which MyCS keeps in punycode and so shows as «new» beside the one it sells) becomes a row with a
     * tick box; the import then fails on it (F4b).
     */
    public function test_f4_a_garbled_name_in_the_list_is_never_a_tld_the_import_creates(): void
    {
        $results = [$this->tld('com', '11.23', '9.80'), $this->tld('Bad TLD!', '1.00', '1.00'), $this->tld('.NET', '9.00', '9.00')];
        $this->fake([self::PROD . '/v1/tlds?with_price=true&limit=500&offset=0' => Http::response(['code' => 0, 'data' => ['total' => 3, 'results' => $results]])]);

        foreach (array_keys($this->list()) as $name) {
            try {
                $this->assertSame(Domain::normalise((string) $name), (string) $name, 'a TLD in MyCS\'s form');
            } catch (InvalidArgumentException) {
                $this->fail("«{$name}» is not a TLD, yet the list returned it");
            }
        }
    }

    /**
     * F4b (the same defect, end to end): the garbled row has a tick box, «tick this page» ticks it with the rest, and the
     * import then dies on DomainTld's own normalise() (an uncaught «Not a domain name»): no TLD of the page is imported,
     * and staff get an error page instead of the grid.
     */
    public function test_f4b_a_garbled_name_from_the_list_never_breaks_the_import_of_the_others(): void
    {
        $results = [$this->tld('com', '11.23', '9.80'), $this->tld('Bad TLD!', '1.00', '1.00')];
        $this->fake([self::PROD . '/v1/tlds?with_price=true&limit=500&offset=0' => Http::response(['code' => 0, 'data' => ['total' => 2, 'results' => $results]])]);

        $page = $this->page()->fillForm(['tlds' => 'com'])->call('fetchAll')->call('togglePage');
        try {
            $page->call('import');
        } catch (InvalidArgumentException $e) {
            $this->fail('the import of ' . json_encode($page->get('selected')) . ' died: ' . $e->getMessage());
        }

        $this->assertNotNull(DomainTld::query()->where('tld', 'com')->first(), '.com was ticked and has its costs');
    }

    /**
     * F5: the grid says «→ 8.38», «the price the import would write», over a free (0.00) or not-offered (empty) price,
     * while the import keeps those (keep_free_and_closed, on by default). Staff decide on a price that is never written;
     * the preview and the import must agree.
     */
    public function test_f5_the_grid_shows_the_price_the_import_writes_for_a_kept_free_or_closed_price(): void
    {
        $eu = DomainTld::query()->create(['tld' => 'eu']);
        DomainTldPrice::query()->create(['domain_tld_id' => $eu->id, 'operation' => 'register', 'years' => 1, 'currency_code' => 'EUR', 'price' => '0.00']);
        DomainTldPrice::query()->create(['domain_tld_id' => $eu->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => null]);
        $this->fake([self::PROD . '/v1/tlds/eu?*' => Http::response(['code' => 0, 'data' => $this->tld('eu', '6.98', '4.60')])]);

        $page = $this->page()->fillForm(['tlds' => 'eu'])->call('fetch');
        $ops = $page->instance()->grid()['rows']['eu']['ops'];
        $shown = ['register' => $ops['register']['new'] ?? $ops['register']['now'], 'renew' => $ops['renew']['new'] ?? $ops['renew']['now']];

        $page->set('selected', ['eu'])->call('import');
        $eu->refresh()->load('prices');
        $written = ['register' => $eu->priceFor('register', 1, 'EUR'), 'renew' => $eu->priceFor('renew', 1, 'EUR')];

        $this->assertSame(['register' => '0.00', 'renew' => null], $written, 'the import kept them (as designed)');
        $this->assertSame($written, $shown, 'the grid promised other prices than the import wrote');
    }
}
