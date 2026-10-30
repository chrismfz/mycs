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

    /** Owner 2026-10-30: never mapped automatically; the live VPS's VMID is the staff's suggestion, a re-run drops a stale one. */
    public function test_the_import_keeps_the_vmid_as_staffs_suggestion_never_a_mapping(): void
    {
        [$a, $ended, $manual] = [$this->vps(), $this->vps(), $this->vps()];
        $ended->forceFill(['status' => Service::STATUS_TERMINATED])->save();
        foreach ([$a, $ended, $manual] as $service) {
            $this->whmcs->prepare('INSERT OR IGNORE INTO tblproducts VALUES (?, ?)')->execute([$service->product_id, 'pvewhmcs']);
        }
        $this->whmcs->prepare('INSERT INTO mod_pvewhmcs_vms VALUES (?, 101), (?, 102)')->execute([$a->id, $ended->id]);

        $result = (new WhmcsProxmoxImport($this->whmcs))->run();

        $this->assertSame(['vms' => 2, 'kept' => 1, 'unmapped' => 1], $result['counts'], 'an ended one\'s VMID is not kept');
        $this->assertStringContainsString("service #{$manual->id}", implode("\n", $result['notes']));
        $server = $this->cluster();
        GuestSync::sync($server);
        $this->assertSame(0, ProxmoxGuest::query()->whereNotNull('service_id')->count(), 'nothing mapped by itself');
        $this->assertSame($a->id, ProxmoxGuest::query()->where('vmid', 101)->sole()->suggestedServiceId(), 'its VMID in WHMCS: the suggestion');
        $this->assertNotSame($ended->id, ProxmoxGuest::query()->where('vmid', 102)->sole()->suggestedServiceId());

        // A re-run where WHMCS no longer has its row: no stale suggestion.
        $this->whmcs->exec('DELETE FROM mod_pvewhmcs_vms');
        (new WhmcsProxmoxImport($this->whmcs))->run();
        $this->assertFalse(Property::query()->where('key', WhmcsProxmoxImport::WHMCS_VMID)->exists());
    }

    public function test_a_whmcs_without_the_module_imports_nothing(): void
    {
        $result = (new WhmcsProxmoxImport(new PDO('sqlite::memory:')))->run();

        $this->assertSame(['vms' => 0, 'kept' => 0, 'unmapped' => 0], $result['counts']);
    }
}
