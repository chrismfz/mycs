<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Show;
use App\Models\ClientUser;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\User;
use App\Services\Domains\Registrars\GrEppRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * P3 of the portal: a customer's DNSSEC at its registrar, as staff's (G8-4b): read when asked, changed from that read
 * (the registrar having another set now refuses it), the registry's rules checked, its outcome in the customer's words.
 */
class PortalDnssecTest extends TestCase
{
    use RefreshDatabase;

    private const DIGEST = '2BB183AF5F22588179A53B0A98631FAD1A292118F8D0CFE5F1C2C2F7C1D3A9B4';

    private FakeTransport $transport;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
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

    private function domain(): Domain
    {
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        $tld = DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'registrar_connection_id' => $gr->id, 'grace_days' => 15, 'redemption_days' => 0]);

        return Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'cdnet', 'tld' => 'gr', 'fqdn' => 'cdnet.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $gr->id, 'status' => DomainStatus::Active]);
    }

    private function info(array $ds = []): string
    {
        $records = implode('', array_map(fn (array $d) => "<secDNS:dsData><secDNS:keyTag>{$d[0]}</secDNS:keyTag><secDNS:alg>{$d[1]}</secDNS:alg>"
            . "<secDNS:digestType>{$d[2]}</secDNS:digestType><secDNS:digest>{$d[3]}</secDNS:digest></secDNS:dsData>", $ds));

        return '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
            . '<resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>cdnet.gr</domain:name><domain:roid>d1-gr</domain:roid>'
            . '<domain:status s="ok"/><domain:ns><domain:hostObj>ns1.myip.gr</domain:hostObj></domain:ns><domain:clID>myip</domain:clID>'
            . '<domain:exDate>2028-08-16T23:59:59.9Z</domain:exDate></domain:infData></resData>'
            . ($records !== '' ? "<extension><secDNS:infData xmlns:secDNS=\"urn:ietf:params:xml:ns:secDNS-1.1\">{$records}</secDNS:infData></extension>" : '')
            . '<trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>';
    }

    private function registry(string ...$answers): void
    {
        GrEppRegistrar::reset();
        $this->transport = new FakeTransport;
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
        foreach ($answers as $answer) {
            $this->transport->queue(new HttpResponse(200, $answer));
        }
    }

    private function updates(): array
    {
        return array_values(array_filter(array_map(fn (array $r) => $r['body'], $this->transport->sent), fn (string $b) => str_contains($b, 'secDNS:update')));
    }

    public function test_a_customer_reads_and_signs_its_domain(): void
    {
        $domain = $this->domain();
        $pending = '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1001"><msg>pending</msg></result>'
            . '<trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>';

        $this->registry($this->info());
        $page = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])
            ->assertSee(__('domains.portal.dnssec_not_read'))
            ->call('readDnssec')->assertSee(__('domains.portal.dnssec_unsigned'));

        $this->registry($this->info(), $pending, $this->info([[2371, 13, 2, self::DIGEST]]));
        $page->call('startDnssec')->call('addDnssecRow')
            ->set('dnssecRows.0', ['key_tag' => '2371', 'algorithm' => '13', 'digest_type' => '2', 'digest' => strtolower(self::DIGEST)])
            ->call('saveDnssec')->assertHasNoErrors()->assertSet('editDnssec', false)
            ->assertSee('DS 2371 13 2 ' . self::DIGEST);
        $this->assertCount(1, $this->updates());
        $log = DomainRegistrarLog::query()->where('action', 'dnssec_update')->latest('id')->first();
        $this->assertSame([$this->user->id, null], [$log->user_id, $log->staff_id]);
    }

    public function test_never_what_the_customer_did_not_see_nor_what_the_registry_refuses(): void
    {
        $domain = $this->domain();
        $this->registry($this->info([[2371, 13, 2, self::DIGEST]]));
        $page = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('readDnssec')->call('startDnssec');

        // Another DS added at the registry since: the change is refused, nothing sent.
        $this->registry($this->info([[2371, 13, 2, self::DIGEST], [4444, 13, 2, str_repeat('C', 64)]]), $this->info());
        $page->call('removeDnssecRow', 0)->call('saveDnssec')->assertDispatched('notify', ['message' => __('domains.portal.dnssec_changed'), 'type' => 'error']);
        $this->assertSame([], $this->updates());

        // An algorithm the registry does not take, a digest not hex: refused by the form or the rules, nothing sent.
        $this->registry($this->info(), $this->info());
        $page = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain->fresh()])->call('readDnssec')->call('startDnssec')
            ->call('addDnssecRow')->set('dnssecRows.0', ['key_tag' => '1', 'algorithm' => '13', 'digest_type' => '2', 'digest' => str_repeat('G', 64)])
            ->call('saveDnssec')->assertHasErrors('dnssecRows.0.digest');
        $this->registry($this->info(), $this->info());
        $page->set('dnssecRows.0', ['key_tag' => '1', 'algorithm' => '15', 'digest_type' => '2', 'digest' => self::DIGEST])
            ->call('saveDnssec')->assertHasErrors('dnssecRows.0.algorithm'); // not the .gr registry's: the form's own list (ADV-4 of #223)
        $this->assertSame([], $this->updates());
    }

    public function test_only_who_manages_domains(): void
    {
        $domain = $this->domain();
        $member = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->user->client()->id, 'user_id' => $member->id, 'granted_at' => now(), 'permissions' => ['domains']]);

        Livewire::actingAs($member)->test(Show::class, ['domain' => $domain])->assertDontSee('DNSSEC')
            ->call('readDnssec')->assertStatus(404);
        $this->assertSame([], $this->transport->sent);
    }
}
