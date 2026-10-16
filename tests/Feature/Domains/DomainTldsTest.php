<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainTldResource;
use App\Admin\Resources\DomainTldResource\Pages\EditDomainTld;
use App\Admin\Resources\DomainTldResource\Pages\ImportTlds;
use App\Admin\Resources\DomainTldResource\RelationManagers\PricesRelationManager;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\Pricing\TldCostSync;
use App\Services\Domains\Pricing\TldImport;
use App\Services\Domains\Pricing\TldImportOptions;
use App\Support\Domains\TldPricing;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Domains D3 (docs/domains.md D-5..D-8): TLDs and their prices; the cost sync that never moves a price; the import
 * with a margin, a rounding, N years and staff's rates; its page; who may do what.
 */
class DomainTldsTest extends TestCase
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
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
    }

    private function staff(array $permissions = ['*']): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    /** @param  array<string, array<string, mixed>>  $prices  tld => Openprovider price block */
    private function fakeOpenprovider(array $prices): void
    {
        $routes = [self::PROD . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']])];
        foreach ($prices as $tld => $block) {
            $routes[self::PROD . '/v1beta/tlds/' . $tld . '*'] = $block === []
                ? Http::response(['desc' => 'Extension not supported'], 400)
                : Http::response(['data' => ['prices' => $block]]);
        }
        Http::fake($routes);
    }

    private function quote(string $register, string $renew, ?string $restore = null): array
    {
        $q = fn (string $price) => ['reseller' => ['price' => $price, 'currency' => 'EUR']];

        return array_filter(['create_price' => $q($register), 'renew_price' => $q($renew), 'transfer_price' => $q($renew),
            'restore_price' => $restore === null ? null : $q($restore)]);
    }

    public function test_the_cost_sync_writes_costs_only_never_a_price(): void
    {
        $tld = DomainTld::query()->create(['tld' => '.COM', 'registrar_connection_id' => $this->op->id]);
        $this->assertSame('com', $tld->tld, 'one form');
        $one = DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '16.50']);
        $three = DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 3, 'currency_code' => 'USD', 'price' => '55.00']);
        $this->fakeOpenprovider(['com' => $this->quote('9.80', '9.80')]);

        $this->assertSame(2, app(TldCostSync::class)->sync($tld));

        $this->assertSame(['16.50', '9.80', 'EUR'], [$one->fresh()->price, $one->fresh()->cost, $one->fresh()->cost_currency]);
        $this->assertSame(['55.00', '29.40'], [$three->fresh()->price, $three->fresh()->cost], 'three years cost three times');
        $this->assertSame(2, DomainTldPrice::query()->count(), 'no row created');
        $this->assertSame(0, app(TldCostSync::class)->sync($tld), 'a re-run changes nothing');

        $manual = DomainRegistrarConnection::query()->create(['registrar' => 'manual', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $this->assertNull(app(TldCostSync::class)->connection(DomainTld::query()->create(['tld' => 'net', 'registrar_connection_id' => $manual->id])));
    }

    public function test_the_import_prices_every_currency_with_a_rate_for_n_years(): void
    {
        $pricing = new TldPricing(tld: 'com', costs: ['register' => ['cost' => '9.80', 'currency' => 'EUR'], 'renew' => ['cost' => '9.80', 'currency' => 'EUR'],
            'transfer' => ['cost' => '9.80', 'currency' => 'EUR'], 'redemption' => ['cost' => '42.16', 'currency' => 'EUR']]);
        $options = new TldImportOptions(marginType: 'percent', margin: '68.37', rounding: '.50', years: 3, autoRegister: true, rates: ['USD' => '1.1195']);

        app(TldImport::class)->import($this->op, ['com' => $pricing], $options);

        $tld = DomainTld::query()->where('tld', 'com')->sole();
        $this->assertSame($this->op->id, $tld->registrar_connection_id);
        $tld->load('prices');
        $this->assertSame('16.50', $tld->priceFor('register', 1, 'EUR'));
        $this->assertSame('49.50', $tld->priceFor('renew', 3, 'EUR'), '3 x the rounded 1-year price');
        $this->assertSame('18.50', $tld->priceFor('register', 1, 'USD'), '9.80 x 1.1195 = 10.97, +68.37% = 18.47, to .50');
        $this->assertSame('16.50', $tld->priceFor('transfer', 1, 'EUR'));
        $this->assertNull($tld->priceFor('transfer', 2, 'EUR'), 'a transfer is one year');
        $this->assertNull($tld->priceFor('redemption', 1, 'EUR'), 'not asked');
        $this->assertSame('29.40', DomainTldPrice::query()->where(['operation' => 'register', 'years' => 3, 'currency_code' => 'EUR'])->value('cost'));

        $withoutRate = new TldImportOptions(marginType: 'fixed', margin: '5', redemptionFee: true);
        app(TldImport::class)->import($this->op, ['com' => $pricing], $withoutRate);
        $tld->load('prices');
        $this->assertSame('14.80', $tld->priceFor('register', 1, 'EUR'), 'updated');
        $this->assertSame('47.16', $tld->priceFor('redemption', 1, 'EUR'));
        $this->assertSame('18.50', $tld->priceFor('register', 1, 'USD'), 'no rate: that currency untouched');
        $this->assertSame($this->op->id, $tld->fresh()->registrar_connection_id, 'not asked: kept');
    }

    public function test_the_page_fetches_without_writing_and_imports_only_what_is_ticked(): void
    {
        $this->fakeOpenprovider(['com' => $this->quote('9.80', '9.80'), 'net' => $this->quote('11.11', '11.11'), 'xyz' => []]);

        $page = $this->livewireAs($this->staff())->test(ImportTlds::class)
            ->fillForm(['connection_id' => $this->op->id, 'tlds' => '.com, NET xyz', 'margin_type' => 'percent', 'margin' => '20', 'rounding' => 'none', 'years' => 1])
            ->call('fetch')
            ->assertSee('Extension not supported')->assertSee('11.76');
        $this->assertSame(0, DomainTld::query()->count(), 'fetch writes nothing');

        $page->set('selected', ['net'])->call('import');

        $this->assertSame(['net'], DomainTld::query()->pluck('tld')->all());
        $this->assertSame('13.33', DomainTld::query()->sole()->load('prices')->priceFor('register', 1, 'EUR'));
    }

    public function test_who_may_see_change_and_import(): void
    {
        $tld = DomainTld::query()->create(['tld' => 'com']);
        Domain::factory()->create(['client_id' => User::factory()->create()->client()->id, 'domain_tld_id' => $tld->id, 'sld' => 'x', 'tld' => 'com', 'fqdn' => 'x.com']);

        $this->actingAs($this->staff(['admin.domain_tlds.viewAny']))->get(DomainTldResource::getUrl('index'))->assertOk()->assertSee('.com');
        $this->actingAs($this->staff(['admin.domain_tlds.viewAny']))->get(DomainTldResource::getUrl('import'))->assertForbidden();
        $this->actingAs($this->staff(['admin.domains.viewAny']))->get(DomainTldResource::getUrl('index'))->assertForbidden();

        $this->livewireAs($this->staff())->test(EditDomainTld::class, ['record' => $tld->getRouteKey()])
            ->callAction('delete')->assertNotified(__('domains.tld_in_use', ['tld' => 'com']));
        $this->assertNotNull($tld->fresh());

        $this->livewireAs($this->staff())->test(PricesRelationManager::class, ['ownerRecord' => $tld, 'pageClass' => EditDomainTld::class])
            ->callTableAction('create', data: ['operation' => 'transfer', 'years' => 1, 'currency_code' => 'USD', 'price' => '0'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('0.00', $tld->load('prices')->priceFor('transfer', 1, 'USD'), 'free and offered');
    }
}
