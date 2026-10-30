<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
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
use Throwable;

/**
 * Round-2 adversarial review of #548 (af6c494c): the VMID is used once, confirmed by IP or name, on one cluster. Can a
 * service still be given a VM that is not its own, or lose its VMID before the cluster could be read?
 */
class ImportProxmoxVmsRound2Test extends TestCase
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
     * R2-548-1, High. The importer is re-runnable (docs/importer.md §1) and runs again until the cut-over; each run
     * writes WHMCS_VMID back with updateOrInsert. The first read used it and deleted it ("once, whatever comes of it"),
     * staff then cleared the mapping (not its VM after all); the next import puts the VMID back and the next cron read
     * maps it again: ADV-548-5 (a mapping staff cleared redone) and ADV-548-4 (a VM made since) are back through the
     * importer. Expected: a VMID the cluster's read already used is never used again, whatever the importer re-runs.
     */
    public function test_r2_548_1_an_importer_rerun_does_not_revive_a_used_vmid(): void
    {
        $a = $this->vps(['domain' => 'vps1']);
        $this->row($a, 101);
        $this->import();
        $server = $this->cluster();
        // Owner 2026-10-30: never mapped automatically; WHMCS's VMID is the staff's suggestion only.
        GuestSync::sync($server);
        $this->assertNull(ProxmoxGuest::query()->where('vmid', 101)->value('service_id'), 'never by itself');
        ProxmoxGuest::query()->where('vmid', 101)->update(['service_id' => $a->id]); // staff map it
        ProxmoxGuest::query()->where('vmid', 101)->update(['service_id' => null]); // staff: not its VM
        $this->import(); // the importer's next run before the cut-over
        GuestSync::sync($server); // the cron's next read

        $this->assertNull(ProxmoxGuest::query()->where('vmid', 101)->value('service_id'), 'staff cleared it: never redone');
    }

    /**
     * R2-548-2, Medium. The first read with a token that sees nothing (no PVEAuditor on «/», review of #446): the
     * cluster lists no node and no guest. The «sees nothing» guard only fires when guests were read before, so on the
     * very first read it does not, and mapFromWhmcs deletes every VMID the import kept: all of WHMCS's VPS are left for
     * staff to map by hand once the token is fixed. Expected: a read that saw nothing uses no VMID (they map when the
     * token sees the cluster).
     */
    public function test_r2_548_2_a_first_read_that_sees_nothing_does_not_use_the_vmids(): void
    {
        $a = $this->vps(['domain' => 'vps1']);
        $this->row($a, 101);
        $this->import();
        $server = $this->cluster();
        $guests = $this->guests;
        $this->guests = $this->nodes = []; // the token's ACL missing
        try {
            GuestSync::sync($server);
        } catch (Throwable) {
            // refusing the read is fine too
        }

        $this->guests = $guests;
        $this->nodes = ['vega.example.test' => [['node' => 'vega', 'status' => 'online']]]; // the token fixed
        GuestSync::sync($server);

        $guest = ProxmoxGuest::query()->where('vmid', 101)->sole();
        $this->assertNull($guest->service_id, 'never mapped by itself (owner 2026-10-30)');
        $this->assertSame($a->id, $guest->suggestedServiceId(), 'its VMID still suggests it after a blind read');
    }

    /**
     * R2-548-3, High. IPs are reused like VMIDs: a VPS still active in WHMCS whose VM was deleted (docs: one of ours was
     * found so on 2026-10-30) left VMID 105 and its address free; staff made B's VPS by hand outside the module and
     * Proxmox and the pool gave it both. B's service carries the address as its dedicated IP. The first read finds guest
     * 105 with WHMCS's address for A and maps it to A: a suspend of A (unpaid) stops B's machine. Expected: an address
     * another live service holds confirms nothing; guest 105 is not A's.
     */
    public function test_r2_548_3_an_address_another_live_service_holds_does_not_confirm_the_guest(): void
    {
        $a = $this->vps();
        $b = $this->vps(['dedicated_ip' => '203.0.113.50']); // made by hand: no row in the module
        $this->row($a, 105, '203.0.113.50');
        $this->import();
        $this->guests['vega.example.test'] = [['vmid' => 105, 'type' => 'lxc', 'node' => 'vega', 'name' => 'b-vps', 'status' => 'running']];
        $this->configs['nodes/vega/lxc/105/config'] = ['net0' => 'name=eth0,hwaddr=BC:24:11:00:00:05,bridge=vmbr0,ip=203.0.113.50/24'];

        GuestSync::sync($this->cluster());

        $this->assertNotSame($a->id, ProxmoxGuest::query()->where('vmid', 105)->value('service_id'), 'the address is B\'s too: which VM is A\'s is staff\'s call');
    }

    /**
     * R2-548-4, Medium. The same with the name: domains are not unique in WHMCS (two rows per name happen), and a VPS's
     * «domain» is often a bare hostname. A (VMID 101, domain «vps1») and B (made by hand, domain «VPS1») are both live;
     * guest 101 is named «vps1» and has no address to compare. It is mapped to A, compared case-insensitively, though
     * the name says B as much as A. Expected: a name more than one live service holds confirms nothing.
     */
    public function test_r2_548_4_a_name_two_live_services_hold_does_not_confirm_the_guest(): void
    {
        $a = $this->vps(['domain' => 'vps1']);
        $this->vps(['domain' => 'VPS1']);
        $this->row($a, 101);
        $this->import();

        GuestSync::sync($this->cluster());

        $this->assertNull(ProxmoxGuest::query()->where('vmid', 101)->value('service_id'), 'the name is two services\': staff\'s call');
    }

    /**
     * R2-548-5, Medium. The single-cluster rule counts only enabled servers (GuestSync::servers()). With a second
     * cluster added but disabled (being set up, or switched off for maintenance), the rule passes: WHMCS's VMIDs, some
     * of them altair's machines, are matched against vega's guests, and a generic hostname confirms one (both clusters
     * have a «vps1» at VMID 101). The VMIDs are also used up, so nothing maps on altair later. Expected: any Proxmox
     * server, enabled or not, makes VMIDs ambiguous: nothing mapped.
     */
    public function test_r2_548_5_a_disabled_second_cluster_still_makes_the_vmids_ambiguous(): void
    {
        $a = $this->vps(['domain' => 'vps1']); // its VM is altair's 101
        $this->row($a, 101);
        $this->import();
        $vega = $this->cluster();
        $this->cluster(self::ALTAIR, 'PVE altair', false);

        GuestSync::sync($vega);

        $this->assertNull(ProxmoxGuest::query()->where('server_id', $vega->id)->where('vmid', 101)->value('service_id'), 'which cluster VMID 101 is in is staff\'s call');
    }
}
