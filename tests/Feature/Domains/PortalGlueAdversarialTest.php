<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Show;
use App\Models\ClientUser;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\Registrars\GrEppRegistrar;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of PR #250 (P5: a .gr domain's child nameservers from the portal). Written without the author's
 * context. Failing tests are findings; test_holds_… pin behaviour that held up.
 */
class PortalGlueAdversarialTest extends TestCase
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

    // ---- helpers (as PortalGlueTest) -----------------------------------------------------------------------------

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

    /**
     * @param  list<string>  $hosts
     * @param  list<string>  $statuses
     */
    private function domainInfo(array $hosts, string $clID = 'myip', array $statuses = ['ok']): void
    {
        $this->answer(1000, '<domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>example1.gr</domain:name><domain:roid>r-gr</domain:roid>'
            . implode('', array_map(fn ($s) => "<domain:status s=\"{$s}\"/>", $statuses))
            . '<domain:registrant>c60_x</domain:registrant><domain:ns><domain:hostObj>ns1.example1.gr</domain:hostObj><domain:hostObj>ns.example.com</domain:hostObj></domain:ns>'
            . implode('', array_map(fn ($h) => "<domain:host>{$h}</domain:host>", $hosts))
            . "<domain:clID>{$clID}</domain:clID><domain:crDate>2005-03-01T10:00:00.0Z</domain:crDate><domain:exDate>2029-03-01T23:59:59.0Z</domain:exDate></domain:infData>");
    }

    /** @param  list<string>  $addresses */
    private function hostInfo(string $name, array $addresses, bool $linked, string $clID = 'myip'): void
    {
        $this->answer(1000, '<host:infData xmlns:host="urn:ietf:params:xml:ns:host-1.0"><host:name>' . $name . '</host:name><host:roid>h-gr</host:roid><host:status s="ok"/>'
            . ($linked ? '<host:status s="linked"/>' : '')
            . implode('', array_map(fn ($ip) => '<host:addr ip="' . (str_contains($ip, ':') ? 'v6' : 'v4') . "\">{$ip}</host:addr>", $addresses))
            . "<host:clID>{$clID}</host:clID><host:crID>myip</host:crID><host:crDate>2004-08-17T10:00:00.0Z</host:crDate></host:infData>");
    }

    private function domain(array $attributes = []): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'registrar_connection_id' => $this->gr->id, 'grace_days' => 15, 'redemption_days' => 0]);

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'example1', 'tld' => 'gr', 'fqdn' => 'example1.gr',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->gr->id, 'status' => DomainStatus::Active, 'expires_at' => '2029-03-01',
            'currency_code' => 'EUR', 'years' => 2]);
    }

    /** @param  list<array<string, mixed>>  $hosts */
    private function read(Domain $domain, array $hosts): void
    {
        $domain->forceFill(['meta' => ['child_hosts' => ['read_at' => now()->toIso8601String(), 'hosts' => $hosts]]])->save();
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

    private function lastNotice($page): ?string
    {
        $notices = array_values(array_filter($page->effects['dispatches'] ?? [], fn ($d) => ($d['name'] ?? null) === 'notify'));

        return $notices === [] ? null : ($notices[count($notices) - 1]['params'][0]['message'] ?? null);
    }

    /** «Login as client» as the browser has it (the pattern of GreppG4bAdversarialTest). */
    private function asStaffInLoginAsClient(Staff $staff, Domain $domain): Show
    {
        session(['impersonating' => $this->user->id, CurrentClient::SESSION_KEY => $this->user->client()->id]);
        Auth::guard('staff')->setUser($staff);
        Auth::guard('web')->setUser($this->user);
        $component = app(Show::class);
        $component->domain = $domain;
        $component->mount();

        return $component;
    }

    private function httpStatus(callable $call): ?int
    {
        try {
            $call();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }

        return null;
    }

    // ---- findings ------------------------------------------------------------------------------------------------

    /**
     * F1 (medium): a .gr domain that the registry shows under another registrar (transferred out, not yet synced):
     * «Read» puts the registrar check's own English text on the customer's page, with the other registrar's id.
     * The new RegistrarNotAsked branch in atRegistrar() shows any InvalidArgumentException from prepare() as it is.
     */
    public function test_f1_another_registrars_id_never_reaches_the_customer(): void
    {
        $domain = $this->domain();
        $this->as($this->user);
        $this->loggedIn();
        $this->domainInfo(['ns1.example1.gr'], clID: 'rivalreg77');

        $page = Livewire::test(Show::class, ['domain' => $domain])->call('readChildHosts');

        $message = (string) $this->lastNotice($page);
        $this->assertStringNotContainsString('rivalreg77', $message, 'another registrar\'s id shown to the customer');
        $this->assertStringNotContainsString('at the registry', $message, 'the registrar check\'s English text shown as it is');
    }

    /**
     * F2 (medium, regression outside P5): the same new catch in atRegistrar() is shared by every portal action. On main
     * a nameserver change refused before sending (RegistrarNotAsked) said the general words; now the raw text, with
     * the other registrar's id, reaches the customer.
     */
    public function test_f2_the_nameserver_change_still_says_the_general_words(): void
    {
        $domain = $this->domain();
        $this->as($this->user);
        $this->loggedIn();
        $this->domainInfo(['ns1.example1.gr'], clID: 'rivalreg77');

        $page = Livewire::test(Show::class, ['domain' => $domain])
            ->set('nameservers', [['host' => 'ns1.example.net'], ['host' => 'ns2.example.net']])->call('saveNameservers');

        $this->assertSame(__('domains.portal.registrar_failed'), $this->lastNotice($page));
    }

    /**
     * F3 (low-medium): the registry's statuses (serverUpdateProhibited, pendingTransfer …) and English-only internal
     * text reach the customer: strings to the customer belong in lang/ (CLAUDE.md), el and en.
     */
    public function test_f3_a_blocked_domain_is_said_in_the_customers_words(): void
    {
        app()->setLocale('el');
        $domain = $this->domain();
        $this->read($domain, []);
        $this->as($this->user);
        $this->loggedIn();
        $this->domainInfo([], statuses: ['serverUpdateProhibited', 'pendingTransfer']);

        $page = Livewire::test(Show::class, ['domain' => $domain])
            ->call('startChildHost', '')->set('glueHost', 'ns2')->set('glueAddresses', '84.54.49.7')->call('saveChildHost');

        $message = (string) $this->lastNotice($page);
        $this->assertStringNotContainsString('serverUpdateProhibited', $message);
        $this->assertStringNotContainsString('cannot be changed at the registry now', $message);
        $this->assertSame([], $this->bodies('create'));
    }

    /**
     * F4 (low): a private / reserved / documentation IP passes the portal's own check (FILTER_VALIDATE_IP without the
     * NO_PRIV/NO_RES flags), so the registry is logged into, the domain read, and after the refusal the whole list read
     * again (up to 31 commands), only to say «Not a public IP address: 10.0.0.1» in English as a toast. It belongs on
     * the field, before anything is sent (as «not-an-ip» already is).
     */
    public function test_f4_a_private_ip_is_refused_on_the_field_before_the_registry_is_asked(): void
    {
        $domain = $this->domain();
        $this->read($domain, []);
        $this->as($this->user);

        foreach (['10.0.0.1', '127.0.0.1', '192.0.2.10', 'fe80::1'] as $ip) {
            Livewire::test(Show::class, ['domain' => $domain])
                ->call('startChildHost', '')->set('glueHost', 'ns2')->set('glueAddresses', $ip)->call('saveChildHost')
                ->assertHasErrors('glueAddresses');
        }
        $this->assertSame([], $this->transport->sent);
    }

    /**
     * F5 (low): the page's list is older than the registry (another domain started using the host since): the
     * registry check refuses the delete (good), but in staff's English («… change that domain's nameservers first.»),
     * not from lang/.
     */
    public function test_f5_a_host_linked_since_the_read_is_refused_in_the_customers_words(): void
    {
        app()->setLocale('el');
        $domain = $this->domain();
        $this->read($domain, [['name' => 'old.example1.gr', 'addresses' => ['84.54.49.9'], 'linked' => false, 'read' => true]]);
        $this->as($this->user);
        $this->loggedIn();
        $this->domainInfo(['ns1.example1.gr', 'old.example1.gr']);
        $this->hostInfo('old.example1.gr', ['84.54.49.9'], true);

        $page = Livewire::test(Show::class, ['domain' => $domain])->call('deleteChildHost', 'old.example1.gr');

        $this->assertSame([], $this->bodies('delete'));
        $this->assertStringNotContainsString("change that domain's nameservers first", (string) $this->lastNotice($page));
    }

    /**
     * F6 (low): an array where a method takes a string (a crafted Livewire call: startChildHost / deleteChildHost)
     * ends in a TypeError (a 500, reported), not a refusal. Nothing reaches the registry. (glueHost / glueAddresses set
     * to arrays are refused by Livewire: held.)
     */
    public function test_f6_arrays_for_strings_are_refused_not_an_error(): void
    {
        $domain = $this->domain();
        $this->read($domain, [['name' => 'old.example1.gr', 'addresses' => ['84.54.49.9'], 'linked' => false, 'read' => true]]);
        $this->as($this->user);

        $errors = [];
        foreach ([
            fn () => Livewire::test(Show::class, ['domain' => $domain])->call('startChildHost', ['old.example1.gr']),
            fn () => Livewire::test(Show::class, ['domain' => $domain])->call('deleteChildHost', ['old.example1.gr']),
            fn () => Livewire::test(Show::class, ['domain' => $domain])->call('startChildHost', '')->set('glueAddresses', ['84.54.49.7'])->call('saveChildHost'),
            fn () => Livewire::test(Show::class, ['domain' => $domain])->call('startChildHost', '')->set('glueHost', ['ns2'])->call('saveChildHost'),
        ] as $i => $call) {
            try {
                $call();
            } catch (Throwable $e) {
                $errors[] = "#{$i}: " . $e::class;
            }
        }
        $this->assertSame([], $this->transport->sent);
        $this->assertSame([], $errors);
    }

    // ---- held ----------------------------------------------------------------------------------------------------

    /** Names the page never showed, the domain itself, names outside it, crafted ones: never a host command. */
    public function test_holds_crafted_names_never_reach_a_host_command(): void
    {
        $domain = $this->domain();
        $poisoned = ['ns1.other.gr', 'example1.gr', "ns1.example1.gr\n<host:name>x.other.gr</host:name>", 'ns1.example1.gr.other.gr', '.example1.gr', '*.example1.gr'];
        $this->read($domain, array_map(fn ($n) => ['name' => $n, 'addresses' => ['84.54.49.9'], 'linked' => false, 'read' => true], $poisoned));
        $this->as($this->user);

        foreach ($poisoned as $name) {
            $this->loggedIn();
            $page = Livewire::test(Show::class, ['domain' => $domain]);
            $page->call('deleteChildHost', $name);
            $this->loggedIn();
            $page->call('startChildHost', $name)->set('glueAddresses', '84.54.49.7')->call('saveChildHost');
        }
        // New ones: the domain itself, outside it, a suffix trick.
        foreach (['example1.gr', 'ns1.other.gr', 'ns1.example1.gr.evil.gr', 'xexample1.gr', 'ns1.evilexample1.gr'] as $host) {
            Livewire::test(Show::class, ['domain' => $domain])
                ->call('startChildHost', '')->set('glueHost', $host)->set('glueAddresses', '84.54.49.7')->call('saveChildHost')
                ->assertHasErrors('glueHost');
        }
        $this->assertSame([], $this->bodies('create'));
        $this->assertSame([], $this->bodies('update'));
        $this->assertSame([], $this->bodies('delete'));
    }

    /** A host the last read showed as in use (or not read): no delete. */
    public function test_holds_a_linked_or_unread_host_is_never_deleted(): void
    {
        $domain = $this->domain();
        $this->read($domain, [
            ['name' => 'ns1.example1.gr', 'addresses' => ['84.54.49.6'], 'linked' => true, 'read' => true],
            ['name' => 'far.example1.gr', 'addresses' => [], 'linked' => true, 'read' => false],
            ['name' => 'nolink.example1.gr', 'addresses' => []],
        ]);
        $this->as($this->user);
        $page = Livewire::test(Show::class, ['domain' => $domain]);
        $page->call('deleteChildHost', 'ns1.example1.gr')->call('deleteChildHost', 'far.example1.gr')->call('deleteChildHost', 'nolink.example1.gr');

        $this->assertSame([], $this->transport->sent);
    }

    /** A member without managedomains, a revoked grant, another account's domain: no form, nothing sent. */
    public function test_holds_without_permission_nothing_is_sent(): void
    {
        $domain = $this->domain();
        $this->read($domain, [['name' => 'old.example1.gr', 'addresses' => ['84.54.49.9'], 'linked' => false, 'read' => true]]);

        // Revoked: the owner's own grant to the account.
        $member = User::factory()->withoutClient()->create();
        $grant = ClientUser::query()->create(['client_id' => $this->user->client()->id, 'user_id' => $member->id, 'permissions' => ['domains', 'managedomains'], 'granted_at' => now()]);
        $this->as($member);
        $page = Livewire::test(Show::class, ['domain' => $domain])->call('startChildHost', '')->assertSet('glueEditing', '');
        $grant->forceFill(['revoked_at' => now()])->save();
        $page->set('glueHost', 'ns2')->set('glueAddresses', '84.54.49.7')->call('saveChildHost')->assertStatus(404);
        Livewire::test(Show::class, ['domain' => $domain])->call('deleteChildHost', 'old.example1.gr')->assertStatus(404);

        // Another account's domain, by id.
        $other = User::factory()->create();
        $this->actingAs($other)->withSession($this->loginUser($other, [CurrentClient::SESSION_KEY => $other->client()->id]));
        Livewire::test(Show::class, ['domain' => $domain])->call('readChildHosts')->assertStatus(404);
        Livewire::test(Show::class, ['domain' => $domain])->call('startChildHost', '')->assertStatus(404);
        Livewire::test(Show::class, ['domain' => $domain])->call('deleteChildHost', 'old.example1.gr')->assertStatus(404);

        $this->assertSame([], $this->transport->sent);
    }

    /** Staff in «Login as client» without admin.domains.manage: 404 on every action, nothing sent. */
    public function test_holds_staff_without_domains_manage_in_login_as_client(): void
    {
        $domain = $this->domain();
        $this->read($domain, [['name' => 'old.example1.gr', 'addresses' => ['84.54.49.9'], 'linked' => false, 'read' => true]]);
        $staff = Staff::factory()->withPermissions(['admin.users.impersonate'])->create();

        $this->assertSame(404, $this->httpStatus(fn () => $this->asStaffInLoginAsClient($staff, $domain)->readChildHosts()));
        $this->assertSame(404, $this->httpStatus(fn () => $this->asStaffInLoginAsClient($staff, $domain)->startChildHost('')));
        $this->assertSame(404, $this->httpStatus(fn () => $this->asStaffInLoginAsClient($staff, $domain)->deleteChildHost('old.example1.gr')));
        $this->assertSame([], $this->transport->sent);
    }

    /** A domain transferred away / ended, or one at Openprovider: no registry call. */
    public function test_holds_a_terminal_or_openprovider_domain_sends_nothing(): void
    {
        $this->as($this->user);
        $ended = $this->domain(['status' => DomainStatus::TransferredAway]);
        $this->read($ended, [['name' => 'old.example1.gr', 'addresses' => ['84.54.49.9'], 'linked' => false, 'read' => true]]);
        Livewire::test(Show::class, ['domain' => $ended])->call('readChildHosts')->assertStatus(404);
        Livewire::test(Show::class, ['domain' => $ended])->call('deleteChildHost', 'old.example1.gr')->assertStatus(404);

        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $atOp = $this->domain(['sld' => 'example2', 'fqdn' => 'example2.gr', 'registrar_connection_id' => $op->id]);
        $this->read($atOp, [['name' => 'old.example2.gr', 'addresses' => ['84.54.49.9'], 'linked' => false, 'read' => true]]);
        Livewire::test(Show::class, ['domain' => $atOp])->assertDontSee(__('domains.portal.glue_read_now'))
            ->call('readChildHosts')->call('deleteChildHost', 'old.example2.gr')
            ->call('startChildHost', '')->set('glueHost', 'ns2')->set('glueAddresses', '84.54.49.7')->call('saveChildHost');

        $this->assertSame([], $this->transport->sent);
    }

    /** At the portal's limit: no registry call, a new component or not. */
    public function test_holds_the_rate_limit(): void
    {
        $domain = $this->domain();
        $this->read($domain, [['name' => 'old.example1.gr', 'addresses' => ['84.54.49.9'], 'linked' => false, 'read' => true]]);
        $this->as($this->user);
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('domain-portal:' . $this->user->id, 60);
        }

        Livewire::test(Show::class, ['domain' => $domain])->call('readChildHosts');
        Livewire::test(Show::class, ['domain' => $domain])->call('deleteChildHost', 'old.example1.gr');
        $page = Livewire::test(Show::class, ['domain' => $domain])
            ->call('startChildHost', '')->set('glueHost', 'ns2')->set('glueAddresses', '84.54.49.7')->call('saveChildHost');

        $this->assertSame(__('domains.portal.too_many'), $this->lastNotice($page));
        $this->assertSame([], $this->transport->sent);
    }

    /** A change of another registrar's host left under our domain: refused before the update. */
    public function test_holds_another_registrars_host_is_never_updated(): void
    {
        $domain = $this->domain();
        $this->read($domain, [['name' => 'ns1.example1.gr', 'addresses' => ['84.54.49.6'], 'linked' => true, 'read' => true]]);
        $this->as($this->user);
        $this->loggedIn();
        $this->domainInfo(['ns1.example1.gr']);
        $this->hostInfo('ns1.example1.gr', ['84.54.49.6'], true, clID: 'rivalreg77');

        Livewire::test(Show::class, ['domain' => $domain])
            ->call('startChildHost', 'ns1.example1.gr')->set('glueHost', 'ns1.other.gr')->set('glueAddresses', '84.54.49.8')->call('saveChildHost');

        $this->assertSame([], $this->bodies('update'));
    }
}
