<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainTldResource\Pages\CreateDomainTld;
use App\Admin\Resources\DomainTldResource\Pages\ImportTlds;
use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Role;
use App\Models\Staff;
use App\Services\Domains\Pricing\TldCostSync;
use App\Services\Domains\Pricing\TldImport;
use App\Services\Domains\Pricing\TldImportOptions;
use App\Support\Domains\TldPricing;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Tests\TestCase;

/**
 * Adversarial review of D3 (PR #160): TLD prices, the cost sync and the import. Each test is a finding and fails on
 * the PR as it stands.
 */
class DomainsD3AdversarialTest extends TestCase
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
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
    }

    private function staff(array $permissions = ['*']): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    /** @param  array<string, array<string, mixed>>  $prices */
    private function fakeOpenprovider(array $prices): void
    {
        $routes = [self::PROD . '/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok']])];
        foreach ($prices as $tld => $block) {
            $routes[self::PROD . '/v1beta/tlds/' . $tld . '*'] = Http::response(['data' => ['prices' => $block]]);
        }
        Http::fake($routes);
    }

    private function quote(string $price): array
    {
        $q = ['reseller' => ['price' => $price, 'currency' => 'EUR']];

        return ['create_price' => $q, 'renew_price' => $q, 'transfer_price' => $q];
    }

    /** F1: a negative cost (a registrar promo/credit, a bad answer) becomes a negative price customers would "pay". */
    public function test_f1_a_negative_registrar_cost_never_becomes_a_negative_price(): void
    {
        $pricing = new TldPricing(tld: 'com', costs: ['register' => ['cost' => '-5.00', 'currency' => 'EUR']]);

        try {
            app(TldImport::class)->import($this->op, ['com' => $pricing], new TldImportOptions(marginType: 'percent', margin: '20'));
        } catch (\Throwable) {
            // refusing is fine
        }

        $this->assertSame(0, DomainTldPrice::query()->where('price', '<', 0)->count(), 'a negative price was written');
    }

    /** F1 end to end: Openprovider quoting a negative price is taken as a cost (D2 parser) and synced as one. */
    public function test_f1b_the_cost_sync_never_writes_a_negative_cost(): void
    {
        $tld = DomainTld::query()->create(['tld' => 'com', 'registrar_connection_id' => $this->op->id]);
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'register', 'years' => 1, 'currency_code' => 'EUR', 'price' => '12.00']);
        $this->fakeOpenprovider(['com' => $this->quote('-3.50')]);

        try {
            app(TldCostSync::class)->sync($tld);
        } catch (\Throwable) {
            // refusing is fine
        }

        $this->assertSame(0, DomainTldPrice::query()->where('cost', '<', 0)->count(), 'a negative cost was written');
    }

    /** F2: `fetched` is a public, unlocked Livewire property: staff forge costs for a TLD never fetched and import them. */
    public function test_f2_the_import_uses_only_what_the_registrar_answered_not_forged_livewire_state(): void
    {
        $page = $this->livewireAs($this->staff())->test(ImportTlds::class)
            ->fillForm(['connection_id' => $this->op->id, 'tlds' => 'com', 'margin_type' => 'percent', 'margin' => '0', 'rounding' => 'none', 'years' => 1]);
        try {
            $page->set('fetched', ['evil' => ['costs' => ['register' => ['cost' => '0.01', 'currency' => 'EUR']]]]);
            $this->fail('the fetched costs took a value from the browser');
        } catch (CannotUpdateLockedPropertyException) {
            // refused: they are written by fetch() only
        }
        $page->set('selected', ['evil'])->call('import');

        $this->assertNull(DomainTld::query()->where('tld', 'evil')->first(), 'a TLD never fetched was imported with a forged cost of 0.01');
    }

    /** F3: rounding to x.99 turns a free (0.00, offered) operation into a paid one: a .gr transfer becomes 0.99. */
    public function test_f3_rounding_never_turns_a_free_operation_into_a_paid_one(): void
    {
        $pricing = new TldPricing(tld: 'gr', costs: ['transfer' => ['cost' => '0.00', 'currency' => 'EUR']]);
        $prices = app(TldImport::class)->prices($pricing, new TldImportOptions(marginType: 'percent', margin: '20', rounding: '.99'));

        $this->assertSame('0.00', $prices['EUR']['transfer'][1]['price'], 'free transfer priced at 0.99 by the .99 rounding');
    }

    /** F4: «.com» (or «ΕΛ» for xn--qxam) passes the form's unique rule, then the model normalises it: a 500, not a form error. */
    public function test_f4_a_duplicate_tld_in_another_form_is_a_form_error_not_a_500(): void
    {
        DomainTld::query()->create(['tld' => 'com']);

        $this->livewireAs($this->staff())->test(CreateDomainTld::class)
            ->fillForm(['tld' => '.com'])
            ->call('create')
            ->assertHasFormErrors(['tld']);

        $this->assertSame(1, DomainTld::query()->count());
    }

    /** F5: a rate of 0 passes the form's regex and makes the import throw (a 500) instead of a form error. */
    public function test_f5_a_zero_rate_is_a_form_error(): void
    {
        $this->fakeOpenprovider(['com' => $this->quote('9.80')]);
        $this->livewireAs($this->staff())->test(ImportTlds::class)
            ->fillForm(['connection_id' => $this->op->id, 'tlds' => 'com', 'margin_type' => 'percent', 'margin' => '20', 'rounding' => 'none', 'years' => 1])
            ->call('fetch')
            ->fillForm(['rates' => ['USD' => '0']])
            ->set('selected', ['com'])
            ->call('import')
            ->assertHasFormErrors(['rates.USD']);
    }

    /** F6: `--tld` is only ltrimmed, not normalised: `--tld=ελ` (stored xn--qxam) syncs nothing and still reports success. */
    public function test_f6_the_command_normalises_its_tld_option(): void
    {
        $tld = DomainTld::query()->create(['tld' => 'ελ', 'registrar_connection_id' => $this->op->id]);
        $this->assertSame('xn--qxam', $tld->tld);
        $row = DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '20.00']);
        $this->fakeOpenprovider(['xn--qxam' => $this->quote('9.80')]);

        $this->artisan('domains:sync-costs', ['--tld' => '.ελ'])->assertSuccessful();

        $this->assertSame('9.80', $row->fresh()->cost, 'the IDN TLD named in its Unicode form was silently skipped');
    }
}
