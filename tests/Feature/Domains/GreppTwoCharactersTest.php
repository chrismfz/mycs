<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\User;
use App\Services\Domains\RegistrarNotAsked;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
}
