<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\Ordering\DomainOrderLine;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Review of the domains sweep fixes (2026-11-07). DOMAINS-2/3/5 keep an odd .gr period out of registrations, the
 * client's period choice and adoption, but the same 1-year .gr renew price still gives a .gr domain an odd period on
 * the other paths that pick one from offeredYears('renew') / shortestYears('renew'), never billed («steps of 2, not 1»).
 */
class RevDomainsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 10:00:00');
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        config(['domains.registrars.revfakegr' => RevFakeGrRegistrar::class, 'settings.domain_billing_enabled' => true,
            'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->user = User::factory()->create();
        $this->user->client()->ensurePrimaryContact()->update(['first_name' => 'Test', 'last_name' => 'Person', 'email' => 'person@example.test',
            'phone' => '+30.2100000000', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10000', 'country' => 'GR']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, array<int, string>>  $prices */
    private function grTld(array $prices): array
    {
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'revfakegr', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $tld = DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'registrar_connection_id' => $gr->id, 'grace_days' => 15, 'redemption_days' => 0]);
        foreach ($prices as $operation => $byYears) {
            foreach ($byYears as $years => $price) {
                DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
            }
        }

        return [$gr, $tld->load('prices')];
    }

    // REV-DOMAINS-1: a .gr transfer, with a 1-year .gr renew price present (the precondition of DOMAINS-2/3), keeps the
    // transfer's 1 year as its renewal period (PlaceOrderService: renew offers 1), so its renewal is never billed.
    public function test_rev_1_a_gr_transfer_never_gets_an_odd_renewal_period(): void
    {
        [, $tld] = $this->grTld(['register' => [2 => '24.00'], 'renew' => [1 => '12.00', 2 => '24.00'], 'transfer' => [1 => '5.00']]);
        $client = $this->user->client();

        app(PlaceOrderService::class)->place($client, 'EUR', [], domains: [new DomainOrderLine('rev-example.gr', $tld, DomainOrderLine::TRANSFER, 1,
            $client->ensurePrimaryContact()->fresh(), 'Epp-Code-1')]);
        $domain = Domain::query()->where('fqdn', 'rev-example.gr')->sole();
        $domain->forceFill(['status' => DomainStatus::Active, 'expires_at' => '2026-11-20'])->save();

        $billing = app(RenewalBilling::class);
        $billed = DB::transaction(fn () => $billing->billDomainRenewal($domain->id, Carbon::parse('2027-01-01')));
        $this->assertSame(0, $domain->fresh()->years % 2, "a .gr domain renews in steps of two, got years={$domain->fresh()->years}");
        $this->assertSame(1, $billed, 'its renewal is billed: ' . implode(' | ', array_map(fn ($e) => $e->getMessage(), $billing->notices)));
    }

    // REV-DOMAINS-2: a .gr domain in grace renews for stageRenewalYears(), its TLD's shortest renewal (shortestYears
    // 'renew', not in steps): 1 with a 1-year .gr renew price, which RenewalBilling refuses, so it lapses unbilled.
    public function test_rev_2_a_gr_domain_in_grace_renews_in_its_registrars_steps(): void
    {
        [$gr, $tld] = $this->grTld(['renew' => [1 => '12.00', 2 => '24.00']]);
        $domain = Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'rev-example', 'tld' => 'gr', 'fqdn' => 'rev-example.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $gr->id, 'status' => DomainStatus::Grace, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'years' => 2, 'auto_renew' => true, 'recurring_amount' => null]);
        $this->assertSame(2, $domain->renewYearStep());

        $this->assertSame(0, $domain->nextRenewalYears() % 2, "in grace it renews for {$domain->nextRenewalYears()} years, never billed");
    }
}

/** The .gr registry's adapter, asked nothing: only its capabilities (renewals in steps of two) are read. */
class RevFakeGrRegistrar extends GrEppRegistrar
{
    public function key(): string
    {
        return 'revfakegr';
    }
}
