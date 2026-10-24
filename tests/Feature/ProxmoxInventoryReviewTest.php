<?php

namespace Tests\Feature;

use App\Admin\Pages\ProxmoxNodes;
use App\Admin\Resources\ProxmoxGuestResource\Pages\ListProxmoxGuests;
use App\Helpers\ExtensionHelper;
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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Independent review of #446 (Proxmox inventory). Each test names a defect found in review and fails on the PR's code.
 */
class ProxmoxInventoryReviewTest extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    private const PEGASUS = 'https://pegasus.example.test:8006';

    /** @var array<string, mixed> path => data */
    private array $answers = [];

    /** @var array<string, int> base => HTTP status it answers everything with */
    private array $failing = [];

    /** @var list<string> */
    private array $sentTo = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->answers = [
            'version' => ['version' => '9.0.3'],
            'nodes' => [['node' => 'vega', 'status' => 'online', 'maxcpu' => 32, 'mem' => 1 << 34, 'maxmem' => 1 << 37, 'uptime' => 86400]],
            'nodes/vega/rrddata' => [['time' => 1, 'cpu' => 0.10]],
            'cluster/resources' => [
                ['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'web1', 'status' => 'stopped'],
                ['vmid' => 102, 'type' => 'qemu', 'node' => 'vega', 'name' => 'web2', 'status' => 'stopped'],
            ],
            'nodes/vega/qemu/101/config' => ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0'],
            'nodes/vega/qemu/102/config' => ['net0' => 'virtio=BC:24:11:00:00:02,bridge=vmbr0'],
        ];
        Http::fake(function (Request $request) {
            $this->sentTo[] = $request->url();
            foreach ($this->failing as $base => $status) {
                if (str_starts_with($request->url(), $base)) {
                    return Http::response('<html>Service Unavailable</html>', $status);
                }
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

    private function service(): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
    }

    /**
     * Proxmox gives a new guest the lowest free VMID: a customer's VM deleted, its VMID is the next one created. The row
     * (server_id, vmid) is found again and its gone_at cleared, but the old customer's service stays mapped to a different
     * machine (other name, other MAC), silently; the actions of the next PRs would act on it.
     */
    public function test_a_reused_vmid_does_not_inherit_the_deleted_guests_mapping(): void
    {
        $server = $this->cluster();
        GuestSync::sync($server);
        $service = $this->service();
        ProxmoxGuest::query()->where('vmid', 101)->update(['service_id' => $service->id]);

        array_shift($this->answers['cluster/resources']); // 101 deleted on the cluster
        GuestSync::sync($server);
        $this->assertNotNull(ProxmoxGuest::query()->where('vmid', 101)->value('gone_at'));

        // Someone else's new VM gets 101.
        $this->answers['cluster/resources'][] = ['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'another-customer', 'status' => 'stopped'];
        $this->answers['nodes/vega/qemu/101/config'] = ['net0' => 'virtio=BC:24:11:99:99:99,bridge=vmbr0'];
        GuestSync::sync($server);

        $this->assertNull(ProxmoxGuest::query()->where('vmid', 101)->sole()->service_id,
            'a different machine under a reused VMID must not keep the deleted guest\'s service');
    }

    /**
     * A token whose ACL was removed (privsep token without PVEAuditor) or a node answering with an empty list gets a 200
     * with data: [] from /cluster/resources. The sync then marks every guest of the cluster gone, the opposite of «a
     * failed read marks nothing gone».
     */
    public function test_an_empty_listing_does_not_mark_the_whole_cluster_gone(): void
    {
        $server = $this->cluster();
        GuestSync::sync($server);

        $this->answers['cluster/resources'] = [];
        $this->answers['nodes'] = []; // a token without its ACL sees no node either (round 2: then refused)
        try {
            GuestSync::sync($server);
        } catch (\Throwable) {
            // refusing the read is fine too
        }

        $this->assertSame(0, ProxmoxGuest::query()->whereNotNull('gone_at')->count());
        $this->assertSame(0, ProxmoxGuest::query()->where('status', 'gone')->count());
    }

    /**
     * A node that is up but whose pveproxy answers 502/503/504 (restarting, or a reverse proxy in front of a dead
     * node) is not «answering»: the call should go to the next node, as for a node that cannot be reached.
     */
    public function test_a_node_answering_503_fails_over_to_the_next_one(): void
    {
        $server = $this->cluster();
        $this->failing = [self::VEGA => 503];

        $result = GuestSync::sync($server);

        $this->assertSame(2, $result['seen']);
    }

    /**
     * A deleted server (soft delete) is no longer synced, but its guests stay in Proxmox Guests as «Not mapped»
     * forever, and in the nodes page's «not mapped» badge.
     */
    public function test_the_guests_of_a_deleted_server_leave_the_list(): void
    {
        $server = $this->cluster();
        GuestSync::sync($server);
        $server->delete();

        $this->assertTrue($server->fresh()?->trashed() ?? true);
        $this->livewireAs($this->staff())->test(ListProxmoxGuests::class)
            ->filterTable('mapping', 'unmapped')
            ->assertCanNotSeeTableRecords(ProxmoxGuest::query()->get());
    }

    /**
     * Node names repeat across clusters (a standalone node is «pve» by default): the «N not mapped» badge counts every
     * cluster's guests on a node of that name, so a fully mapped cluster shows another one's count.
     */
    public function test_the_not_mapped_badge_counts_the_nodes_own_cluster_only(): void
    {
        $one = $this->cluster('One');
        $two = $this->cluster('Two');
        GuestSync::sync($one);
        GuestSync::sync($two);
        ProxmoxGuest::query()->where('server_id', $two->id)->update(['internal' => true]);

        $html = $this->livewireAs($this->staff())->test(ProxmoxNodes::class)->html();

        $this->assertSame(1, substr_count($html, '2 not mapped'), 'only cluster One has guests not mapped');
    }

    // ---- checks that the review expected to hold (kept as regression guards) ----

    public function test_a_new_node_address_needs_the_secret_typed_again(): void
    {
        $server = $this->cluster();
        $form = ExtensionHelper::settingsForForm($server);

        foreach (['node2_host' => 'https://evil.example.test:8006', 'node3_host' => 'https://evil.example.test:8006',
            'node2_ip' => '198.51.100.66', 'node3_ip' => '198.51.100.66'] as $key => $value) {
            try {
                ExtensionHelper::withStoredSecrets($server, [$key => $value] + $form);
                $this->fail("{$key} changed: the stored secret was filled in");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('data.settings.token_secret', $e->errors());
            }
        }
    }

    public function test_a_reader_can_neither_map_nor_refresh(): void
    {
        $server = $this->cluster();
        GuestSync::sync($server);
        $guest = ProxmoxGuest::query()->where('vmid', 101)->sole();
        $service = $this->service();
        $this->sentTo = [];
        $reader = $this->staff(['admin.servers.viewAny']);

        $page = $this->livewireAs($reader)->test(ListProxmoxGuests::class);
        foreach ([['assign', ['service_id' => $service->id]], ['internal', ['note' => 'x']], ['unmap', []]] as [$action, $data]) {
            try {
                $page->callTableAction($action, $guest, $data);
            } catch (\Throwable) {
            }
        }
        try {
            $page->callAction('refresh');
        } catch (\Throwable) {
        }

        $guest->refresh();
        $this->assertSame([null, false], [$guest->service_id, $guest->internal]);
        $this->assertSame([], $this->sentTo, 'no read of the cluster');
    }
}
