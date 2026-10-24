<?php

namespace Tests\Feature;

use App\Admin\Resources\ProxmoxGuestResource\Pages\ListProxmoxGuests;
use App\Helpers\ExtensionHelper;
use App\Models\ModuleAction;
use App\Models\ProxmoxGuest;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Proxmox\GuestLifecycle;
use App\Services\Proxmox\GuestSync;
use Exception;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #447 (Proxmox suspend / unsuspend / terminate): each test asserts the behaviour that keeps the
 * right VM in the right state, and fails on the PR's code.
 */
class ProxmoxLifecycleAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    private const UPID = 'UPID:vega:0000ABCD:0001:6700:qmshutdown:101:mycs@pve!api:';

    /** @var array<string, mixed>|null the guest as /cluster/resources lists it (null: not listed) */
    private ?array $vm = ['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps1', 'status' => 'running', 'pool' => 'mycs'];

    /** @var array<string, mixed> */
    private array $config = ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0', 'onboot' => 1];

    /** What nodes/vega/qemu/101/status/current says: the guest's real state now. */
    private string $current = 'running';

    /** HTTP status of every nodes/vega/qemu/101/* read (403: the token may not see it). */
    private int $guestReads = 200;

    /** @var list<string> */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function (Request $request) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/'));
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
            if (str_starts_with($path, 'nodes/vega/qemu/101') && $this->guestReads !== 200) {
                return Http::response(['data' => null, 'message' => 'Permission check failed'], $this->guestReads);
            }

            return match (true) {
                $path === 'cluster/resources' => Http::response(['data' => array_values(array_filter([$this->vm]))]),
                $path === 'nodes' => Http::response(['data' => [['node' => 'vega', 'status' => 'online']]]),
                $path === 'nodes/vega/qemu/101/config' => Http::response(['data' => $this->config]),
                $path === 'nodes/vega/qemu/101/status/current' => Http::response(['data' => ['status' => $this->current, 'qmpstatus' => $this->current]]),
                str_starts_with($path, 'nodes/vega/tasks/') => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
                default => Http::response(['data' => null], 500),
            };
        });
    }

    private function mapped(array $guest = [], string $terminate = 'destroy', string $pool = 'mycs'): Service
    {
        $server = Server::query()->create(['name' => 'PVE', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => self::VEGA, 'token_id' => 'mycs@pve!api', 'token_secret' => 's3cret', 'pool' => $pool, 'terminate' => $terminate]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        ProxmoxGuest::query()->create(array_merge(['server_id' => $server->id, 'vmid' => 101, 'node' => 'vega', 'name' => 'vps1', 'status' => 'running',
            'pool' => $pool, 'macs' => ['BC:24:11:00:00:01'], 'service_id' => $service->id], $guest));

        return $service;
    }

    private function lifecycle(Service $service): GuestLifecycle
    {
        $server = $service->provisioningServer();
        $extension = ExtensionHelper::getExtension('server', 'Proxmox', $server->settings);
        $this->assertInstanceOf(Proxmox::class, $extension);

        return new GuestLifecycle($extension->client(), $server, 'mycs', fn () => null);
    }

    /**
     * A1. /cluster/resources is pvestatd's cache (refreshed every ~10 s), not the guest's state now: a payment (or staff's
     * «oops, unsuspend») right after a suspension sees the VM still listed «running», starts nothing, and reports the
     * unsuspension done. The paid VM stays off.
     */
    public function test_an_unsuspension_right_after_a_suspension_starts_the_vm_although_the_cached_list_still_says_running(): void
    {
        $service = $this->mapped(['status' => 'stopped', 'onboot_before_suspend' => true]);
        $this->config['onboot'] = 0;
        $this->vm['status'] = 'running'; // stale: the suspension stopped it a few seconds ago
        $this->current = 'stopped';      // the truth

        ExtensionHelper::unsuspendServer($service);

        $this->assertContains('POST nodes/vega/qemu/101/status/start', $this->writes, 'the paid VM is left stopped');
    }

    /**
     * A2. The same cache the other way, and a node whose pvestatd is stuck lists its guests «unknown»: the suspension
     * sends no shutdown and reports success. The unpaid VM keeps running while MyCS says suspended.
     */
    public function test_a_suspension_never_reports_success_while_the_vm_runs(): void
    {
        foreach (['stopped', 'unknown'] as $listed) {
            $this->writes = [];
            ProxmoxGuest::query()->delete();
            $service = $this->mapped();
            $this->vm['status'] = $listed;
            $this->current = 'running';

            try {
                $this->lifecycle($service)->suspend($service);
            } catch (Throwable) {
                continue; // refused: staff see it, fine
            }
            $this->assertContains('POST nodes/vega/qemu/101/status/shutdown timeout=60&forceStop=1', $this->writes,
                "listed «{$listed}», running: suspended in MyCS, running on the cluster");
        }
    }

    /**
     * A3. «Destroy» mode: a guest the cluster list does not show is marked destroyed and the termination reported done,
     * without asking. A token that sees no guest (its ACL removed, a token rotated: the case GuestSync refuses to read
     * as «all gone», review of #446) makes every termination a silent success while the VMs keep running, and the row,
     * marked destroyed, is never looked up again.
     */
    public function test_a_destroy_that_cannot_see_the_guest_is_not_reported_done(): void
    {
        $service = $this->mapped();
        $this->vm = null;          // the token lists nothing
        $this->guestReads = 403;   // and may not read the guest either

        $failed = false;
        try {
            ExtensionHelper::terminateServer($service);
        } catch (Throwable) {
            $failed = true;
        }

        $this->assertNotSame('destroyed', ProxmoxGuest::query()->sole()->status, 'never confirmed gone');
        $this->assertTrue($failed || ModuleAction::query()->open()->where('service_id', $service->id)->exists(),
            'a termination that did nothing is reported done: no failure, no task for staff');
    }

    /**
     * A4. A template is mapped as easily as a VM (the «Service» action has no check) and templates live in the pool
     * MyCS clones from. Its termination destroys the template every later VM is made from.
     */
    public function test_a_template_is_never_destroyed(): void
    {
        $service = $this->mapped(['template' => true, 'status' => 'stopped']);
        $this->vm = array_merge($this->vm, ['template' => 1, 'status' => 'stopped']);

        try {
            $this->lifecycle($service)->terminate($service);
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->writes, 'a template destroyed');
    }

    /**
     * A5. A VMID reused by another machine: the sync clears the mapping, MACs and note, but keeps the old customer's
     * «onboot before suspend». The next service mapped to it inherits it: its first suspension does not record its own
     * onboot (the column is not null), and its unsuspension sets back the old customer's value (onboot=0: the paid VM
     * no longer starts after a node's reboot; onboot=1 on one that never had it).
     */
    public function test_a_reused_vmid_does_not_inherit_the_previous_guests_onboot(): void
    {
        $service = $this->mapped(['onboot_before_suspend' => false, 'status' => 'stopped']);
        $this->config['net0'] = 'virtio=BC:24:11:99:99:99,bridge=vmbr0'; // another machine under VMID 101

        GuestSync::sync($service->provisioningServer());

        $row = ProxmoxGuest::query()->sole();
        $this->assertNull($row->service_id, 'the sync saw another machine');
        $this->assertNull($row->onboot_before_suspend, 'the old customer\'s onboot kept for the next one');
    }

    /**
     * A6. «Terminate: stop and leave to staff» is treated as a done termination (the service goes terminated), but
     * unlike a success it leaves the service's earlier failed suspend / unsuspend open in Pending Module Actions
     * (recordSuccess closes them: ModuleAction::SUPERSEDES['terminate']). Staff see a stale «unsuspend failed» for a
     * terminated service, and its Retry asks the cluster to start the VM they were told to destroy.
     */
    public function test_a_termination_left_to_staff_closes_the_older_failures_as_a_done_one_does(): void
    {
        $service = $this->mapped(terminate: 'stop');
        ModuleAction::recordFailure($service, 'unsuspend', 'Proxmox', new Exception('VM is locked (backup)'));

        ExtensionHelper::terminateServer($service);

        $this->assertFalse(ModuleAction::query()->open()->where('service_id', $service->id)->whereNull('subject')->where('action', 'unsuspend')->exists(),
            'the stale unsuspend failure is still open');
    }

    /**
     * A7. The «Mapped, outside the MyCS pool» filter hard-codes «mycs» while the column and the pool command read the
     * server's own pool: with any other pool every mapped guest is listed as outside it (and a guest really outside a
     * pool named otherwise is the only kind it would hide, were it named «mycs»).
     */
    public function test_the_outside_pool_filter_reads_the_servers_pool(): void
    {
        $service = $this->mapped(pool: 'customers');
        $inside = ProxmoxGuest::query()->sole();
        $outside = ProxmoxGuest::query()->create(['server_id' => $inside->server_id, 'vmid' => 102, 'name' => 'vps2', 'pool' => 'mycs',
            'service_id' => Service::factory()->create(['client_id' => $service->client_id, 'plan_id' => $service->plan_id,
                'product_id' => $service->product_id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1])->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(ListProxmoxGuests::class)
            ->assertCanSeeTableRecords([$inside, $outside]) // both listed without the filter
            ->filterTable('outside_pool', true)
            ->assertCanSeeTableRecords([$outside])
            ->assertCanNotSeeTableRecords([$inside]);
    }

    /**
     * R2-1. The «sees nothing» refusal covers an empty list only. A token whose PVEAuditor on «/» was removed still lists
     * the pool's guests (VM.Audit through /pool/mycs): a mapped VM that staff took out of the pool (docs: how MyCS is
     * kept away from a VM) is then simply absent, and «Terminate: Destroy» marks it destroyed and reports the
     * termination done, never asked. It keeps running, and is never looked up again.
     */
    public function test_a_destroy_of_a_guest_the_token_cannot_see_is_not_reported_done_though_it_sees_others(): void
    {
        $service = $this->mapped();
        $this->vm = ['vmid' => 102, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps2', 'status' => 'running', 'pool' => 'mycs'];
        $this->guestReads = 403; // VM 101 is outside the token's ACL now

        $failed = false;
        try {
            ExtensionHelper::terminateServer($service);
        } catch (Throwable) {
            $failed = true;
        }

        $this->assertNotSame('destroyed', ProxmoxGuest::query()->where('vmid', 101)->sole()->status, 'never confirmed gone');
        $this->assertTrue($failed || ModuleAction::query()->open()->where('service_id', $service->id)->exists(),
            'a termination that did nothing is reported done: no failure, no task for staff');
    }
}
