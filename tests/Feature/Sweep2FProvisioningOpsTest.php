<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\UpgradeJob;
use App\Models\ModuleAction;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\DeleteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial verification, flow «f-provisioning-ops»: what reaches staff when a module leaves a step to them, and a
 * create that is not idempotent.
 */
class Sweep2FProvisioningOpsTest extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    private const UPID = 'UPID:vega:0000ABCD:0001:6700:qmstop:101:mycs@pve!api:';

    /** @var list<string> */
    private array $daUsernames = [];

    private function proxmoxService(string $status, string $terminate = 'stop'): Service
    {
        Http::fake(function (Request $request) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/'));
            if ($request->method() !== 'GET') {
                return Http::response(['data' => $request->method() === 'PUT' ? null : self::UPID]);
            }

            return match (true) {
                $path === 'cluster/resources' => Http::response(['data' => [['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps1', 'status' => 'running', 'pool' => 'mycs']]]),
                $path === 'nodes/vega/qemu/101/config' => Http::response(['data' => ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0', 'onboot' => 1]]),
                str_starts_with($path, 'nodes/vega/tasks/') => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
                default => Http::response(['data' => null], 500),
            };
        });
        $server = Server::query()->create(['name' => 'PVE', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => self::VEGA, 'token_id' => 'mycs@pve!api', 'token_secret' => 's3cret', 'pool' => 'mycs', 'terminate' => $terminate]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
    }

    /**
     * The delete of a service whose termination leaves a task for staff (owner's call, no migration): not deleted, kept
     * terminated with the task open; a later Delete is refused until staff close it.
     */
    private function deleteKeepsTheTask(Service $service): void
    {
        try {
            app(DeleteService::class)->handle($service, true);
            $this->fail('the delete should keep the service while its by-hand task is open');
        } catch (DisplayException $e) {
            $this->assertStringContainsString(__('services.delete_open_task'), $e->getMessage());
        }
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()?->status, 'kept, terminated');
        $this->assertSame(1, ModuleAction::query()->open()->where('service_id', $service->id)->where('subject', ModuleAction::SUBJECT_BY_HAND)
            ->where('action', 'terminate')->count(), 'the by-hand terminate task staff need is still in Pending Module Actions');

        try {
            app(DeleteService::class)->handle($service->fresh());
            $this->fail('a later Delete is refused while the task is open');
        } catch (DisplayException $e) {
            $this->assertSame(__('services.delete_open_task'), $e->getMessage());
        }
        $this->assertNotNull($service->fresh());

        ModuleAction::query()->open()->where('service_id', $service->id)->update(['status' => ModuleAction::STATUS_RESOLVED, 'resolved_at' => now()]);
        app(DeleteService::class)->handle($service->fresh());
        $this->assertNull(Service::query()->find($service->id), 'deleted once staff closed the task');
    }

    /**
     * F-PROVISIONING-OPS-1 (create). Proxmox has no createServer and its first create is staff's by hand (docs/proxmox.md):
     * the paid service goes active with no task for staff in Pending Module Actions, unlike an OfflineVm's create.
     */
    public function test_f1_a_paid_proxmox_vps_create_leaves_a_task_for_staff(): void
    {
        $service = $this->proxmoxService('pending');

        (new CreateJob($service, false))->handle();

        $this->assertSame('active', $service->fresh()->status, 'the service started (its period runs)');
        $this->assertSame(1, ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'create')->count(),
            'a paid VPS with no VM made: a «create by hand» task for staff, as for OfflineVm');
    }

    /**
     * F-PROVISIONING-OPS-1 (upgrade). A paid plan or option change on Proxmox (no upgradeServer): nothing changes on the
     * VM and nothing reaches staff.
     */
    public function test_f1_a_paid_proxmox_upgrade_leaves_a_task_for_staff(): void
    {
        $service = $this->proxmoxService('active');

        (new UpgradeJob($service, false))->handle();

        $this->assertSame(1, ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'upgrade')->count(),
            'a paid upgrade the VM never got: an «upgrade by hand» task for staff');
    }

    /**
     * F-PROVISIONING-OPS-2. Delete with «Also trigger deletion of server» on Proxmox «Terminate: stop»: the by-hand
     * «destroy it» task is created, and the service is kept (terminated) so the task is not cascaded away with it.
     */
    public function test_f2_the_by_hand_destroy_task_survives_the_delete_of_its_proxmox_service(): void
    {
        $service = $this->proxmoxService('active');
        ProxmoxGuest::query()->create(['server_id' => $service->provisioningServer()->id, 'vmid' => 101, 'node' => 'vega', 'name' => 'vps1',
            'status' => 'running', 'pool' => 'mycs', 'macs' => ['BC:24:11:00:00:01'], 'service_id' => $service->id]);

        $this->deleteKeepsTheTask($service);
        $this->assertNull(ProxmoxGuest::query()->sole()->service_id, 'its VM, stopped and not destroyed, is unmapped once deleted');
    }

    /** The same for a machine with no API (OfflineVm): its terminate task stays, and so does the service. */
    public function test_f2_the_by_hand_terminate_task_survives_the_delete_of_an_offline_vm_service(): void
    {
        $server = Server::query()->create(['name' => 'OfflineVm', 'extension' => 'OfflineVm', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1,
            'domain' => 'vm.example.test', 'dedicated_ip' => '203.0.113.21']);

        $this->deleteKeepsTheTask($service);
    }

    /**
     * F-PROVISIONING-OPS-3. DirectAdmin's create draws a new username on each attempt and keeps nothing before the call:
     * an answer lost after the account was made leaves it under a username MyCS never stored, and every retry asks for
     * another one, which DirectAdmin refuses (the domain exists).
     */
    public function test_f3_a_directadmin_create_retried_after_a_lost_answer_keeps_its_first_username(): void
    {
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/CMD_API_SHOW_RESELLER_IPS') {
                return Http::response('list[]=203.0.113.30');
            }
            if ($path === '/CMD_API_ACCOUNT_USER') {
                $this->daUsernames[] = (string) ($request->data()['username'] ?? '');
                if ($calls++ === 0) {
                    throw new ConnectionException('cURL error 28: Operation timed out'); // made on DirectAdmin, the answer lost
                }

                return Http::response('error=1&text=Unable to Create&details=That domain already exists');
            }

            return Http::response('error=1&text=unexpected', 500);
        });
        $server = Server::query()->create(['name' => 'DA', 'extension' => 'DirectAdmin', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => 'https://da.example.test:2222', 'username' => 'admin', 'password' => 'pw']);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'basic']);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'pending', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1,
            'domain' => 'site.example.test']);
        $service->properties()->create(['key' => 'domain', 'name' => 'Domain', 'value' => 'site.example.test']);

        foreach ([1, 2, 3] as $attempt) { // the job's first try, its retry, staff's Retry
            try {
                ExtensionHelper::createServer($service->fresh(), whilePending: true);
            } catch (Throwable) {
            }
        }

        $this->assertCount(3, $this->daUsernames, 'three attempts reached DirectAdmin');
        $this->assertSame('pending', $service->fresh()->status, 'paid, still pending');
        $this->assertNull($service->fresh()->properties()->where('key', 'directadmin_username')->value('value'), 'MyCS never stored the account it made');
        $this->assertSame([$this->daUsernames[0]], array_values(array_unique($this->daUsernames)),
            'each retry should ask again for the account the first attempt made, not draw a new username: '
            . implode(', ', $this->daUsernames));
    }
}
