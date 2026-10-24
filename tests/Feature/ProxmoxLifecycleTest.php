<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Models\EmailLog;
use App\Models\ModuleAction;
use App\Models\ProxmoxGuest;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Proxmox\GuestLifecycle;
use App\Services\Proxmox\GuestSync;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use RuntimeException;
use Tests\TestCase;

/**
 * Proxmox PR 2 (owner 2026-10-24): suspend = stop, unsuspend = start, terminate = destroy, only on the guest mapped to
 * the service, in MyCS's pool, still the same machine.
 */
class ProxmoxLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    private const UPID = 'UPID:vega:0000ABCD:0001:6700:qmshutdown:101:mycs@pve!api:';

    /** @var array<string, mixed> the guest as the cluster lists it */
    private array $vm = ['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps1', 'status' => 'running', 'pool' => 'mycs'];

    /** @var array<string, mixed> */
    private array $config = ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0', 'onboot' => 1];

    private string $taskStatus = 'stopped';

    private string $taskExit = 'OK';

    /** @var list<string> «METHOD path form» of every change sent */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $path = substr($path, strlen('/api2/json/'));
            if ($request->method() !== 'GET') {
                parse_str($request->body(), $form);
                $query = (string) parse_url($request->url(), PHP_URL_QUERY);
                $this->writes[] = trim($request->method() . ' ' . $path . ($query !== '' ? '?' . $query : '') . ' ' . http_build_query($form));
                if ($request->method() === 'PUT') {
                    $this->config = array_merge($this->config, $form);

                    return Http::response(['data' => null]);
                }

                return Http::response(['data' => self::UPID]);
            }

            return match (true) {
                $path === 'cluster/resources' => Http::response(['data' => array_values(array_filter([$this->vm]))]),
                $path === 'nodes/vega/qemu/101/config' => Http::response(['data' => $this->config]),
                str_starts_with($path, 'nodes/vega/tasks/') => Http::response(['data' => ['status' => $this->taskStatus, 'exitstatus' => $this->taskExit]]),
                default => Http::response(['data' => null], 500),
            };
        });
    }

    private function mapped(array $guest = [], string $terminate = 'destroy'): Service
    {
        $server = Server::query()->create(['name' => 'PVE', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => self::VEGA, 'token_id' => 'mycs@pve!api', 'token_secret' => 's3cret', 'pool' => 'mycs', 'terminate' => $terminate]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        ProxmoxGuest::query()->create(array_merge(['server_id' => $server->id, 'vmid' => 101, 'node' => 'vega', 'name' => 'vps1', 'status' => 'running',
            'pool' => 'mycs', 'macs' => ['BC:24:11:00:00:01'], 'service_id' => $service->id], $guest));

        return $service;
    }

    private function lifecycle(Service $service): GuestLifecycle
    {
        $server = $service->provisioningServer();
        $extension = ExtensionHelper::getExtension('server', 'Proxmox', $server->settings);
        $this->assertInstanceOf(Proxmox::class, $extension);

        return new GuestLifecycle($extension->client(), $server, 'mycs', fn () => null);
    }

    public function test_a_suspension_stops_it_and_keeps_it_off_after_a_reboot_and_the_unsuspension_undoes_it(): void
    {
        $service = $this->mapped();

        ExtensionHelper::suspendServer($service);

        $this->assertSame(['PUT nodes/vega/qemu/101/config onboot=0', 'POST nodes/vega/qemu/101/status/shutdown timeout=60&forceStop=1'], $this->writes);
        $guest = ProxmoxGuest::query()->sole();
        $this->assertSame([true, 'stopped'], [$guest->onboot_before_suspend, $guest->status]);

        $this->writes = [];
        $this->vm['status'] = 'stopped';
        ExtensionHelper::unsuspendServer($service);

        $this->assertSame(['PUT nodes/vega/qemu/101/config onboot=1', 'POST nodes/vega/qemu/101/status/start'], $this->writes);
        $this->assertNull($guest->fresh()->onboot_before_suspend);
    }

    public function test_a_guest_that_did_not_start_at_boot_is_left_so(): void
    {
        $service = $this->mapped();
        $this->config['onboot'] = 0;
        $this->vm['status'] = 'stopped';

        ExtensionHelper::suspendServer($service);
        ExtensionHelper::unsuspendServer($service);

        $this->assertSame(['POST nodes/vega/qemu/101/status/start'], $this->writes, 'no shutdown of a stopped VM, no onboot set');
    }

    public function test_a_retried_suspension_keeps_the_guests_own_onboot(): void
    {
        $service = $this->mapped();
        $this->taskStatus = 'running';
        try {
            $this->lifecycle($service)->suspend($service);
            $this->fail('still shutting down');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('still shutting down', $e->getMessage());
        }
        $this->taskStatus = 'stopped';
        $this->vm['status'] = 'stopped';

        $this->lifecycle($service)->suspend($service); // the retry: onboot is 0 by now

        $this->assertTrue(ProxmoxGuest::query()->sole()->onboot_before_suspend);
    }

    public function test_a_failed_task_is_said(): void
    {
        $service = $this->mapped();
        $this->taskExit = 'VM quit/powerdown failed';

        $this->expectExceptionMessage('VM quit/powerdown failed');
        $this->lifecycle($service)->suspend($service);
    }

    public function test_a_termination_destroys_it_with_its_disks_once(): void
    {
        $service = $this->mapped();

        ExtensionHelper::terminateServer($service);

        $this->assertSame(['POST nodes/vega/qemu/101/status/stop', 'DELETE nodes/vega/qemu/101?purge=1&destroy-unreferenced-disks=1'], $this->writes);
        $guest = ProxmoxGuest::query()->sole();
        $this->assertSame('destroyed', $guest->status);
        $this->assertNotNull($guest->gone_at);
        $this->assertSame($service->id, $guest->service_id, 'kept, for its history');

        $this->writes = [];
        $this->vm = ['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'someone-else', 'status' => 'running', 'pool' => 'mycs'];
        $this->lifecycle($service)->terminate($service); // a retry, the VMID taken by another VM since

        $this->assertSame([], $this->writes, 'never looked up again by its VMID');
    }

    public function test_by_default_a_termination_only_stops_it_and_leaves_the_destroy_to_staff(): void
    {
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $service = $this->mapped(terminate: 'stop');
        $service->provisioningServer()->settings()->where('key', 'terminate')->delete(); // a server saved before the setting

        ExtensionHelper::terminateServer($service);

        $this->assertSame(['PUT nodes/vega/qemu/101/config onboot=0', 'POST nodes/vega/qemu/101/status/stop'], $this->writes, 'no destroy');
        $this->assertSame('stopped', ProxmoxGuest::query()->sole()->status);
        $task = ModuleAction::query()->open()->where('service_id', $service->id)->sole();
        $this->assertSame([ModuleAction::SUBJECT_BY_HAND, 'terminate'], [$task->subject, $task->action]);
        $this->assertStringContainsString('qm destroy 101 --purge', (string) $task->last_error);
        $this->assertTrue(EmailLog::query()->where('subject', 'like', 'By hand: terminate service #' . $service->id . '%')->exists());
    }

    public function test_the_stop_failsafe_is_refused_on_another_machine_too(): void
    {
        $service = $this->mapped(terminate: 'stop');
        $this->vm['pool'] = 'other';

        try {
            ExtensionHelper::terminateServer($service);
            $this->fail('outside the pool');
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->writes);
        $this->assertNull(ModuleAction::query()->where('subject', ModuleAction::SUBJECT_BY_HAND)->first());
    }

    public function test_a_guest_the_last_read_saw_gone_is_not_destroyed_by_its_vmid(): void
    {
        $service = $this->mapped(['gone_at' => now(), 'status' => 'gone']);

        $this->lifecycle($service)->terminate($service);

        $this->assertSame([], $this->writes);
        $this->assertSame('destroyed', ProxmoxGuest::query()->sole()->status);
    }

    public function test_nothing_is_done_outside_the_pool(): void
    {
        $service = $this->mapped();
        $this->vm['pool'] = null;

        foreach (['suspend', 'unsuspend', 'terminate'] as $action) {
            try {
                $this->lifecycle($service)->{$action}($service);
                $this->fail("{$action} outside the pool");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('pvesh set /pools --poolid mycs --vms 101', $e->getMessage());
            }
        }
        $this->assertSame([], $this->writes);
    }

    public function test_nothing_is_done_to_another_machine_under_the_same_vmid(): void
    {
        $service = $this->mapped();
        $this->config['net0'] = 'virtio=BC:24:11:99:99:99,bridge=vmbr0';

        foreach (['suspend', 'terminate'] as $action) {
            try {
                $this->lifecycle($service)->{$action}($service);
                $this->fail($action);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('MACs changed', $e->getMessage());
            }
        }
        $this->assertSame([], $this->writes);
    }

    public function test_a_destroy_needs_a_known_mac(): void
    {
        $service = $this->mapped(['macs' => null]);

        $this->expectExceptionMessage('no MAC known');
        $this->lifecycle($service)->terminate($service);
    }

    public function test_an_internal_or_unmapped_guest_is_never_touched(): void
    {
        $service = $this->mapped(['service_id' => null, 'internal' => true]);

        try {
            ExtensionHelper::suspendServer($service);
            $this->fail('refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No Proxmox guest is mapped', $e->getMessage()); // the job's module action, for staff
        }

        $this->assertSame([], $this->writes);
    }

    public function test_two_guests_mapped_to_one_service_are_refused(): void
    {
        $service = $this->mapped();
        ProxmoxGuest::query()->create(['server_id' => $service->provisioningServer()->id, 'vmid' => 102, 'service_id' => $service->id, 'macs' => ['BC:24:11:00:00:02']]);

        $this->expectExceptionMessage('2 Proxmox guests are mapped');
        $this->lifecycle($service)->suspend($service);
    }

    public function test_the_sync_keeps_the_pool(): void
    {
        $service = $this->mapped();

        GuestSync::sync($service->provisioningServer());

        $this->assertSame('mycs', ProxmoxGuest::query()->sole()->pool);
    }

    public function test_staff_power_buttons(): void
    {
        $service = $this->mapped();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('vm_reboot')->assertNotified();
        $this->assertSame(['POST nodes/vega/qemu/101/status/reboot'], $this->writes);

        $service->forceFill(['status' => Service::STATUS_SUSPENDED])->save();
        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertActionHidden('vm_start')->assertActionHidden('vm_reboot')->assertActionVisible('vm_stop');
    }
}
