<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceUsage;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Servers\CPanel\CPanel;
use Tests\TestCase;

/**
 * Usage (owner 2026-10-22: WHMCS's «Usage Statistics», plain accounts and resellers): read from WHM in two calls a
 * server (listaccts, showbw), kept with the time it was read, shown to the customer and staff.
 */
class ServiceUsageTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private User $user;

    /** @var list<array<string, string>> listaccts */
    private array $accounts = [];

    /** @var list<array<string, string>> showbw */
    private array $bandwidth = [];

    private bool $down = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        $this->server = Server::query()->create(['name' => 'Virgo', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $this->server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->user = User::factory()->create();
        Http::fake(function (Request $request) {
            if ($this->down) {
                return Http::response([], 503);
            }
            $call = (string) parse_url($request->url(), PHP_URL_PATH);

            return Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['acct' => str_ends_with($call, '/showbw') ? $this->bandwidth : $this->accounts]]);
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
            'server_id' => $this->server->id, 'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10.00, 'domain' => "{$username}.test"]);
        foreach (['cpanel_username' => $username] + $properties as $key => $value) {
            $service->properties()->create(['key' => $key, 'name' => $key, 'value' => $value]);
        }

        return $service;
    }

    public function test_a_plain_accounts_disk_and_bandwidth_are_read_with_their_limits(): void
    {
        $service = $this->service('zonecl');
        $this->accounts = [['user' => 'zonecl', 'owner' => 'root', 'diskused' => '793M', 'disklimit' => '20480M']];
        $this->bandwidth = [['user' => 'zonecl', 'totalbytes' => (string) (513 * 1048576), 'limit' => (string) (409600 * 1048576)]];

        $this->artisan('services:usage')->assertExitCode(0);

        $usage = $service->usage()->sole();
        $this->assertSame([793, 20480, 513, 409600, null], [$usage->disk_used_mb, $usage->disk_limit_mb, $usage->bandwidth_used_mb, $usage->bandwidth_limit_mb, $usage->accounts]);
        $this->assertNotNull($usage->read_at);
    }

    public function test_unlimited_is_no_limit_and_an_idle_account_uses_zero(): void
    {
        $service = $this->service('idle1');
        $this->accounts = [['user' => 'idle1', 'owner' => 'root', 'diskused' => '0M', 'disklimit' => 'unlimited']];
        $this->bandwidth = [['user' => 'idle1', 'totalbytes' => '0', 'limit' => 'unlimited']];

        $this->artisan('services:usage')->assertExitCode(0);

        $usage = $service->usage()->sole();
        $this->assertSame([0, null, 0, null], [$usage->disk_used_mb, $usage->disk_limit_mb, $usage->bandwidth_used_mb, $usage->bandwidth_limit_mb]);
    }

    public function test_a_resellers_usage_is_its_accounts_with_its_products_limits(): void
    {
        $service = $this->service('resel1', ['reseller' => '1', 'reseller_disk_mb' => '70000', 'reseller_bandwidth_mb' => '4000000', 'reseller_max_accounts' => '50'],
            [CPanel::RESELLER => '1']);
        $this->accounts = [['user' => 'resel1', 'owner' => 'resel1', 'diskused' => '100M', 'disklimit' => 'unlimited'],
            ['user' => 'cust1', 'owner' => 'resel1', 'diskused' => '2G', 'disklimit' => '5G'],
            ['user' => 'cust2', 'owner' => 'RESEL1', 'diskused' => '300M', 'disklimit' => '5G'],
            ['user' => 'other', 'owner' => 'root', 'diskused' => '9G', 'disklimit' => '10G']];
        $this->bandwidth = [['user' => 'cust1', 'totalbytes' => (string) (1000 * 1048576), 'limit' => '0'],
            ['user' => 'other', 'totalbytes' => (string) (5000 * 1048576), 'limit' => '0']];

        $this->artisan('services:usage')->assertExitCode(0);

        $usage = $service->usage()->sole();
        $this->assertSame([2448, 70000, 1000, 4000000, 2, 50], [$usage->disk_used_mb, $usage->disk_limit_mb, $usage->bandwidth_used_mb,
            $usage->bandwidth_limit_mb, $usage->accounts, $usage->accounts_limit]);
    }

    public function test_two_calls_a_server_whatever_its_number_of_services(): void
    {
        foreach (['a1', 'a2', 'a3'] as $user) {
            $this->service($user);
            $this->accounts[] = ['user' => $user, 'owner' => 'root', 'diskused' => '1M', 'disklimit' => '10M'];
        }

        $this->artisan('services:usage')->assertExitCode(0);

        Http::assertSentCount(2);
        $this->assertSame(3, ServiceUsage::query()->count());
    }

    public function test_a_server_that_fails_keeps_what_was_last_read(): void
    {
        $service = $this->service('zonecl');
        $this->accounts = [['user' => 'zonecl', 'owner' => 'root', 'diskused' => '793M', 'disklimit' => '20480M']];
        $this->artisan('services:usage')->assertExitCode(0);
        $before = $service->usage()->sole()->read_at;

        $this->travel(4)->hours();
        $this->down = true;
        $this->artisan('services:usage')->assertExitCode(0);

        $this->assertEquals($before, $service->usage()->sole()->read_at, 'what was last read, with its time');
    }

    public function test_an_account_not_on_its_server_is_left_out(): void
    {
        $service = $this->service('gone1');
        $this->accounts = [['user' => 'other', 'owner' => 'root', 'diskused' => '1M', 'disklimit' => '10M']];

        $this->artisan('services:usage')->assertExitCode(0);

        $this->assertNull($service->usage()->first());
    }

    public function test_the_customer_sees_it_with_when_it_was_read(): void
    {
        $service = $this->service('zonecl');
        $this->accounts = [['user' => 'zonecl', 'owner' => 'root', 'diskused' => '793M', 'disklimit' => '20480M']];
        $this->bandwidth = [['user' => 'zonecl', 'totalbytes' => (string) (513 * 1048576), 'limit' => (string) (409600 * 1048576)]];
        $this->artisan('services:usage')->assertExitCode(0);

        $this->actingAs($this->user)->withSession($this->loginUser($this->user, [CurrentClient::SESSION_KEY => $service->client_id]))
            ->get(route('services.show', $service))->assertOk()
            ->assertSee(__('services.usage.title'))->assertSee('793 MB')->assertSee('20 GB')->assertSee('400 GB')
            ->assertSee(__('services.usage.read_at', ['when' => $service->usage->read_at->format('d/m/Y H:i')]));
    }

    public function test_nothing_is_shown_before_it_is_read(): void
    {
        $service = $this->service('zonecl');

        $this->actingAs($this->user)->withSession($this->loginUser($this->user, [CurrentClient::SESSION_KEY => $service->client_id]))
            ->get(route('services.show', $service))->assertOk()->assertDontSee(__('services.usage.title'));
    }
}
