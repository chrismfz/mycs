<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Livewire\Services\ProxmoxPanel;
use App\Models\ProxmoxGuest;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Proxmox\GuestLifecycle;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use RuntimeException;
use Tests\TestCase;

/**
 * The customer's one snapshot of a VPS (owner 2026-11-06, as myip's pvewhmcs has it): taken, rolled back to and deleted
 * from the client area where the product offers it, in the queue; only the snapshot of that name, never staff's own; never
 * while suspended; staff may delete it.
 */
class ProxmoxSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $vm = ['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps1', 'status' => 'running', 'pool' => 'mycs'];

    /** @var array<string, int> the guest's snapshots, by name: when taken */
    private array $snapshots = [];

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> method, path, form */
    private array $writes = [];

    private string $taskExit = 'OK';

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
                } elseif ($request->method() === 'DELETE' && str_starts_with($path, "{$guest}/snapshot/")) {
                    unset($this->snapshots[substr($path, strlen("{$guest}/snapshot/"))]);
                }

                return Http::response(['data' => 'UPID:vega:0000ABCD:0001:6700:qmsnapshot:101:mycs@pve!api:']);
            }

            return match (true) {
                $path === 'cluster/resources' => Http::response(['data' => [$this->vm]]),
                $path === "{$guest}/config" => Http::response(['data' => ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0', 'parent' => 'client-snap',
                    'digest' => 'a1']]),
                // The snapshot's own keys differ from the VM's now; its settings are the same.
                $path === "{$guest}/snapshot/client-snap/config" => Http::response(['data' => ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0',
                    'snaptime' => 1762430400, 'vmstate' => 'local-zfs:vm-101-state-client-snap', 'description' => 'Taken from MyCS']]),
                $path === "{$guest}/status/current" => Http::response(['data' => ['status' => $this->vm['status'], 'cpus' => 2, 'maxmem' => 4294967296]]),
                $path === "{$guest}/snapshot" => Http::response(['data' => [
                    ...array_map(fn (string $name, int $time) => ['name' => $name, 'snaptime' => $time, 'description' => ''], array_keys($this->snapshots), $this->snapshots),
                    ['name' => 'current', 'description' => 'You are here!', 'running' => 1],
                ]]),
                $path === "{$guest}/rrddata" => Http::response(['data' => []]),
                str_starts_with($path, 'nodes/vega/tasks/') => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => $this->taskExit]]),
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

    /** @return list<string> */
    private function written(): array
    {
        return array_map(fn (array $write) => $write[0] . ' ' . $write[1], $this->writes);
    }

    private function panel(Service $service)
    {
        return Livewire::actingAs($service->client->users()->first())->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $service]);
    }

    public function test_take_replaces_the_customers_snapshot_disks_only_and_leaves_staffs_own(): void
    {
        $service = $this->vps();
        $this->snapshots = ['before-upgrade' => 1700000000];

        $this->lifecycle($service)->snapshot($service, 'take');
        $this->assertSame(['POST nodes/vega/qemu/101/snapshot'], $this->written(), 'staff\'s own snapshot is not the customer\'s');
        $this->assertSame(['snapname' => 'client-snap', 'description' => 'Taken from MyCS', 'vmstate' => '0'], $this->writes[0][2]);

        $this->writes = [];
        $this->lifecycle($service)->snapshot($service, 'take');
        $this->assertSame(['DELETE nodes/vega/qemu/101/snapshot/client-snap', 'POST nodes/vega/qemu/101/snapshot'], $this->written(),
            'the one there is gone before the new one is taken');
        $this->assertArrayHasKey('before-upgrade', $this->snapshots);
    }

    public function test_rollback_restarts_only_a_running_vm_and_needs_a_snapshot(): void
    {
        $service = $this->vps();
        try {
            $this->lifecycle($service)->snapshot($service, 'rollback');
            $this->fail('rolled back to nothing');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no snapshot', $e->getMessage());
        }
        $this->assertSame([], $this->writes);

        $this->snapshots = ['client-snap' => 1762430400];
        $this->lifecycle($service)->snapshot($service, 'rollback');
        $this->assertSame([['POST', 'nodes/vega/qemu/101/snapshot/client-snap/rollback', ['start' => '1']]], $this->writes);

        $this->writes = [];
        $this->vm['status'] = 'stopped';
        $this->lifecycle($service)->snapshot($service, 'rollback');
        $this->assertSame([['POST', 'nodes/vega/qemu/101/snapshot/client-snap/rollback', []]], $this->writes, 'a stopped VM stays stopped');
    }

    public function test_delete_of_nothing_is_done_and_a_container_has_no_vmstate(): void
    {
        $service = $this->vps();
        $this->lifecycle($service)->snapshot($service, 'delete');
        $this->assertSame([], $this->writes);

        $this->vm['type'] = 'lxc';
        $this->lifecycle($service)->snapshot($service, 'take');
        $this->assertSame([['POST', 'nodes/vega/lxc/101/snapshot', ['snapname' => 'client-snap', 'description' => 'Taken from MyCS']]], $this->writes);
    }

    public function test_never_for_the_customer_while_suspended_staff_may_delete(): void
    {
        $service = $this->vps('suspended');
        $this->snapshots = ['client-snap' => 1762430400];
        try {
            $this->lifecycle($service)->snapshot($service, 'rollback');
            $this->fail('rolled back while suspended');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not active', $e->getMessage());
        }
        $this->assertSame([], $this->writes);

        $this->lifecycle($service)->snapshot($service, 'delete', byCustomer: false);
        $this->assertSame(['DELETE nodes/vega/qemu/101/snapshot/client-snap'], $this->written());
    }

    public function test_a_vm_out_of_the_pool_or_locked_is_refused(): void
    {
        $service = $this->vps();
        $this->vm['pool'] = 'other';
        try {
            $this->lifecycle($service)->snapshot($service, 'take');
            $this->fail('acted out of the pool');
        } catch (RuntimeException) {
        }
        $this->vm['pool'] = 'mycs';
        $this->vm['status'] = 'paused';
        try {
            $this->lifecycle($service)->snapshot($service, 'take');
            $this->fail('took a snapshot of a paused VM');
        } catch (RuntimeException) {
        }
        $this->assertSame([], $this->writes);
    }

    public function test_the_panel_offers_it_only_where_the_product_does(): void
    {
        $service = $this->vps(snapshots: false);
        $this->panel($service)->assertDontSee(__('services.proxmox.snapshot.title'))->call('snapshot', 'take');
        $this->assertSame([], $this->writes);
    }

    public function test_the_customer_takes_rolls_back_and_deletes_and_is_told_how_it_went(): void
    {
        $service = $this->vps();
        $page = $this->panel($service)->assertSee(__('services.proxmox.snapshot.none'))
            ->assertSee(__('services.proxmox.snapshot.action.take'))->assertDontSee(__('services.proxmox.snapshot.action.rollback'));

        $page->call('snapshot', 'take')->assertSee(__('services.proxmox.snapshot.result.done.take'))
            ->assertSee(__('services.proxmox.snapshot.action.rollback'));
        $this->assertSame(['POST nodes/vega/qemu/101/snapshot'], $this->written());
        $this->assertDatabaseHas('audits', ['auditable_id' => $service->id, 'event' => 'vm_snapshot']);

        // Told once only.
        $this->panel($service)->assertDontSee(__('services.proxmox.snapshot.result.done.take'));

        // A minute between two.
        $page->call('snapshot', 'rollback');
        $this->assertCount(1, $this->writes);

        $this->travel(61)->seconds();
        $page->call('snapshot', 'rollback')->assertSee(__('services.proxmox.snapshot.result.done.rollback'));
        $this->assertSame('POST nodes/vega/qemu/101/snapshot/client-snap/rollback', $this->written()[1]);
    }

    public function test_a_failure_is_told_and_frees_the_panel(): void
    {
        $service = $this->vps();
        $this->taskExit = 'zfs error: out of space';

        $this->panel($service)->call('snapshot', 'take')->assertSee(__('services.proxmox.snapshot.result.failed'));
        $this->assertNull(cache()->get("proxmox-panel.{$service->id}.pending"));
    }

    public function test_one_at_a_time_and_power_waits_for_it(): void
    {
        $service = $this->vps();
        cache()->put("proxmox-panel.{$service->id}.pending", 'snapshot.take', 150); // its job still running

        $this->panel($service)->call('snapshot', 'delete')->call('power', 'reboot');
        $this->assertSame([], $this->writes);
    }

    public function test_never_while_suspended_nor_another_customers(): void
    {
        $service = $this->vps('suspended');
        $this->snapshots = ['client-snap' => 1762430400];
        $this->panel($service)->assertDontSee(__('services.proxmox.snapshot.action.rollback'))->call('snapshot', 'rollback');
        $this->assertSame([], $this->writes);

        Livewire::actingAs(User::factory()->create())->withoutLazyLoading()->test(ProxmoxPanel::class, ['service' => $service])->assertForbidden();
    }

    public function test_staff_delete_the_customers_snapshot(): void
    {
        $service = $this->vps();
        $this->snapshots = ['client-snap' => 1762430400];
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('vm_snapshot_delete')->assertNotified();
        $this->assertSame(['DELETE nodes/vega/qemu/101/snapshot/client-snap'], $this->written());

        $off = $this->vps(snapshots: false);
        $this->livewireAs($staff)->test(EditService::class, ['record' => $off->getRouteKey()])->assertActionHidden('vm_snapshot_delete');
    }
}
