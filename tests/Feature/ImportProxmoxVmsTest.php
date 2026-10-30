<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Property;
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
 * WHMCS's Proxmox VPS (docs/proxmox.md A3): the import keeps each one's VMID on its service (no network), and the
 * cluster's first read here maps its guest by it, once; staff's mapping is never redone; the live ones WHMCS's module
 * never knew are listed for staff.
 */
class ImportProxmoxVmsTest extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        $answers = [
            'version' => ['version' => '9.0.3'],
            'nodes' => [['node' => 'vega', 'status' => 'online']],
            'cluster/resources' => [
                ['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps1', 'status' => 'running'],
                ['vmid' => 102, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps2', 'status' => 'running'],
                ['vmid' => 103, 'type' => 'qemu', 'node' => 'vega', 'name' => 'ours', 'status' => 'running'],
            ],
        ];
        Http::fake(function (Request $request) use ($answers) {
            $path = strtok(substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/')), '?');

            return Http::response(['data' => $answers[$path] ?? []]);
        });
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE mod_pvewhmcs_vms (id INTEGER PRIMARY KEY, vmid INT)');
        $this->whmcs->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, servertype TEXT)');
    }

    private function cluster(): Server
    {
        $server = Server::query()->create(['name' => 'PVE cluster', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => self::VEGA, 'token_id' => 'mycs@pve!api', 'token_secret' => 's3cret']);

        return $server->fresh();
    }

    private function vps(): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
    }

    public function test_the_import_keeps_the_vmid_and_the_first_read_maps_the_guest_once(): void
    {
        [$a, $b, $manual] = [$this->vps(), $this->vps(), $this->vps()];
        $this->whmcs->prepare('INSERT INTO tblproducts VALUES (?, ?)')->execute([$a->product_id, 'pvewhmcs']);
        foreach ([$b, $manual] as $service) {
            $this->whmcs->prepare('INSERT OR IGNORE INTO tblproducts VALUES (?, ?)')->execute([$service->product_id, 'pvewhmcs']);
        }
        $this->whmcs->prepare('INSERT INTO mod_pvewhmcs_vms VALUES (?, 101), (?, 102)')->execute([$a->id, $b->id]);

        $result = (new WhmcsProxmoxImport($this->whmcs))->run();

        $this->assertSame(['vms' => 2, 'kept' => 2, 'unmapped' => 1], $result['counts']);
        $this->assertStringContainsString("service #{$manual->id}", implode("\n", $result['notes']), 'made by hand in WHMCS: for staff');
        $this->assertSame('101', Property::query()->where('model_id', $a->id)->where('key', WhmcsProxmoxImport::WHMCS_VMID)->value('value'));

        // Staff map 102 to another service before the first read: theirs stays.
        $server = $this->cluster();
        $other = $this->vps();
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 102, 'service_id' => $other->id]);

        $read = GuestSync::sync($server);

        $this->assertSame(1, $read['mapped']);
        $this->assertSame($a->id, ProxmoxGuest::query()->where('vmid', 101)->value('service_id'), 'its VMID in WHMCS: its guest');
        $this->assertSame($server->id, $a->fresh()->server_id, 'the cluster is its server');
        $this->assertSame($other->id, ProxmoxGuest::query()->where('vmid', 102)->value('service_id'), 'staff\'s mapping never redone');
        $this->assertNull(ProxmoxGuest::query()->where('vmid', 103)->value('service_id'));
        $this->assertFalse(Property::query()->where('model_id', $a->id)->where('key', WhmcsProxmoxImport::WHMCS_VMID)->exists(), 'once: the VMID goes');

        // Staff clear it: the next read does not map it again.
        ProxmoxGuest::query()->where('vmid', 101)->update(['service_id' => null]);
        GuestSync::sync($server);
        $this->assertNull(ProxmoxGuest::query()->where('vmid', 101)->value('service_id'));
    }

    public function test_a_whmcs_without_the_module_imports_nothing(): void
    {
        $result = (new WhmcsProxmoxImport(new PDO('sqlite::memory:')))->run();

        $this->assertSame(['vms' => 0, 'kept' => 0, 'unmapped' => 0], $result['counts']);
    }
}
