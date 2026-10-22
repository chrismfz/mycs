<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceHostedDomain;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Servers\CPanel\CPanel;
use Tests\TestCase;

/**
 * The domains an account hosts (owner 2026-10-22: «what else it hosts», for the customer and staff's search): one
 * get_domain_info a server, with the usage; a reseller's accounts' too; found by staff's global search.
 */
class HostedDomainsTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private User $user;

    /** @var list<array<string, string>> get_domain_info */
    private array $domains = [];

    private bool $domainsDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        $this->server = Server::query()->create(['name' => 'Orion', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $this->server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->user = User::factory()->create();
        Http::fake(function (Request $request) {
            $call = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($call, '/get_domain_info')) {
                return $this->domainsDown ? Http::response([], 503) : Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['domains' => $this->domains]]);
            }

            return Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['acct' => array_map(fn ($u) => ['user' => $u, 'owner' => 'root', 'diskused' => '1M', 'disklimit' => '10M'],
                array_values(array_unique(array_column($this->domains, 'user'))))]]);
        });
    }

    private function service(string $username, array $productSettings = [], array $properties = []): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);
        foreach (['package' => 'Business20'] + $productSettings as $key => $value) {
            $product->product->settings()->create(['key' => $key, 'value' => $value]);
        }
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $this->server->id, 'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10.00,
            'domain' => "{$username}.test", 'username' => $username]);
        foreach (['cpanel_username' => $username] + $properties as $key => $value) {
            $service->properties()->create(['key' => $key, 'name' => $key, 'value' => $value]);
        }

        return $service;
    }

    private static function row(string $domain, string $type, string $user, string $owner = 'root'): array
    {
        return ['domain' => $domain, 'domain_type' => $type, 'user' => $user, 'user_owner' => $owner, 'parent_domain' => $domain];
    }

    private function hosted(Service $service): array
    {
        return $service->hostedDomains()->get()->map(fn (ServiceHostedDomain $d) => "{$d->domain} {$d->type} {$d->account}")->all();
    }

    public function test_an_accounts_main_addon_alias_and_subdomains_are_read(): void
    {
        $service = $this->service('zonecl');
        $this->domains = [self::row('zonecloud.io', 'main', 'zonecl'), self::row('shop.example', 'addon', 'zonecl'),
            self::row('zonecloud.gr', 'parked', 'zonecl'), self::row('blog.zonecloud.io', 'sub', 'zonecl'), self::row('other.test', 'main', 'other')];

        $this->artisan('services:usage')->assertExitCode(0);

        $this->assertSame(['zonecloud.io main zonecl', 'shop.example addon zonecl', 'zonecloud.gr parked zonecl', 'blog.zonecloud.io sub zonecl'], $this->hosted($service));
        Http::assertSentCount(3); // listaccts, showbw, get_domain_info: one each a server
    }

    public function test_a_resellers_are_its_own_and_its_accounts(): void
    {
        $service = $this->service('resel1', ['reseller' => '1'], [CPanel::RESELLER => '1']);
        $this->domains = [self::row('resel1.test', 'main', 'resel1', 'resel1'), self::row('cust-a.gr', 'main', 'cust1', 'RESEL1'),
            self::row('cust-a.com', 'parked', 'cust1', 'resel1'), self::row('else.gr', 'main', 'other', 'root')];

        $this->artisan('services:usage')->assertExitCode(0);

        $this->assertEqualsCanonicalizing(['resel1.test main resel1', 'cust-a.gr main cust1', 'cust-a.com parked cust1'], $this->hosted($service));
    }

    public function test_a_domain_gone_at_the_server_goes_here_and_a_server_that_fails_keeps_them(): void
    {
        $service = $this->service('zonecl');
        $this->domains = [self::row('zonecloud.io', 'main', 'zonecl'), self::row('shop.example', 'addon', 'zonecl')];
        $this->artisan('services:usage')->assertExitCode(0);

        $this->domains = [self::row('zonecloud.io', 'main', 'zonecl')];
        $this->artisan('services:usage')->assertExitCode(0);
        $this->assertSame(['zonecloud.io main zonecl'], $this->hosted($service));

        $this->domainsDown = true;
        $this->artisan('services:usage')->assertExitCode(1);
        $this->assertSame(['zonecloud.io main zonecl'], $this->hosted($service), 'kept as last read');
        $this->assertNotNull($service->usage()->first(), 'the usage read all the same');
    }

    public function test_the_customer_sees_them(): void
    {
        $service = $this->service('zonecl');
        $this->domains = [self::row('zonecloud.io', 'main', 'zonecl'), self::row('zonecloud.gr', 'parked', 'zonecl'), self::row('blog.zonecloud.io', 'sub', 'zonecl')];
        $this->artisan('services:usage')->assertExitCode(0);

        $this->actingAs($this->user)->withSession($this->loginUser($this->user, [CurrentClient::SESSION_KEY => $service->client_id]))
            ->get(route('services.show', $service))->assertOk()
            ->assertSee(__('services.hosted.title'))->assertSee('zonecloud.gr')->assertSee(__('services.hosted.types.parked'))
            ->assertSee(__('services.hosted.subdomains', ['count' => 1]));
    }

    public function test_staffs_search_finds_the_service_by_any_domain_it_hosts(): void
    {
        $service = $this->service('zonecl');
        $this->domains = [self::row('zonecloud.io', 'main', 'zonecl'), self::row('shop.example', 'addon', 'zonecl')];
        $this->artisan('services:usage')->assertExitCode(0);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]), 'staff');

        $results = ServiceResource::getGlobalSearchResults('shop.example');

        $this->assertSame([$service->id], $results->map(fn ($r) => (int) basename(dirname($r->url)))->unique()->values()->all());
        $this->assertSame('zonecl', $results->first()->details['Account'] ?? null);
        $this->assertSame('Orion', $results->first()->details['Server'] ?? null);
    }
}
