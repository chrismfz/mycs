<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Livewire\Services\ProxmoxPanel;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Support\Sparkline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proxmox's client area (owner 2026-10-28): the customer sees their VPS (state, CPU, memory, disk, addresses, graphs)
 * and starts, shuts down, reboots or stops it; never while suspended, never another's, never one MyCS may not act on.
 */
class ProxmoxClientAreaTest extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    /** @var array<string, mixed> */
    private array $vm = ['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps1', 'status' => 'running', 'pool' => 'mycs'];

    /** @var list<string> */
    private array $writes = [];

    /** @var list<string> the graphs' timeframes asked */
    private array $frames = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function (Request $request) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/'));
            if ($request->method() !== 'GET') {
                $this->writes[] = $request->method() . ' ' . $path;

                return Http::response(['data' => 'UPID:vega:0000ABCD:0001:6700:qmstart:101:mycs@pve!api:']);
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match (true) {
                $path === 'cluster/resources' => Http::response(['data' => [$this->vm]]),
                $path === 'nodes/vega/qemu/101/config' => Http::response(['data' => ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0']]),
                $path === 'nodes/vega/qemu/101/status/current' => Http::response(['data' => ['status' => $this->vm['status'], 'cpu' => 0.25, 'cpus' => 2,
                    'mem' => 1073741824, 'maxmem' => 4294967296, 'maxdisk' => 64424509440, 'uptime' => 93784]]),
                $path === 'nodes/vega/qemu/101/rrddata' => tap(Http::response(['data' => [
                    ['time' => 1, 'cpu' => 0.1, 'mem' => 1, 'maxmem' => 4, 'netin' => 100, 'netout' => 50, 'diskread' => 10, 'diskwrite' => 5],
                    ['time' => 2, 'cpu' => 0.4, 'mem' => 2, 'maxmem' => 4, 'netin' => 300, 'netout' => 80, 'diskread' => 20, 'diskwrite' => 15],
                ]]), fn () => $this->frames[] = (string) ($query['timeframe'] ?? '')),
                default => Http::response(['data' => null], 500),
            };
        });
    }

    private function vps(string $status = 'active', array $guest = []): Service
    {
        $server = Server::query()->create(['name' => 'PVE', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => self::VEGA, 'token_id' => 'mycs@pve!api', 'token_secret' => 's3cret', 'pool' => 'mycs', 'terminate' => 'destroy']);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        ProxmoxGuest::query()->create(array_merge(['server_id' => $server->id, 'vmid' => 101, 'node' => 'vega', 'name' => 'vps1', 'status' => 'running',
            'pool' => 'mycs', 'macs' => ['BC:24:11:00:00:01'], 'ips' => ['192.0.2.10'], 'service_id' => $service->id], $guest));

        return $service;
    }

    private function owner(Service $service): User
    {
        return $service->client->users()->first();
    }

    public function test_the_customer_sees_its_vps_state_resources_addresses_and_graphs(): void
    {
        $service = $this->vps();

        Livewire::actingAs($this->owner($service))->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $service])
            ->assertSee(__('services.proxmox.status.running'))
            ->assertSee('25%')->assertSee('1.0 GB')->assertSee('4.0 GB')->assertSee('60.0 GB')
            ->assertSee('192.0.2.10')->assertSee('BC:24:11:00:00:01')
            ->assertSee(__('services.proxmox.power.reboot'))->assertDontSee(__('services.proxmox.power.start'))
            ->assertSee(__('services.proxmox.peak') . ' 40%')
            ->call('$set', 'timeframe', 'week')->assertSee(__('services.proxmox.timeframe.week'));
        $this->assertSame(['hour', 'week'], $this->frames);

        // On its service page too.
        $this->livewireAs($this->owner($service))->test(ServiceShow::class, ['service' => $service])->assertSeeLivewire(ProxmoxPanel::class);
    }

    public function test_it_powers_its_vps_and_is_told_once_in_half_a_minute(): void
    {
        $service = $this->vps();
        $page = Livewire::actingAs($this->owner($service))->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $service]);

        $page->call('power', 'reboot');
        $page->call('power', 'shutdown'); // too soon
        $page->call('power', 'delete');   // not a power action

        $this->assertSame(['POST nodes/vega/qemu/101/status/reboot'], $this->writes);
        $this->assertDatabaseHas('audits', ['auditable_id' => $service->id, 'event' => 'vm_power']);
    }

    public function test_never_while_suspended_never_another_customers_and_a_bad_timeframe_is_the_hour(): void
    {
        $suspended = $this->vps('suspended', ['status' => 'stopped']);
        $this->vm['status'] = 'stopped';
        Livewire::actingAs($this->owner($suspended))->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $suspended])
            ->assertSee(__('services.proxmox.suspended'))->assertDontSee(__('services.proxmox.power.start'))
            ->call('power', 'start');
        $this->assertSame([], $this->writes, 'suspended: its unsuspension starts it');

        $other = User::factory()->create();
        Livewire::actingAs($other)->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $suspended])->assertForbidden();

        $this->vm['status'] = 'running';
        $active = $this->vps();
        Livewire::actingAs($this->owner($active))->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $active])
            ->set('timeframe', 'year&x=1')->assertSet('timeframe', 'hour');
    }

    public function test_a_vm_mycs_may_not_act_on_is_not_shown_nor_powered(): void
    {
        $service = $this->vps();
        $this->vm['pool'] = 'other'; // out of MyCS's pool

        Livewire::actingAs($this->owner($service))->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $service])
            ->assertSee(__('services.proxmox.unavailable'))->assertDontSee('192.0.2.10')
            ->call('power', 'reboot');
        $this->assertSame([], $this->writes);
    }

    public function test_a_sparkline_draws_its_points_and_nothing_from_too_little(): void
    {
        $this->assertSame('0,60 150,30 300,0', Sparkline::points([0, 50, 100], 300, 60, 100));
        $this->assertSame('0,60 300,0', Sparkline::points([0, null, 4], 300, 60));
        $this->assertSame('', Sparkline::points([5], 300, 60));
        $this->assertSame('', Sparkline::points([0, 0], 300, 60));
    }
}
