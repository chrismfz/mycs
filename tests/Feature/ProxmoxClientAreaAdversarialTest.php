<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Livewire\Services\ProxmoxPanel;
use App\Models\ClientUser;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of #502 (Proxmox's client area): each test fails on 847d1fdf and names what it proves.
 */
class ProxmoxClientAreaAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    /** @var array<string, mixed> */
    private array $vm = ['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps1', 'status' => 'running', 'pool' => 'mycs'];

    /** @var list<string> */
    private array $writes = [];

    /** @var list<string> every GET path */
    private array $reads = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function (Request $request) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/'));
            if ($request->method() !== 'GET') {
                $this->writes[] = $request->method() . ' ' . $path;
                // The cluster does it: a stop or a shutdown leaves the VM stopped, a start running.
                if (preg_match('#/status/(stop|shutdown)$#', $path)) {
                    $this->vm['status'] = 'stopped';
                } elseif (str_ends_with($path, '/status/start')) {
                    $this->vm['status'] = 'running';
                }

                return Http::response(['data' => 'UPID:vega:0000ABCD:0001:6700:qmstart:101:mycs@pve!api:']);
            }
            $this->reads[] = $path;

            return match (true) {
                $path === 'cluster/resources' => Http::response(['data' => [$this->vm]]),
                $path === 'nodes/vega/qemu/101/config' => Http::response(['data' => ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0']]),
                $path === 'nodes/vega/qemu/101/status/current' => Http::response(['data' => ['status' => $this->vm['status'], 'cpu' => 0.25, 'cpus' => 2,
                    'mem' => 1073741824, 'maxmem' => 4294967296, 'maxdisk' => 64424509440, 'uptime' => 93784]]),
                $path === 'nodes/vega/qemu/101/rrddata' => Http::response(['data' => [
                    ['time' => 1, 'cpu' => 0.1, 'mem' => 1, 'maxmem' => 4, 'netin' => 100, 'netout' => 50, 'diskread' => 10, 'diskwrite' => 5],
                    ['time' => 2, 'cpu' => 0.4, 'mem' => 2, 'maxmem' => 4, 'netin' => 300, 'netout' => 80, 'diskread' => 20, 'diskwrite' => 15],
                ]]),
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

    /**
     * ADV-502-1: render() never re-checks `view`; only mount() does. A login whose grant was revoked while the page was
     * open (or anyone holding the component's snapshot, which is not bound to a user, even logged out) keeps reading the
     * VM live from the cluster: its state, its addresses, its graphs, for any timeframe. Services\Show re-authorizes.
     */
    public function test_adv_502_1_a_revoked_login_keeps_reading_the_vm_from_the_cluster(): void
    {
        $service = $this->vps();
        $member = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $service->client_id, 'user_id' => $member->id, 'permissions' => ['products'], 'granted_at' => now()]);
        $page = Livewire::actingAs($member)->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $service])->assertSee('192.0.2.10');

        ClientUser::query()->where('user_id', $member->id)->update(['revoked_at' => now()]);
        $this->assertFalse($member->fresh()->can('view', $service->fresh()), 'the grant is revoked');
        $this->reads = [];

        $page->call('$set', 'timeframe', 'week');

        $this->assertNotContains('nodes/vega/qemu/101/rrddata', $this->reads, 'a revoked login made MyCS read the VM\'s week graph from the cluster');
        $page->assertDontSee('192.0.2.10');
    }

    /** ADV-502-1 (logged out): the same snapshot, the session gone. */
    public function test_adv_502_1_a_logged_out_session_keeps_reading_the_vm(): void
    {
        $service = $this->vps();
        $page = Livewire::actingAs($this->owner($service))->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $service]);

        auth('web')->logout();
        $this->reads = [];
        $page->call('$set', 'timeframe', 'day');

        $this->assertNotContains('nodes/vega/qemu/101/rrddata', $this->reads, 'no login, and the cluster was read for it');
        $page->assertDontSee('192.0.2.10');
    }

    /**
     * ADV-502-2: power() with no guest (unmapped, or destroyed, since the page was opened; the server changed) goes
     * through `$this->extension()?->…`: null, nothing is sent, and yet the customer is told «sent» and an audit
     * «vm_power» is written as if the VM had been rebooted.
     */
    public function test_adv_502_2_power_on_an_unmapped_vm_is_told_sent_and_audited(): void
    {
        $service = $this->vps();
        $page = Livewire::actingAs($this->owner($service))->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $service]);

        ProxmoxGuest::query()->update(['service_id' => null]); // staff unmap it (or it is destroyed)
        $page->call('power', 'reboot');

        $this->assertSame([], $this->writes);
        $page->assertNotDispatched('notify', ['message' => __('services.proxmox.sent.reboot'), 'type' => 'success']);
        $this->assertDatabaseMissing('audits', ['auditable_id' => $service->id, 'event' => 'vm_power']);
    }

    /**
     * ADV-502-3: power() forgets only the current timeframe's cache key; the other timeframes keep the VM's state (and
     * its buttons) from before the action for up to a minute. Stopped from «hour», «day» still says running and offers
     * shutdown/reboot/stop, no start.
     */
    public function test_adv_502_3_power_leaves_the_other_timeframes_showing_the_old_state(): void
    {
        $service = $this->vps();
        $page = Livewire::actingAs($this->owner($service))->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $service])
            ->call('$set', 'timeframe', 'day')->call('$set', 'timeframe', 'hour');

        $page->call('power', 'stop');
        $this->assertSame(['POST nodes/vega/qemu/101/status/stop'], $this->writes);
        $page->assertSee(__('services.proxmox.power.start'));

        $page->call('$set', 'timeframe', 'day')
            ->assertSee(__('services.proxmox.status.stopped'))
            ->assertSee(__('services.proxmox.power.start'));
    }

    /**
     * ADV-502-4: the cache is keyed on the service only. A VM re-mapped from service A to service B (staff fixing a
     * wrong mapping: it is B's customer's machine) is still shown to A's customer, addresses and graphs, until the
     * minute runs out; A's own read would now be «unavailable».
     */
    public function test_adv_502_4_a_vm_remapped_to_another_service_is_still_shown_to_the_old_one(): void
    {
        $a = $this->vps();
        $b = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $a->plan_id,
            'product_id' => $a->product_id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        $page = Livewire::actingAs($this->owner($a))->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $a])->assertSee('192.0.2.10');

        ProxmoxGuest::query()->update(['service_id' => $b->id]);
        $page->call('$refresh');

        $page->assertDontSee('192.0.2.10');
    }

    /**
     * ADV-502-5: a refusal (VM out of the pool, MACs changed, the cluster down) is never kept: each render reads the
     * whole cluster's list again and report()s an exception. A customer's script on `$refresh` (or the timeframe buttons)
     * hammers the cluster and the error log, with no rate limit; a success is kept a minute.
     */
    public function test_adv_502_5_a_refused_vm_is_read_from_the_cluster_on_every_render(): void
    {
        $service = $this->vps();
        $this->vm['pool'] = 'other';
        $page = Livewire::actingAs($this->owner($service))->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $service])
            ->assertSee(__('services.proxmox.unavailable'));

        for ($i = 0; $i < 10; $i++) {
            $page->call('$refresh');
        }

        $this->assertLessThanOrEqual(2, count(array_keys($this->reads, 'cluster/resources', true)),
            'each render of a refused VM read the whole cluster\'s list again');
    }
}
