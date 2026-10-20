<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Show;
use App\Models\ClientUser;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\User;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\RegistrarOutcomeUnknown;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * P5 (owner 2026-10-20): a .gr domain's child nameservers (glue) from the portal, as staff have had them since #196:
 * read when asked, made, their IPs changed, an unused one deleted; within the customer's grant.
 */
class PortalGlueTest extends TestCase
{
    use RefreshDatabase;

    private FakeTransport $transport;

    private DomainRegistrarConnection $gr;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $this->gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
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

    private function answer(int $code, string $resData = ''): void
    {
        $this->transport->queue(new HttpResponse(200, '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
            . "<result code=\"{$code}\"><msg>m</msg></result>" . ($resData !== '' ? "<resData>{$resData}</resData>" : '')
            . '<trID><clTRID>ECHO</clTRID><svTRID>s</svTRID></trID></response></epp>'));
    }

    private function loggedIn(): void
    {
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
    }

    /** @param  list<string>  $hosts */
    private function domainInfo(array $hosts): void
    {
        $this->answer(1000, '<domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>example1.gr</domain:name><domain:roid>r-gr</domain:roid>'
            . '<domain:status s="ok"/><domain:registrant>c60_x</domain:registrant><domain:ns><domain:hostObj>ns1.example1.gr</domain:hostObj><domain:hostObj>ns.example.com</domain:hostObj></domain:ns>'
            . implode('', array_map(fn ($h) => "<domain:host>{$h}</domain:host>", $hosts))
            . '<domain:clID>myip</domain:clID><domain:crDate>2005-03-01T10:00:00.0Z</domain:crDate><domain:exDate>2029-03-01T23:59:59.0Z</domain:exDate></domain:infData>');
    }

    /** @param  list<string>  $addresses */
    private function hostInfo(string $name, array $addresses, bool $linked): void
    {
        $this->answer(1000, '<host:infData xmlns:host="urn:ietf:params:xml:ns:host-1.0"><host:name>' . $name . '</host:name><host:roid>h-gr</host:roid><host:status s="ok"/>'
            . ($linked ? '<host:status s="linked"/>' : '')
            . implode('', array_map(fn ($ip) => '<host:addr ip="' . (str_contains($ip, ':') ? 'v6' : 'v4') . "\">{$ip}</host:addr>", $addresses))
            . '<host:clID>myip</host:clID><host:crID>myip</host:crID><host:crDate>2004-08-17T10:00:00.0Z</host:crDate></host:infData>');
    }

