<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Livewire\Services\ProxmoxPanel;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review 2 of #502 (Proxmox's client area): each test fails on d8992758 and names what it proves.
 */
class ProxmoxClientAreaAdversarialR2Test extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    /** @var array<string, mixed> */
    private array $vm = ['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps1', 'status' => 'running', 'pool' => 'mycs'];

    /** @var list<string> */
    private array $writes = [];

    /** @var list<string> every GET path */
    private array $reads = [];

    /** @var list<string> timeframes whose rrddata the node fails */
    private array $rrdFails = [];

    /** @var (\Closure(): void)|null run while a write is with the cluster */
    private ?\Closure $duringWrite = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function (Request $request) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/'));
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if ($request->method() !== 'GET') {
                $this->writes[] = $request->method() . ' ' . $path;
                if ($this->duringWrite !== null) {
                    ($this->duringWrite)();
                }

                return Http::response(['data' => 'UPID:vega:0000ABCD:0001:6700:qmreboot:101:mycs@pve!api:']);
            }
            $this->reads[] = $path;

            return match (true) {
                $path === 'cluster/resources' => Http::response(['data' => [$this->vm]]),
                $path === 'nodes/vega/qemu/101/config' => Http::response(['data' => ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0']]),
                $path === 'nodes/vega/qemu/101/status/current' => Http::response(['data' => ['status' => $this->vm['status'], 'cpu' => 0.25, 'cpus' => 2,
                    'mem' => 1073741824, 'maxmem' => 4294967296, 'maxdisk' => 64424509440, 'uptime' => 93784]]),
                $path === 'nodes/vega/qemu/101/rrddata' && in_array($query['timeframe'] ?? 'hour', $this->rrdFails, true) => Http::response(['data' => null], 500),
                $path === 'nodes/vega/qemu/101/rrddata' => Http::response(['data' => [
                    ['time' => 1, 'cpu' => 0.1, 'mem' => 1, 'maxmem' => 4, 'netin' => 100, 'netout' => 50, 'diskread' => 10, 'diskwrite' => 5],
                ]]),
                default => Http::response(['data' => null], 500),
            };
        });
    }

    private function vps(): Service
    {
        $server = Server::query()->create(['name' => 'PVE', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => self::VEGA, 'token_id' => 'mycs@pve!api', 'token_secret' => 's3cret', 'pool' => 'mycs', 'terminate' => 'destroy']);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 101, 'node' => 'vega', 'name' => 'vps1', 'status' => 'running',
            'pool' => 'mycs', 'macs' => ['BC:24:11:00:00:01'], 'ips' => ['192.0.2.10'], 'service_id' => $service->id]);

        return $service;
    }

    private function owner(Service $service): User
    {
        return $service->client->users()->first();
    }

    /**
     * ADV-502-R2-1: while an action is pending (20 s out of every 30, since one power a half minute is allowed), every
     * render reads the VM's state from the cluster uncached: no limit but the customer's request rate. The poll asks every
     * 5 s, but nothing holds the customer to the poll: any Livewire request ($refresh, a timeframe click, a script) is a
     * full guest() check (cluster/resources, config, status/current) on the cluster.
     */
    public function test_adv_502_r2_1_the_pending_window_reads_the_cluster_on_every_request(): void
    {
        $service = $this->vps();
        $page = Livewire::actingAs($this->owner($service))->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $service]);
        $page->call('power', 'reboot');
        $this->reads = [];

        for ($i = 0; $i < 25; $i++) {
            $page->call('$refresh');
        }

        $current = count(array_filter($this->reads, fn ($p) => $p === 'nodes/vega/qemu/101/status/current'));
        $this->assertLessThanOrEqual(2, $current, "25 requests within a second, while pending, read the VM's state {$current} times (once per request: no limit)");
    }

    /**
     * ADV-502-R2-2: the limiter is checked (tooManyAttempts) before the action and hit only after the cluster answered.
     * Meanwhile (the lock's wait up to 10 s, the HTTP call) a second request (a double click, two tabs, a script) passes
     * the check too, waits on the guest's lock, and acts once the first releases it: two actions where one per half minute
     * is promised. RateLimiter::attempt() before (hit at once) held it.
     */
    public function test_adv_502_r2_2_the_limiter_admits_a_second_power_while_the_first_is_with_the_cluster(): void
    {
        $service = $this->vps();
        $admitted = null;
        $this->duringWrite = function () use ($service, &$admitted) {
            $admitted ??= !RateLimiter::tooManyAttempts('proxmox-power:' . $service->id, 1);
        };
        Livewire::actingAs($this->owner($service))->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $service])->call('power', 'stop');

        $this->assertSame(['POST nodes/vega/qemu/101/status/stop'], $this->writes);
        $this->assertFalse($admitted, 'while the stop was with the cluster, the limiter would let a second action through');
    }

    /**
     * ADV-502-R2-3: the graph and the state share one refusal key. A node whose rrddata fails for one timeframe (a week's
     * RRD missing or corrupt: a node's own fault, not a refusal) hides the whole panel, its state and its power buttons,
     * for a minute, for every timeframe: the customer cannot reboot their VM because a graph could not be read.
     */
    public function test_adv_502_r2_3_a_graph_that_fails_hides_the_state_and_the_power_buttons(): void
    {
        $service = $this->vps();
        $this->rrdFails = ['week'];
        $page = Livewire::actingAs($this->owner($service))->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $service])
            ->assertSee(__('services.proxmox.power.reboot'));

        $page->call('$set', 'timeframe', 'week');
        $page->call('$set', 'timeframe', 'hour'); // back to the graph that reads fine

        $page->assertSee(__('services.proxmox.status.running'));
        $page->assertSee(__('services.proxmox.power.reboot'));
    }

    /**
     * ADV-502-R2-4: «a state other than running or stopped neither told as such nor powered» holds in the view only.
     * power() never looks at the state: a crafted call (the buttons are just wire:click) sends a hard stop to a VM the
     * cluster reports paused, or mid-migration.
     */
    public function test_adv_502_r2_4_a_vm_in_another_state_is_powered_by_a_crafted_call(): void
    {
        $service = $this->vps();
        $this->vm['status'] = 'paused';
        $page = Livewire::actingAs($this->owner($service))->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $service])
            ->assertSee(__('services.proxmox.status.unknown'))
            ->assertDontSee(__('services.proxmox.power.stop'));

        $page->call('power', 'stop');

        $this->assertSame([], $this->writes, 'a VM in a state the panel neither tells nor powers was hard-stopped');
    }
}
