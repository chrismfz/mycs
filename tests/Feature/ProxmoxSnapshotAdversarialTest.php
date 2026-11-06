<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\ProxmoxSnapshotJob;
use App\Livewire\Services\ProxmoxPanel;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Proxmox\GuestLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use RuntimeException;
use Tests\TestCase;

/**
 * Adversarial review of the customer's snapshot (feat/proxmox-snapshot): each test is one finding, failing on the
 * commit reviewed. Same cluster fake as ProxmoxSnapshotTest, with paths that can be overridden per test.
 */
class ProxmoxSnapshotAdversarialTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $vm = ['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps1', 'status' => 'running', 'pool' => 'mycs'];

    /** @var array<string, int> */
    private array $snapshots = [];

    /** @var array<string, mixed> the VM's config now */
    private array $config = ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0', 'memory' => 2048, 'cores' => 1, 'sockets' => 1];

    /** @var array<string, array<string, mixed>> each snapshot's own config, by name (what a rollback restores) */
    private array $snapshotConfig = [];

    private bool $snapshotListFails = false;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function (Request $request) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/'));
            $guest = 'nodes/vega/' . $this->vm['type'] . '/101';
            if ($request->method() !== 'GET') {
                parse_str($request->body(), $form);
                $this->writes[] = [$request->method(), $path, $form];
                if ($request->method() === 'POST' && $path === "{$guest}/snapshot") {
                    $this->snapshots[(string) $form['snapname']] = 1762430400;
                    $this->snapshotConfig[(string) $form['snapname']] = $this->config;
                } elseif ($request->method() === 'DELETE' && str_starts_with($path, "{$guest}/snapshot/")) {
                    unset($this->snapshots[substr($path, strlen("{$guest}/snapshot/"))]);
                } elseif ($request->method() === 'POST' && preg_match('#/snapshot/([^/]+)/rollback$#', $path, $m) === 1) {
                    $this->config = $this->snapshotConfig[$m[1]] ?? $this->config; // Proxmox: the snapshot's config comes back
                }

                return Http::response(['data' => 'UPID:vega:0000ABCD:0001:6700:qmsnapshot:101:mycs@pve!api:']);
            }

            if (preg_match('#^' . preg_quote($guest, '#') . '/snapshot/([^/]+)/config$#', $path, $m) === 1) {
                return Http::response(['data' => $this->snapshotConfig[$m[1]] ?? null], isset($this->snapshotConfig[$m[1]]) ? 200 : 500);
            }

            return match (true) {
                $path === 'cluster/resources' => Http::response(['data' => [$this->vm]]),
                $path === "{$guest}/config" => Http::response(['data' => $this->config]),
                $path === "{$guest}/status/current" => Http::response(['data' => ['status' => $this->vm['status'], 'cpus' => 2, 'maxmem' => 4294967296]]),
                $path === "{$guest}/snapshot" => $this->snapshotListFails ? Http::response(['data' => null, 'errors' => 'got timeout'], 500) : Http::response(['data' => [
                    ...array_map(fn (string $name, int $time) => ['name' => $name, 'snaptime' => $time, 'description' => ''], array_keys($this->snapshots), $this->snapshots),
                    ['name' => 'current', 'description' => 'You are here!', 'running' => 1],
                ]]),
                $path === "{$guest}/rrddata" => Http::response(['data' => []]),
                str_starts_with($path, 'nodes/vega/tasks/') => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
                default => Http::response(['data' => null], 500),
            };
        });
    }

    private function vps(string $status = 'active', bool $snapshots = true): Service
    {
        $server = Server::query()->create(['name' => 'PVE', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => 'https://vega.example.test:8006', 'token_id' => 'mycs@pve!api', 'token_secret' => 's3cret', 'pool' => 'mycs']);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        (new Setting)->forceFill(['key' => 'snapshots', 'value' => $snapshots ? '1' : '0', 'type' => 'boolean', 'encrypted' => false,
            'settingable_id' => $product->product->id, 'settingable_type' => $product->product->getMorphClass()])->save();
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 101, 'node' => 'vega', 'name' => 'vps1', 'status' => 'running',
            'pool' => 'mycs', 'macs' => ['BC:24:11:00:00:01'], 'service_id' => $service->id]);

        return $service;
    }

    private function lifecycle(Service $service): GuestLifecycle
    {
        $server = $service->provisioningServer();
        $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
        $this->assertInstanceOf(Proxmox::class, $extension);

        return $extension->lifecycle($service);
    }

    private function panel(Service $service)
    {
        return Livewire::actingAs($service->client->users()->first())->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $service]);
    }

    /**
     * ADV-SNAP-1: a Proxmox rollback restores the snapshot's whole config, not only its disks: cores, memory, sockets
     * (and net0's rate limit, firewall, onboot). A customer snapshots on the bigger plan, downgrades (staff take memory
     * and cores off by hand: the module has no upgradeServer), rolls back, and runs the bigger plan at the smaller price.
     * A rollback must not hand back resources the VM no longer has (refused, or the config put back after it).
     */
    public function test_a_rollback_never_restores_resources_taken_off_since_the_snapshot(): void
    {
        $service = $this->vps();
        $this->config = ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0', 'memory' => 8192, 'cores' => 4, 'sockets' => 1];
        $this->lifecycle($service)->snapshot($service, 'take');

        // The downgrade, done by staff on the cluster.
        $this->config['memory'] = 2048;
        $this->config['cores'] = 1;

        try {
            $this->lifecycle($service)->snapshot($service, 'rollback');
        } catch (RuntimeException) {
            // refused: fine
        }
        $this->assertSame(2048, (int) $this->config['memory'], 'the rollback gave the customer back memory they no longer pay for');
        $this->assertSame(1, (int) $this->config['cores'], 'the rollback gave the customer back cores they no longer pay for');
    }

    /**
     * ADV-SNAP-2: the snapshot list is read inside read(), unguarded: a list that cannot be read (a node's timeout) is a
     * refusal of the whole VM, a minute, its state and power buttons gone. ADV-502-R2-3 keeps a graph's failure apart for
     * that reason; the snapshot's line is no more essential than the graph.
     */
    public function test_a_snapshot_list_that_cannot_be_read_does_not_hide_the_vm_and_its_power(): void
    {
        $service = $this->vps();
        $this->snapshotListFails = true;

        $this->panel($service)->assertSee(__('services.proxmox.power.shutdown'))->assertSee(__('services.proxmox.status.running'));
    }

    /**
     * ADV-SNAP-3: staff's delete runs the same job, whose finally always settles the panel: while the customer's own
     * snapshot job holds the VM, staff's waits 10 s for the lock, fails, and then tells the customer «could not be done»
     * and clears the customer's pending mark, though the customer's job is still running (the panel offers its buttons
     * again, and a power action is no longer held back).
     */
    public function test_staffs_delete_never_settles_the_customers_running_snapshot(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $service = $this->vps();
        $this->snapshots = ['client-snap' => 1762430400];
        $server = $service->provisioningServer();

        // The customer's take: its pending mark, and its job holding the guest's lock.
        Cache::put("proxmox-panel.{$service->id}.pending", 'snapshot.take', 150);
        $lock = Cache::lock("proxmox.guest.{$server->id}.{$service->id}", 150);
        $this->assertTrue($lock->get());

        (new ProxmoxSnapshotJob($service, 'delete', false))->handle();

        $this->assertSame('snapshot.take', Cache::get("proxmox-panel.{$service->id}.pending"), 'the customer\'s job still runs');
        $this->assertNotSame('failed', Cache::get("proxmox-panel.{$service->id}.snapshot"), 'the customer is told their take failed');
        $lock->release();
    }

    /**
     * ADV-SNAP-4: the VM's state is cached (15 s, file or Redis: it outlives a deploy) in the shape the code before this
     * one wrote, without «snapshot». The first renders after the deploy read $vm['snapshot'] from it: an undefined key,
     * the panel errors instead of rendering. A cached state is read as it may be, not as this code writes it.
     */
    public function test_a_state_cached_by_the_code_before_still_renders(): void
    {
        $service = $this->vps();
        $guest = ProxmoxGuest::query()->where('service_id', $service->id)->firstOrFail();
        Cache::put("proxmox-panel.{$service->id}.{$guest->id}.101.state", ['vmid' => 101, 'name' => 'vps1', 'status' => 'running', 'uptime' => 0,
            'cpu' => 0.0, 'cpus' => 2, 'memory' => 0, 'max_memory' => 4294967296, 'max_disk' => 0, 'ips' => [], 'macs' => ['BC:24:11:00:00:01']], 15);

        $this->panel($service)->assertSee(__('services.proxmox.power.shutdown'))->assertSee(__('services.proxmox.snapshot.title'));
    }

    /**
     * ADV-SNAP-5: a job that the worker ends (its 120 s timeout: HTTP calls are not counted in the 80 s wait budget, or a
     * worker restarted by a deploy) never reaches its finally, and the job has no failed(): the customer's panel says
     * «pending» for up to 150 s and is never told it failed; the result is never written.
     */
    public function test_a_job_the_worker_fails_tells_the_customer_and_frees_the_panel(): void
    {
        $service = $this->vps();
        Cache::put("proxmox-panel.{$service->id}.pending", 'snapshot.rollback', 150);

        app(CallQueuedHandler::class)->failed(['command' => serialize(new ProxmoxSnapshotJob($service, 'rollback'))],
            new TimeoutExceededException('App\Jobs\Server\ProxmoxSnapshotJob has timed out.'), 'adv-snap-5');

        $this->assertNull(Cache::get("proxmox-panel.{$service->id}.pending"), 'the panel waits on a job that is gone');
        $this->assertSame('failed', Cache::get("proxmox-panel.{$service->id}.snapshot"), 'the customer is never told');
    }
}