    private function domain(): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);

        return Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'example1', 'tld' => 'gr', 'fqdn' => 'example1.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::Active, 'expires_at' => '2029-03-01',
            'currency_code' => 'EUR', 'years' => 2]);
    }

    private function as(User $user): void
    {
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $this->user->client()->id]));
    }

    /** @return list<string> */
    private function bodies(string $kind): array
    {
        return array_values(array_filter(array_column($this->transport->sent, 'body'), fn (string $b) => str_contains($b, "<host:{$kind}")));
    }

    public function test_the_customer_reads_adds_changes_and_deletes_their_child_nameservers(): void
    {
        $domain = $this->domain();
        $this->as($this->user);
        $this->loggedIn();
        $this->domainInfo(['ns1.example1.gr', 'old.example1.gr']);
        $this->hostInfo('ns1.example1.gr', ['84.54.49.6'], true);
        $this->hostInfo('old.example1.gr', ['192.0.2.10'], false);

        $page = Livewire::test(Show::class, ['domain' => $domain])
            ->assertSee(__('domains.portal.glue'))
            ->call('readChildHosts')->assertDispatched('notify')
            ->assertSee('ns1.example1.gr')->assertSee('84.54.49.6')->assertSee('old.example1.gr');

        // Add ns2 (each request its own session: hello, login), then the list read again.
        $this->loggedIn();
        $this->domainInfo(['ns1.example1.gr', 'old.example1.gr']);
        $this->answer(1000); // host:create, then read again in the same session
        $this->domainInfo(['ns1.example1.gr', 'ns2.example1.gr', 'old.example1.gr']);
        $this->hostInfo('ns1.example1.gr', ['84.54.49.6'], true);
        $this->hostInfo('ns2.example1.gr', ['84.54.49.7'], false);
        $this->hostInfo('old.example1.gr', ['192.0.2.10'], false);
        $page->call('startChildHost', '')->set('glueHost', 'ns2')->set('glueAddresses', '84.54.49.7')->call('saveChildHost')
            ->assertHasNoErrors()->assertSet('glueEditing', null);
        $this->assertStringContainsString('<host:name>ns2.example1.gr</host:name>', $this->bodies('create')[0]);

        // Delete the unused one.
        $this->loggedIn();
        $this->domainInfo(['ns1.example1.gr', 'ns2.example1.gr', 'old.example1.gr']);
        $this->hostInfo('old.example1.gr', ['192.0.2.10'], false);
        $this->answer(1000); // host:delete, then read again in the same session
        $this->domainInfo(['ns1.example1.gr', 'ns2.example1.gr']);
        $this->hostInfo('ns1.example1.gr', ['84.54.49.6'], true);
        $this->hostInfo('ns2.example1.gr', ['84.54.49.7'], false);
        $page->call('deleteChildHost', 'old.example1.gr');
        $this->assertStringContainsString('old.example1.gr', $this->bodies('delete')[0] ?? '');
        $this->assertSame(['ns1.example1.gr', 'ns2.example1.gr'], array_column($domain->fresh()->meta['child_hosts']['hosts'], 'name'));
    }

    public function test_a_bad_ip_or_a_linked_or_unknown_host_is_refused_before_anything_is_sent(): void
    {
        $domain = $this->domain();
        $meta = ['child_hosts' => ['read_at' => now()->toIso8601String(), 'hosts' => [
            ['name' => 'ns1.example1.gr', 'addresses' => ['84.54.49.6'], 'linked' => true, 'read' => true],
        ]]];
        $domain->forceFill(['meta' => $meta])->save();
        $this->as($this->user);

        $page = Livewire::test(Show::class, ['domain' => $domain])
            ->call('startChildHost', '')->set('glueHost', 'ns2')->set('glueAddresses', 'not-an-ip')->call('saveChildHost')
            ->assertHasErrors('glueAddresses');
        $page->call('deleteChildHost', 'ns1.example1.gr'); // linked: not offered, not sent
        $page->call('startChildHost', 'ns9.example1.gr')->assertSet('glueEditing', null); // never read: no form

        $this->assertSame([], $this->transport->sent);
    }

    public function test_the_form_cannot_be_pointed_at_another_host_from_the_browser(): void
    {
        $domain = $this->domain();
        $this->as($this->user);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Show::class, ['domain' => $domain])->set('glueEditing', 'ns1.other.gr');
    }

    public function test_a_member_without_managedomains_neither_sees_nor_changes_them(): void
    {
        $domain = $this->domain();
        $member = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $this->user->client()->id, 'user_id' => $member->id, 'permissions' => ['domains'], 'granted_at' => now()]);
        $this->as($member);

        Livewire::test(Show::class, ['domain' => $domain])->assertDontSee(__('domains.portal.glue'))
            ->call('readChildHosts')->assertStatus(404);
        $this->assertSame([], $this->transport->sent);
    }

    public function test_a_name_outside_the_domain_is_said_a_connection_failure_is_not_shown_raw(): void
    {
        $domain = $this->domain();
        $domain->forceFill(['meta' => ['child_hosts' => ['read_at' => now()->toIso8601String(), 'hosts' => []]]])->save();
        $this->as($this->user);
        $page = Livewire::test(Show::class, ['domain' => $domain]);

        // Ours: said on the field (nothing sent, not even a login).
        $page->call('startChildHost', '')->set('glueHost', 'ns1.other.gr')->set('glueAddresses', '84.54.49.6')->call('saveChildHost')
            ->assertHasErrors('glueHost');
        $this->assertSame([], $this->transport->sent);

        // The registry not answering: the general words, never its text.
        $this->transport->queue(new HttpResponse(200, 'not epp'));
        $page->call('startChildHost', '')->set('glueHost', 'ns2')->set('glueAddresses', '84.54.49.7')->call('saveChildHost');
        $this->assertSame(__('domains.portal.registrar_failed'), $page->effects['dispatches'][0]['params'][0]['message'] ?? null);
    }

    /** The real service (what the page shows), its glue calls answered here. */
    private function service(\Closure $expect): void
    {
        $mock = \Mockery::mock(DomainManagementService::class, [app(DomainRegistrarFactory::class),
            app(DomainSyncService::class), app(DomainLifecycle::class)])->makePartial();
        $mock->shouldReceive('managesChildHosts')->andReturnTrue();
        $expect($mock);
        $this->instance(DomainManagementService::class, $mock);
    }

    public function test_a_greek_name_is_sent_in_punycode_and_one_not_read_is_never_edited(): void
    {
        // Reviews of #250: an IDN host under an IDN domain, checked and sent as the registry knows it.
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);
        $fqdn = (string) idn_to_ascii('παράδειγμα.gr', IDNA_NONTRANSITIONAL_TO_ASCII);
        $domain = Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => explode('.', $fqdn)[0], 'tld' => 'gr', 'fqdn' => $fqdn,
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::Active, 'expires_at' => '2029-03-01',
            'currency_code' => 'EUR', 'years' => 2]);
        $domain->forceFill(['meta' => ['child_hosts' => ['read_at' => now()->toIso8601String(), 'hosts' => [
            ['name' => 'ns5.' . $fqdn, 'addresses' => [], 'linked' => false, 'read' => false],
        ]]]])->save();
        $this->as($this->user);
        $sent = null;
        $this->service(function ($mock) use (&$sent) {
            $mock->shouldReceive('createChildHost')->once()->andReturnUsing(function ($domain, string $host) use (&$sent) {
                $sent = $host;
            });
            $mock->shouldReceive('readChildHosts')->andReturn([]);
        });

        $page = Livewire::test(Show::class, ['domain' => $domain])
            ->call('startChildHost', 'ns5.' . $fqdn)->assertSet('glueEditing', null) // listed, its details never read: no form
            ->call('startChildHost', '')->set('glueHost', 'NS1.Παράδειγμα.gr.')->set('glueAddresses', '84.54.49.7')->call('saveChildHost')
            ->assertHasNoErrors();

        $this->assertSame('ns1.' . $fqdn, $sent);
        $page->call('startChildHost', '')->set('glueHost', 'ns1.-bad-.gr')->set('glueAddresses', '84.54.49.7')->call('saveChildHost')->assertHasErrors('glueHost');
    }

    public function test_an_answer_never_received_is_said_as_maybe_done(): void
    {
        $domain = $this->domain();
        $domain->forceFill(['meta' => ['child_hosts' => ['read_at' => now()->toIso8601String(), 'hosts' => []]]])->save();
        $this->as($this->user);
        $this->service(function ($mock) {
            $mock->shouldReceive('createChildHost')->andThrow(new RegistrarOutcomeUnknown('timed out after host:create sent to epp.example.test'));
        });

        $page = Livewire::test(Show::class, ['domain' => $domain])
            ->call('startChildHost', '')->set('glueHost', 'ns2')->set('glueAddresses', '84.54.49.7')->call('saveChildHost');

        $this->assertSame(__('domains.portal.glue_maybe'), $page->effects['dispatches'][0]['params'][0]['message'] ?? null);
    }
}
