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

/**
 * Adversarial review of #548 (WHMCS's Proxmox VMIDs mapped on the cluster's first read): a service must never be
 * mapped to a guest that is not its VM, staff's choices are never redone, an ended service gets no VM to act on.
 */
class ImportProxmoxVmsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    private const ALTAIR = 'https://altair.example.test:8006';

    private PDO $whmcs;

    /** @var array<string, list<array<string, mixed>>> host => cluster/resources */
    private array $guests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->guests = [
            'vega.example.test' => [
                ['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps1', 'status' => 'running'],
                ['vmid' => 102, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps2', 'status' => 'running'],
            ],
            'altair.example.test' => [
                ['vmid' => 101, 'type' => 'qemu', 'node' => 'altair', 'name' => 'their-vps', 'status' => 'running'],
            ],
        ];
        Http::fake(function (Request $request) {
            $host = (string) parse_url($request->url(), PHP_URL_HOST);
            $path = strtok(substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/')), '?');
            $answers = [
                'version' => ['version' => '9.0.3'],
                'nodes' => [['node' => explode('.', $host)[0], 'status' => 'online']],
                'cluster/resources' => $this->guests[$host] ?? [],
            ];

            return Http::response(['data' => $answers[$path] ?? []]);
        });
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE mod_pvewhmcs_vms (id INTEGER PRIMARY KEY, vmid INT)');
        $this->whmcs->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, servertype TEXT)');
    }

    private function cluster(string $host = self::VEGA, string $name = 'PVE cluster'): Server
    {
        $server = Server::query()->create(['name' => $name, 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => $host, 'token_id' => 'mycs@pve!api', 'token_secret' => 's3cret']);

        return $server->fresh();
    }

    private function vps(string $status = 'active'): Service
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        $this->whmcs->prepare('INSERT OR IGNORE INTO tblproducts VALUES (?, ?)')->execute([$service->product_id, 'pvewhmcs']);

        return $service;
    }

    /** @param  array<int, int>  $vms  service id => vmid, in WHMCS's row order */
    private function import(array $vms): void
    {
        foreach ($vms as $id => $vmid) {
            $this->whmcs->prepare('INSERT INTO mod_pvewhmcs_vms VALUES (?, ?)')->execute([$id, $vmid]);
        }
        (new WhmcsProxmoxImport($this->whmcs))->run();
    }

    /**
     * ADV-548-1, High. A terminated VPS keeps its row in mod_pvewhmcs_vms (the module does not delete it on terminate),
     * and Proxmox hands the lowest free VMID to the next VM: VM 101 today is a live machine (a hand-made VPS, ours).
     * The first read maps it to the terminated service and makes the cluster its server.
     * Expected: an ended service (terminated, cancelled) is never given a VM: guest 101 stays unmapped.
     */
    public function test_adv_548_1_an_ended_service_is_never_given_the_vm_now_at_its_old_vmid(): void
    {
        $terminated = $this->vps('terminated');
        $cancelled = $this->vps('cancelled');
        $this->import([$terminated->id => 101, $cancelled->id => 102]);
        $server = $this->cluster();

        GuestSync::sync($server);

        $this->assertNull(ProxmoxGuest::query()->where('vmid', 101)->value('service_id'), 'a terminated service has no VM to act on');
        $this->assertNull(ProxmoxGuest::query()->where('vmid', 102)->value('service_id'), 'a cancelled service has no VM to act on');
        $this->assertNull($terminated->fresh()->server_id);
    }

    /**
     * ADV-548-2, High. VMID reuse inside WHMCS: an old terminated VPS (lower row) and today's active VPS both carry
     * VMID 101. The properties are walked in row order, so the terminated one takes guest 101 and the live customer's
     * service gets nothing (its suspend/terminate later finds «no guest mapped», or staff map by hand).
     * Expected: the live service gets its VM; the ended one gets none.
     */
    public function test_adv_548_2_a_vmid_reused_in_whmcs_goes_to_the_live_service_not_the_old_one(): void
    {
        $old = $this->vps('terminated');
        $live = $this->vps('active');
        $live->forceFill(['domain' => 'vps1'])->save(); // its VM by its name too (code review 1 of #548: a VMID alone is not enough)
        $this->import([$old->id => 101, $live->id => 101]);

        GuestSync::sync($this->cluster());

        $this->assertSame($live->id, ProxmoxGuest::query()->where('vmid', 101)->value('service_id'));
    }

    /**
     * ADV-548-3, High. Two live services claim the same VMID (a stale row in WHMCS's module: a VPS recreated, its old
     * row on a still-suspended service). The first property wins silently: one of them is given the other's VM, and a
     * suspend of that service stops another customer's machine.
     * Expected: an ambiguous VMID is mapped to neither; left to staff.
     */
    public function test_adv_548_3_a_vmid_two_live_services_claim_is_mapped_to_neither(): void
    {
        $a = $this->vps('suspended');
        $b = $this->vps('active');
        $this->import([$a->id => 101, $b->id => 101]);

        GuestSync::sync($this->cluster());

        $this->assertNull(ProxmoxGuest::query()->where('vmid', 101)->value('service_id'), 'which one is its VM is staff\'s call');
    }

    /**
     * ADV-548-4, High. The VMID is kept until it matches, forever: the first read of the cluster finds no guest 103
     * (the VM was deleted before the cut-over), so the property stays; months later Proxmox's next VM gets VMID 103
     * (the lowest free one) and the cron's read maps that new machine to the old service.
     * Expected: WHMCS's VMID is for the cluster's first read only; a VM made later is never mapped by it.
     */
    public function test_adv_548_4_a_vm_made_later_at_the_old_vmid_is_not_mapped_by_the_stale_whmcs_vmid(): void
    {
        $service = $this->vps('active');
        $this->import([$service->id => 103]);
        $server = $this->cluster();
        GuestSync::sync($server); // no 103 on the cluster: nothing mapped

        $this->guests['vega.example.test'][] = ['vmid' => 103, 'type' => 'qemu', 'node' => 'vega', 'name' => 'new-internal-box', 'status' => 'running'];
        GuestSync::sync($server);

        $this->assertNull(ProxmoxGuest::query()->where('vmid', 103)->value('service_id'), 'a VM made after the first read is not the WHMCS one');
    }

    /**
     * ADV-548-5, High. Staff mapped the service to its real VM (102: recreated in WHMCS's time, the module's row stale
     * at 101). mapFromWhmcs skips it but keeps the property; when staff later clear that mapping (moving, fixing), the
     * next cron maps the service to 101, another machine.
     * Expected: staff's mapping of the service ends WHMCS's VMID; it is never applied after.
     */
    public function test_adv_548_5_staffs_mapping_of_the_service_ends_the_whmcs_vmid(): void
    {
        $service = $this->vps('active');
        $this->import([$service->id => 101]);
        $server = $this->cluster();
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 102, 'service_id' => $service->id]);
        GuestSync::sync($server);
        $this->assertNull(ProxmoxGuest::query()->where('vmid', 101)->value('service_id'));

        ProxmoxGuest::query()->where('vmid', 102)->update(['service_id' => null]); // staff clear theirs
        GuestSync::sync($server);

        $this->assertNull(ProxmoxGuest::query()->where('vmid', 101)->value('service_id'), 'staff\'s choice is not redone with WHMCS\'s VMID');
    }

    /**
     * ADV-548-6, Medium. Two clusters (VMIDs are per cluster: both start at 100) and a VPS with no server (WHMCS's server
     * not imported). Whichever cluster is read first takes it: here altair's 101, another machine, and altair becomes
     * the service's server, so its lifecycle acts there.
     * Expected: with more than one Proxmox cluster, a service with no server is not guessed onto one.
     */
    public function test_adv_548_6_a_service_with_no_server_is_not_guessed_onto_the_first_cluster_read(): void
    {
        $service = $this->vps('active');
        $this->import([$service->id => 101]);
        $vega = $this->cluster(self::VEGA, 'vega');
        $altair = $this->cluster(self::ALTAIR, 'altair');

        GuestSync::sync($altair);

        $this->assertNull(ProxmoxGuest::query()->where('server_id', $altair->id)->where('vmid', 101)->value('service_id'), 'which cluster is unknown');
        $this->assertNull($service->fresh()->server_id);
        $this->assertNotNull($vega->id);
    }
}
