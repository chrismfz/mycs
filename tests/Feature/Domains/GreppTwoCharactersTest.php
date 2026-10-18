<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\InvoiceItem;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\Ordering\DomainOffer;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * .gr names of 2 characters (owner 2026-10-18): the EETT charges 300 € for each deed, so they are never sold or charged
 * from here at the TLD's price. Free, they are premium (the shop: «contact us»); registering or renewing one is never
 * sent: staff do it by hand.
 */
class GreppTwoCharactersTest extends TestCase
{
    use RefreshDatabase;

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
        GrEppRegistrar::reset();
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        parent::tearDown();
    }

    private function credentials(): DomainRegistrarCredentials
    {
        return new DomainRegistrarCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], sandbox: false);
    }

    public function test_which_names_have_two_characters(): void
    {
        foreach (['ab.gr', 'AB.GR', 'ab.com.gr', 'αβ.ελ', 'xn--mxac.gr', 'a.gr'] as $name) {
            $this->assertTrue(GrEppRegistrar::twoCharacters($name), $name);
        }
        foreach (['abc.gr', 'αβγ.ελ', 'example.gr', 'ab-c.gr'] as $name) {
            $this->assertFalse(GrEppRegistrar::twoCharacters($name), $name);
        }
    }

    public function test_free_it_is_premium_and_registering_or_renewing_it_is_never_sent(): void
    {
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
        $this->transport->queue(new HttpResponse(200, '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
            . '<result code="1000"><msg>ok</msg></result><resData><domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
            . '<domain:cd><domain:name avail="1">ab.gr</domain:name></domain:cd><domain:cd><domain:name avail="1">free-example.gr</domain:name></domain:cd>'
            . '<domain:cd><domain:name avail="0">xy.gr</domain:name></domain:cd></domain:chkData></resData><trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>'));

        $results = (new GrEppRegistrar)->checkAvailabilityMany(['ab.gr', 'free-example.gr', 'xy.gr'], $this->credentials());

        $this->assertSame([true, false, false], [$results['ab.gr']->premium, $results['free-example.gr']->premium, $results['xy.gr']->premium],
            'free and two characters: premium; a taken one is taken');

        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $tld = DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'registrar_connection_id' => $gr->id, 'grace_days' => 15, 'redemption_days' => 0]);
        $domain = Domain::factory()->create(['client_id' => User::factory()->create()->client()->id, 'sld' => 'ab', 'tld' => 'gr', 'fqdn' => 'ab.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $gr->id, 'status' => DomainStatus::Active, 'expires_at' => '2027-01-01',
            'currency_code' => 'EUR', 'years' => 2]);
        $sent = count($this->transport->sent);
        foreach ([fn () => (new GrEppRegistrar)->register($domain, 2, $this->credentials()), fn () => (new GrEppRegistrar)->renew($domain, 2, $this->credentials()),
            fn () => (new GrEppRegistrar)->transferIn($domain, 'Code-1', $this->credentials())] as $deed) {
            $thrown = null;
            try {
                $deed();
            } catch (RegistrarNotAsked $e) {
                $thrown = $e;
            }
            $this->assertStringContainsString('2 characters', (string) $thrown?->getMessage());
        }
        $this->assertCount($sent, $this->transport->sent, 'nothing sent');
    }

    public function test_never_billed_or_ordered_at_the_tlds_price(): void
    {
        // Review 1-3 of #202: the renewal invoice, «renew now» and a transfer order, not only the registry's deeds.
        config(['settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $tld = DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'registrar_connection_id' => $gr->id, 'grace_days' => 15, 'redemption_days' => 0]);
        foreach (['register' => 2, 'renew' => 2, 'transfer' => 1] as $operation => $years) {
            DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR',
                'price' => $operation === 'transfer' ? '0.00' : '19.00']);
        }
        $user = User::factory()->create();
        $make = fn (string $sld, array $more = []) => Domain::factory()->create($more + ['client_id' => $user->client()->id, 'sld' => $sld, 'tld' => 'gr',
            'fqdn' => "{$sld}.gr", 'domain_tld_id' => $tld->id, 'registrar_connection_id' => $gr->id, 'status' => DomainStatus::Active,
            'expires_at' => now()->addDays(10)->toDateString(), 'currency_code' => 'EUR', 'years' => 2, 'auto_renew' => true]);
        $ab = $make('ab');
        $this->assertTrue($ab->staffPriced());
        $this->assertNull($ab->renewalPriceFor(2));
        $this->assertSame('19.00', $make('abc')->renewalPriceFor(2), 'three characters: its TLD\'s price');

        $billed = DB::transaction(fn () => app(RenewalBilling::class)->billDomainRenewal($ab->id, now()->addMonth()));
        $this->assertSame(0, $billed, 'no renewal invoice at the TLD\'s price');
        $this->assertSame(0, InvoiceItem::query()->where('reference_id', $ab->id)->count());

        $thrown = null;
        try {
            app(DomainRenewalService::class)->renewNow($ab, Staff::factory()->create());
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        $this->assertStringContainsString('ab.gr', (string) $thrown?->getMessage());
        $this->assertSame(0, InvoiceItem::query()->where('reference_id', $ab->id)->count(), 'refused before any invoice');

        // Its own price set by staff is billed (done by hand at the registry).
        $ab->forceFill(['recurring_amount' => '400.00'])->save();
        $this->assertSame('400.00', $ab->fresh()->renewalPrice());

        // A taken one is never «transfer it to us», at checkout either (no availability check there).
        $refused = null;
        try {
            app(DomainOffer::class)->check('xy.gr', 'transfer', 1, 'EUR', availability: false);
        } catch (DisplayException $e) {
            $refused = $e->getMessage();
        }
        $this->assertSame(__('domains.shop.premium'), $refused);
    }
}
