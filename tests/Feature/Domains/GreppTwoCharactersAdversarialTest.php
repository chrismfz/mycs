<?php

namespace Tests\Feature\Domains;

use App\Classes\Cart as ClassesCart;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Livewire\DomainShop\Search;
use App\Models\CartItem;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\InvoiceSelectedItems;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\Lookup\DomainLookup;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * Adversarial review of #202 (.gr names of 2 characters): the EETT charges 300 € for each registration, renewal or
 * transfer, so such a name must never be sold, billed or sent at the TLD's price (~19-24 €). The PR guards the shop's
 * «free» answer (premium) and the registrar's register/renew/transferIn; these tests look for the paths around them.
 * Failing tests mark real defects; the rest are regressions for what held.
 */
class GreppTwoCharactersAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $gr;

    private DomainTld $tld;

    private User $user;

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 10:00:00');
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15,
            'settings.credits_auto_use' => false]);
        session(['currency' => 'EUR']);
        config(['domains.registrars.grepp' => AdvTwoCharGrEppRegistrar::class]);
        AdvTwoCharGrEppRegistrar::$record = null;
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $this->tld = DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $this->gr->id,
            'grace_days' => 15, 'redemption_days' => 0]);
        // .gr as it should be priced: even years, a transfer «1 year, 0.00» (it adds no period).
        foreach (['register' => [2 => '24.00'], 'renew' => [2 => '24.00'], 'transfer' => [1 => '0.00']] as $operation => $prices) {
            foreach ($prices as $years => $price) {
                DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => $operation, 'years' => $years,
                    'currency_code' => 'EUR', 'price' => $price]);
            }
        }
        $this->user = User::factory()->create();
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
        GrEppRegistrar::reset();
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** hello, login, then the registry's domain:check answer: name => avail. */
    private function registryChecks(array $names): void
    {
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
        $cds = '';
        foreach ($names as $name => $avail) {
            $cds .= '<domain:cd><domain:name avail="' . ($avail ? 1 : 0) . '">' . $name . '</domain:name></domain:cd>';
        }
        $this->transport->queue(new HttpResponse(200, '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
            . '<result code="1000"><msg>ok</msg></result><resData><domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
            . $cds . '</domain:chkData></resData><trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>'));
    }

    private function twoCharDomain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'ab', 'tld' => 'gr', 'fqdn' => 'ab.gr',
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::Active,
            'expires_at' => '2026-11-20', 'currency_code' => 'EUR', 'years' => 2, 'auto_renew' => true]);
    }

    private function addRefused(string $fqdn, string $operation): ?string
    {
        try {
            ClassesCart::addDomain($fqdn, $operation);
        } catch (DisplayException $e) {
            return $e->getMessage();
        }

        return null;
    }

    // ---- held: registering a free 2-character name from the shop ---------------------------------------------------

    /** R1 (held): typed in any form (case, www., trailing dot, full-width), a free ab.gr is premium: never carted. */
    public function test_r1_a_free_two_character_name_is_never_carted_whatever_its_form(): void
    {
        foreach (['ab.gr', 'WWW.AB.GR.', 'ＡＢ.gr', "ab\u{00AD}.gr"] as $typed) {
            Cache::flush();
            $this->registryChecks(['ab.gr' => true]);
            $this->assertNotNull($this->addRefused($typed, 'register'), "{$typed}: carted at the TLD price");
        }
        $this->assertSame(0, CartItem::query()->count());
    }

    // ---- F1: a taken 2-character name sold as a transfer at the TLD price ---------------------------------------------

    /**
     * F1 (high): a taken ab.gr is offered «Transfer it to us» and carted at the TLD's transfer price (0.00 here); the
     * order is placed and only transferIn refuses (to staff) after it was sold. The EETT charges 300 € for the transfer.
     */
    public function test_f1_a_taken_two_character_name_is_not_sold_as_a_transfer(): void
    {
        $this->registryChecks(['ab.gr' => false]);
        $this->assertNotNull($this->addRefused('ab.gr', 'transfer'), 'a 2-character transfer carted at the TLD price');

        Cache::flush();
        $this->registryChecks(['ab.gr' => false]);
        Livewire::test(Search::class)->set('query', 'ab.gr')->call('search')->assertDontSee("transfer('ab.gr')", false);
    }

    // ---- F2: the renewal billed at the TLD price ------------------------------------------------------------------

    /**
     * F2 (high): the renewal cron (and the portal's «Renew», InvoiceSelectedItems) bills ab.gr at the TLD's 24.00; the
     * customer pays it and the renewal is then never asked (renew() refuses: not asked, to staff), or staff pay 300 €.
     */
    public function test_f2_a_two_character_renewal_is_never_billed_at_the_tld_price(): void
    {
        $domain = $this->twoCharDomain();
        DB::transaction(fn () => app(RenewalBilling::class)->billDomainRenewal($domain->id, Carbon::parse('2027-01-01')));
        $this->assertSame([], InvoiceItem::query()->where('reference_id', $domain->id)->pluck('price')->map(fn ($p) => (string) $p)->all(),
            'the cron billed a 2-character renewal at the TLD price');

        (new InvoiceSelectedItems)->handle([], [$domain->fresh()]);
        $this->assertSame(0, InvoiceItem::query()->where('reference_id', $domain->id)->count(), 'the portal «Renew» billed it at the TLD price');
    }

    // ---- F3: admin «Renew now» leaves an invoice at the TLD price ---------------------------------------------------

    /**
     * F3 (medium): «Renew now» bills the period first (billedOrBill: 24.00, an invoice of its own) and only then asks
     * the registrar, whose renew() refuses 2 characters: the action fails but the 24.00 invoice stays for the customer.
     */
    public function test_f3_renew_now_leaves_no_invoice_at_the_tld_price(): void
    {
        $domain = $this->twoCharDomain();
        AdvTwoCharGrEppRegistrar::$record = new DomainSyncResult(expiresAt: '2026-11-20', status: DomainStatus::Active, rawStatus: 'ok');
        $refused = null;
        try {
            app(DomainRenewalService::class)->renewNow($domain, Staff::factory()->create());
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }
        $this->assertNotNull($refused, 'renewed at the TLD price');
        $this->assertSame(0, Invoice::query()->count(), 'an invoice at the TLD price left behind: ' . (string) $refused);
    }

    // ---- F4: the rule lives in one registrar driver only --------------------------------------------------------

    /**
     * F4 (medium): the 2-character rule is only in GrEppRegistrar's availability answer. A .gr TLD whose registry
     * connection is off (or none, or another registrar such as Openprovider) is searched by RDAP, which says «free»:
     * ab.gr is carted at 24.00 and staff must then register it by hand for 300 €.
     */
    public function test_f4_a_gr_tld_checked_by_rdap_still_does_not_sell_two_characters(): void
    {
        $this->gr->update(['is_active' => false]);
        $this->mock(DomainLookup::class, fn ($mock) => $mock->shouldReceive('availableByRdap')->andReturn(true));

        $this->assertNotNull($this->addRefused('ab.gr', 'register'), 'ab.gr carted at the TLD price on an RDAP «free»');
    }
}

/** The .gr registry's driver with its real register/renew/transferIn guards; its domain:info as the test sets it. */
class AdvTwoCharGrEppRegistrar extends GrEppRegistrar
{
    public static ?DomainSyncResult $record = null;

    public function capabilities(): DomainRegistrarCapabilities
    {
        return new DomainRegistrarCapabilities(supportsTransfer: true, renewsInGrace: true, renewYearStep: 2,
            authCodeByEmail: true, transferKeepsNameservers: true, supportsRegistration: true);
    }

    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        return self::$record ?? parent::syncDomain($domain, $credentials);
    }
}
