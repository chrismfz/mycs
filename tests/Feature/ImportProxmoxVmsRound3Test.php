<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsProxmoxImport;
use App\Services\Proxmox\GuestSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

/**
 * Round-3 review of #548 (9729d128): an address or a name another live service holds confirms nothing, a used VMID is
 * marked. Can a service still be given a VM that is not its own?
 */
class ImportProxmoxVmsRound3Test extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    private const ALTAIR = 'https://altair.example.test:8006';

    private PDO $whmcs;

    /** @var array<string, list<array<string, mixed>>> host => cluster/resources */
    private array $guests = [];

    /** @var array<string, list<array<string, mixed>>> host => nodes */
    private array $nodes = [];

    /** @var array<string, array<string, mixed>> path => config */
    private array $configs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->guests = [
            'vega.example.test' => [['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps1', 'status' => 'running']],
            'altair.example.test' => [['vmid' => 101, 'type' => 'qemu', 'node' => 'altair', 'name' => 'vps1', 'status' => 'running']],
        ];
        $this->nodes = ['vega.example.test' => [['node' => 'vega', 'status' => 'online']], 'altair.example.test' => [['node' => 'altair', 'status' => 'online']]];
        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $path = strtok(substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/')), '?');
            $answers = ['version' => ['version' => '9.0.3'], 'nodes' => $this->nodes[$host] ?? [], 'cluster/resources' => $this->guests[$host] ?? []];

            return Http::response(['data' => $answers[$path] ?? $this->configs[$path] ?? []]);
        });
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE mod_pvewhmcs_vms (id INTEGER PRIMARY KEY, vmid INT, ipaddress TEXT)');
        $this->whmcs->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, servertype TEXT)');
    }

    private function cluster(string $host = self::VEGA, string $name = 'PVE cluster', bool $enabled = true): Server
    {
        $server = Server::query()->create(['name' => $name, 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => $enabled]);
        ExtensionHelper::saveSettings($server, ['host' => $host, 'token_id' => 'mycs@pve!api', 'token_secret' => 's3cret']);

        return $server->fresh();
    }

    /** @param  array<string, mixed>  $attributes */
    private function vps(array $attributes = []): Service
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1] + $attributes);
        $this->whmcs->prepare('INSERT OR IGNORE INTO tblproducts VALUES (?, ?)')->execute([$service->product_id, 'pvewhmcs']);

        return $service;
    }

    private function row(Service $service, int $vmid, ?string $ip = null): void
    {
        $this->whmcs->prepare('INSERT OR REPLACE INTO mod_pvewhmcs_vms VALUES (?, ?, ?)')->execute([$service->id, $vmid, $ip]);
    }

    private function import(): void
    {
        (new WhmcsProxmoxImport($this->whmcs))->run();
    }

    /**
     * R3-548-1, High. The importer only ever adds WHMCS_VMID: a re-run that no longer keeps a service's VMID leaves the
     * one an earlier run wrote. Here the earlier run saw A alone on VMID 105; by the next run (before the cluster's
     * first read) B claims 105 too. The run notes «none mapped from it, staff map the right one» (ADV-548-3), yet A's
     * VMID and address from the first run are still there, and the read maps guest 105 to A. The same holds when A's
     * module row is gone or A left the module's products by the re-run. Expected: a run that does not keep a VMID
     * removes the one an earlier run kept; guest 105 is staff's to map.
     */
    public function test_r3_548_1_a_rerun_that_finds_two_claims_drops_the_vmid_an_earlier_run_kept(): void
    {
        $a = $this->vps();
        $this->row($a, 105, '203.0.113.50');
        $this->import();
        $b = $this->vps();
        $this->row($b, 105, '203.0.113.60');
        $result = (new WhmcsProxmoxImport($this->whmcs))->run();
        $this->assertStringContainsString('mapped by staff', implode(' ', $result['notes']));
        $this->guests['vega.example.test'] = [['vmid' => 105, 'type' => 'lxc', 'node' => 'vega', 'name' => 'vps105', 'status' => 'running']];
        $this->configs['nodes/vega/lxc/105/config'] = ['net0' => 'name=eth0,hwaddr=BC:24:11:00:00:05,bridge=vmbr0,ip=203.0.113.50/24'];

        GuestSync::sync($this->cluster());

        $guest = ProxmoxGuest::query()->where('vmid', 105)->sole();
        $this->assertNull($guest->service_id, 'two live services claim VMID 105: staff\'s call');
        $this->assertNotSame($a->id, $guest->suggestedServiceId(), 'nor is either one the VMID\'s suggestion');
    }

    /**
     * R3-548-2, High. R2-548-3 with the address among B's other addresses: B (made by hand) has its main address as its
     * dedicated IP and the reused one in «assigned IPs» (WHMCS's tblhosting.assignedips, imported to assigned_ips). The
     * «held» check reads dedicated_ip only, so WHMCS's stale address for A confirms B's machine as A's: a suspend of A
     * stops B. Expected: an address another live service holds, as any of its addresses, confirms nothing.
     */
    public function test_r3_548_2_an_address_in_another_services_assigned_ips_does_not_confirm(): void
    {
        $a = $this->vps();
        $this->vps(['dedicated_ip' => '203.0.113.60', 'assigned_ips' => "203.0.113.50\n203.0.113.51"]);
        $this->row($a, 105, '203.0.113.50');
        $this->import();
        $this->guests['vega.example.test'] = [['vmid' => 105, 'type' => 'lxc', 'node' => 'vega', 'name' => 'b-vps', 'status' => 'running']];
        $this->configs['nodes/vega/lxc/105/config'] = ['net0' => 'name=eth0,hwaddr=BC:24:11:00:00:05,bridge=vmbr0,ip=203.0.113.60/24',
            'net1' => 'name=eth1,hwaddr=BC:24:11:00:01:05,bridge=vmbr0,ip=203.0.113.50/24'];

        GuestSync::sync($this->cluster());

        $this->assertNotSame($a->id, ProxmoxGuest::query()->where('vmid', 105)->value('service_id'), 'the address is B\'s too');
    }

    /**
     * R3-548-3, High. The same with the IP pool, where MyCS records who holds an address (ip_addresses.service_id):
     * IpAllocator writes dedicated_ip only when the service has none, so a second address given to B from the pool is
     * held there only. The «held» check never reads the pool. Expected: an address the pool gives another live service
     * confirms nothing.
     */
    public function test_r3_548_3_an_address_the_pool_gives_another_service_does_not_confirm(): void
    {
        $a = $this->vps();
        $b = $this->vps(['dedicated_ip' => '203.0.113.60']);
        $pool = IpPool::query()->create(['name' => 'VPS', 'family' => 4, 'network' => '203.0.113.0/24', 'prefix' => 24]);
        IpAddress::query()->create(['ip_pool_id' => $pool->id, 'address' => '203.0.113.60', 'service_id' => $b->id, 'assigned_at' => now()]);
        IpAddress::query()->create(['ip_pool_id' => $pool->id, 'address' => '203.0.113.50', 'service_id' => $b->id, 'assigned_at' => now()]);
        $this->row($a, 105, '203.0.113.50');
        $this->import();
        $this->guests['vega.example.test'] = [['vmid' => 105, 'type' => 'lxc', 'node' => 'vega', 'name' => 'b-vps', 'status' => 'running']];
        $this->configs['nodes/vega/lxc/105/config'] = ['net0' => 'name=eth0,hwaddr=BC:24:11:00:00:05,bridge=vmbr0,ip=203.0.113.60/24',
            'net1' => 'name=eth1,hwaddr=BC:24:11:00:01:05,bridge=vmbr0,ip=203.0.113.50/24'];

        GuestSync::sync($this->cluster());

        $this->assertNotSame($a->id, ProxmoxGuest::query()->where('vmid', 105)->value('service_id'), 'the pool gives the address to B');
    }

    /**
     * R3-548-4, Low. IPv6 is written many ways («2001:DB8::50», «2001:db8:0:0::50»); the «held» check compares text, so
     * B's dedicated IP in another form than A's is not seen as the same address, and A's confirms B's machine. Expected:
     * addresses compared normalized (IpRange::normalize), as the pool keeps them.
     */
    public function test_r3_548_4_the_same_ipv6_written_otherwise_is_still_held(): void
    {
        $a = $this->vps(['dedicated_ip' => '2001:db8::50']);
        $this->vps(['dedicated_ip' => '2001:DB8:0:0::50']);
        $this->row($a, 105);
        $this->import();
        $this->guests['vega.example.test'] = [['vmid' => 105, 'type' => 'lxc', 'node' => 'vega', 'name' => 'b-vps', 'status' => 'running']];
        $this->configs['nodes/vega/lxc/105/config'] = ['net0' => 'name=eth0,hwaddr=BC:24:11:00:00:05,bridge=vmbr0,ip6=2001:db8::50/64'];

        GuestSync::sync($this->cluster());

        $this->assertNotSame($a->id, ProxmoxGuest::query()->where('vmid', 105)->value('service_id'), 'the address is B\'s too');
    }

    /**
     * R3-548-5, Low. The «held» check reads the other services' WHMCS_VM_IP, but mapFromWhmcs deletes each service's as
     * it goes: an address two of the module's services claim is «held» only for the one read second. A (lower id, VMID
     * 300) and B (VMID 105) both have 203.0.113.50 in WHMCS; A is read first, its address deleted, and B's confirms guest
     * 105. With the ids the other way round, nothing maps. Expected: the outcome does not hang on the order; an address
     * two services claim confirms neither.
     */
    public function test_r3_548_5_an_address_two_module_services_claim_confirms_neither_whatever_the_order(): void
    {
        $a = $this->vps();
        $b = $this->vps();
        $this->row($a, 300, '203.0.113.50');
        $this->row($b, 105, '203.0.113.50');
        $this->import();
        $this->guests['vega.example.test'] = [['vmid' => 105, 'type' => 'lxc', 'node' => 'vega', 'name' => 'vps105', 'status' => 'running']];
        $this->configs['nodes/vega/lxc/105/config'] = ['net0' => 'name=eth0,hwaddr=BC:24:11:00:00:05,bridge=vmbr0,ip=203.0.113.50/24'];

        GuestSync::sync($this->cluster());

        $this->assertNull(ProxmoxGuest::query()->where('vmid', 105)->value('service_id'), 'A claims the address too: staff\'s call');
    }
}
