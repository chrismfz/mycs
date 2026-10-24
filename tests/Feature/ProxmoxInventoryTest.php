<?php

namespace Tests\Feature;

use App\Admin\Pages\ProxmoxNodes;
use App\Admin\Resources\ProxmoxGuestResource;
use App\Admin\Resources\ProxmoxGuestResource\Pages\ListProxmoxGuests;
use App\Helpers\ExtensionHelper;
use App\Models\Property;
use App\Models\ProxmoxGuest;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Proxmox\GuestSync;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Proxmox, PR 1 (owner 2026-10-24): a cluster read with a read-only token, its guests mapped to services or marked
 * internal, its nodes' graphs. One server per cluster, any of its nodes answering.
 */
class ProxmoxInventoryTest extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    private const PEGASUS = 'https://pegasus.example.test:8006';

    /** @var array<string, mixed> path => data */
    private array $answers = [];

    /** @var list<string> */
    private array $down = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->answers = [
            'version' => ['version' => '9.0.3'],
            'nodes' => [['node' => 'vega', 'status' => 'online', 'maxcpu' => 32, 'mem' => 1 << 34, 'maxmem' => 1 << 37, 'uptime' => 86400],
                ['node' => 'pegasus', 'status' => 'offline']],
            'nodes/vega/rrddata' => [['time' => 1, 'cpu' => 0.10, 'iowait' => 0.01, 'memused' => 10, 'memtotal' => 100, 'netin' => 1000, 'netout' => 500],
                ['time' => 2, 'cpu' => 0.20, 'iowait' => 0.02, 'memused' => 20, 'memtotal' => 100, 'netin' => 2000, 'netout' => 700]],
            'cluster/resources' => [
                ['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'web1', 'status' => 'running', 'maxcpu' => 4, 'maxmem' => 1 << 33, 'maxdisk' => 1 << 36],
                ['vmid' => 102, 'type' => 'qemu', 'node' => 'vega', 'name' => 'dns.example.test', 'status' => 'stopped'],
            ],
            'nodes/vega/qemu/101/config' => ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0'],
            'nodes/vega/qemu/101/agent/network-get-interfaces' => ['result' => [['name' => 'eth0', 'ip-addresses' => [['ip-address' => '203.0.113.10']]]]],
            'nodes/vega/qemu/102/config' => ['net0' => 'virtio=BC:24:11:00:00:02,bridge=vmbr0'],
        ];
        Http::fake(function (Request $request) {
            foreach ($this->down as $base) {
                if (str_starts_with($request->url(), $base)) {
                    return Http::failedConnection();
                }
            }
            if ($request->header('Authorization') !== ['PVEAPIToken=mycs@pve!api=s3cret']) {
                return Http::response(['data' => null], 401);
            }
            $path = strtok(substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/')), '?');

            return array_key_exists($path, $this->answers) ? Http::response(['data' => $this->answers[$path]]) : Http::response(['data' => null], 500);
        });
    }

    private function cluster(string $name = 'PVE cluster'): Server
    {
        $server = Server::query()->create(['name' => $name, 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => self::VEGA, 'node2_host' => self::PEGASUS, 'token_id' => 'mycs@pve!api', 'token_secret' => 's3cret']);

        return $server->fresh();
    }

    private function staff(array $permissions = ['*']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1], $attributes));
    }

    public function test_the_token_secret_is_kept_encrypted_and_the_connection_tested(): void
    {
        $server = $this->cluster();

        $this->assertTrue((bool) $server->settings->firstWhere('key', 'token_secret')->encrypted);
        $this->assertTrue(ExtensionHelper::testConfig($server, $server->settings->pluck('value', 'key')->all()));
        $this->assertStringContainsString('token was refused', (string) ExtensionHelper::testConfig($server,
            ['host' => self::VEGA, 'token_id' => 'mycs@pve!api', 'token_secret' => 'wrong']));
    }

    public function test_the_sync_reads_the_guests_and_keeps_staffs_mapping(): void
    {
        $server = $this->cluster();
        $this->artisan('proxmox:sync')->assertExitCode(0);

        $web = ProxmoxGuest::query()->where('vmid', 101)->sole();
        $this->assertSame(['web1', 'vega', 'running', 4, ['BC:24:11:00:00:01'], ['203.0.113.10']],
            [$web->name, $web->node, $web->status, $web->cpus, $web->macs, $web->ips]);
        $this->assertSame(2, ProxmoxGuest::query()->unmapped()->count());

        $service = $this->service();
        $web->update(['service_id' => $service->id]);
        $this->answers['cluster/resources'][0]['status'] = 'stopped'; // its agent not asked: its addresses kept
        array_pop($this->answers['cluster/resources']); // 102 deleted on the cluster

        $this->assertSame(['seen' => 1, 'new' => 0, 'gone' => 1], GuestSync::sync($server));
        $web->refresh();
        $this->assertSame([$service->id, 'stopped', ['203.0.113.10']], [$web->service_id, $web->status, $web->ips]);
        $this->assertNotNull(ProxmoxGuest::query()->where('vmid', 102)->value('gone_at'), 'kept, marked gone');
    }

    public function test_a_node_down_hides_nothing(): void
    {
        $this->cluster();
        $this->down = [self::VEGA];

        $this->artisan('proxmox:sync')->assertExitCode(0);

        $this->assertSame(2, ProxmoxGuest::query()->count());
    }

    public function test_a_cluster_that_cannot_be_read_marks_nothing_gone(): void
    {
        $server = $this->cluster();
        GuestSync::sync($server);
        $this->down = [self::VEGA, self::PEGASUS];

        $this->artisan('proxmox:sync')->assertExitCode(1);

        $this->assertSame(0, ProxmoxGuest::query()->whereNotNull('gone_at')->count());
    }

    public function test_a_cluster_really_emptied_marks_its_guests_gone(): void
    {
        $server = $this->cluster();
        GuestSync::sync($server);
        $this->answers['cluster/resources'] = []; // its nodes still seen: the token is fine

        $this->assertSame(['seen' => 0, 'new' => 0, 'gone' => 2], GuestSync::sync($server));
    }

    public function test_two_clusters_may_have_the_same_vmid(): void
    {
        GuestSync::sync($this->cluster('One'));
        GuestSync::sync($this->cluster('Two'));

        $this->assertSame(2, ProxmoxGuest::query()->where('vmid', 101)->count());
    }

    public function test_a_guest_is_suggested_by_its_ip_mac_or_name(): void
    {
        GuestSync::sync($this->cluster());
        $byIp = $this->service(['dedicated_ip' => '203.0.113.10']);
        $byName = $this->service(['domain' => 'dns.example.test']);

        $this->assertSame($byIp->id, ProxmoxGuest::query()->where('vmid', 101)->sole()->suggestedServiceId());
        $this->assertSame($byName->id, ProxmoxGuest::query()->where('vmid', 102)->sole()->suggestedServiceId());

        $byName->update(['domain' => 'other.example.test']);
        $byMac = $this->service();
        Property::query()->create(['model_type' => Service::class, 'model_id' => $byMac->id, 'key' => 'machine_mac', 'name' => 'MAC', 'value' => 'BC:24:11:00:00:02']);
        $this->assertSame($byMac->id, ProxmoxGuest::query()->where('vmid', 102)->sole()->suggestedServiceId());
    }

    public function test_staff_map_a_guest_or_mark_it_internal(): void
    {
        GuestSync::sync($this->cluster());
        $service = $this->service(['dedicated_ip' => '203.0.113.10']);
        $web = ProxmoxGuest::query()->where('vmid', 101)->sole();
        $dns = ProxmoxGuest::query()->where('vmid', 102)->sole();
        $staff = $this->staff();

        $this->livewireAs($staff)->test(ListProxmoxGuests::class)
            ->assertCanSeeTableRecords([$web, $dns])
            ->callTableAction('assign', $web, ['service_id' => $service->id])->assertHasNoTableActionErrors()
            ->callTableAction('internal', $dns, ['note' => 'our DNS'])->assertHasNoTableActionErrors()
            ->filterTable('mapping', 'unmapped')->assertCanNotSeeTableRecords([$web, $dns]);

        $this->assertSame($service->id, $web->fresh()->service_id);
        $this->assertSame([true, 'our DNS'], [$dns->fresh()->internal, $dns->fresh()->note]);
    }

    public function test_staff_without_the_servers_permissions_see_nothing(): void
    {
        GuestSync::sync($this->cluster());
        $staff = $this->staff(['admin.services.viewAny']);

        $this->actingAs($staff, 'staff');
        $this->get(ProxmoxGuestResource::getUrl())->assertForbidden();
        $this->get(ProxmoxNodes::getUrl())->assertForbidden();

        $reader = $this->staff(['admin.servers.viewAny']);
        $this->livewireAs($reader)->test(ListProxmoxGuests::class)
            ->assertTableActionHidden('assign', ProxmoxGuest::query()->first())
            ->assertActionHidden('refresh');
    }

    public function test_the_nodes_page_shows_each_node_and_its_graphs(): void
    {
        $this->cluster();
        $staff = $this->staff();

        $this->livewireAs($staff)->test(ProxmoxNodes::class)
            ->assertSee('vega')->assertSee('pegasus')->assertSee('offline')
            ->assertSee('20%') // the last CPU point
            ->assertSeeHtml('<polyline');
    }

    public function test_a_graph_scales_its_points(): void
    {
        $this->assertSame('0,54 150,48 300,0', ProxmoxNodes::points([10, 20, 100], 100));
        $this->assertSame('', ProxmoxNodes::points([null, null]));
    }
}
