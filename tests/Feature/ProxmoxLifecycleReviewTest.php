<?php

namespace Tests\Feature;

use App\Admin\Resources\ProxmoxGuestResource\Pages\ListProxmoxGuests;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\ModuleAction;
use App\Models\ProxmoxGuest;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Proxmox\GuestLifecycle;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use RuntimeException;
use Tests\TestCase;

/**
 * Independent review of #447 (Proxmox lifecycle): failing tests for its findings.
 */
class ProxmoxLifecycleReviewTest extends TestCase
{
    use RefreshDatabase;

    private const VEGA = 'https://vega.example.test:8006';

    /** @var array<string, mixed> */
    private array $vm = ['vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'vps1', 'status' => 'running', 'pool' => 'mycs'];

    /** @var array<string, mixed> */
    private array $config = ['net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0', 'onboot' => 1];

    /** @var array<string, int> UPID => polls left before it reports stopped (PHP_INT_MAX: never ends) */
    private array $tasks = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(function (Request $request) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/'));
            if ($request->method() === 'PUT') {
                parse_str($request->body(), $form);
                $this->config = array_merge($this->config, $form);

                return Http::response(['data' => null]);
            }
            if ($request->method() !== 'GET') {
                $kind = $request->method() === 'DELETE' ? 'destroy' : basename($path);

                return Http::response(['data' => "UPID:vega:0000ABCD:0001:6700:{$kind}:101:mycs@pve!api:"]);
            }
            if ($path === 'cluster/resources') {
                return Http::response(['data' => array_filter([$this->vm])]);
            }
            if (preg_match('#^nodes/vega/(qemu|lxc)/101/config$#', $path) === 1) {
                return Http::response(['data' => $this->config]);
            }
            if (str_starts_with($path, 'nodes/vega/tasks/')) {
                $upid = rawurldecode(explode('/', $path)[3]);
                $left = $this->tasks[$upid] ?? 0;
                if ($left > 0) {
                    $this->tasks[$upid] = $left === PHP_INT_MAX ? $left : $left - 1;

                    return Http::response(['data' => ['status' => 'running']]);
                }

                return Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]);
            }

            return Http::response(['data' => null], 500);
        });
    }

    private function mapped(array $guest = [], string $terminate = 'stop', string $pool = 'mycs'): Service
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

    /**
     * Finding 1. «Terminate: stop and leave to staff» goes through staffTask, which closes only by-hand rows and failures
     * of the same action. A module success of terminate (recordSuccess) closes the open failures of create, suspend,
     * unsuspend and upgrade (SUPERSEDES); the LeftToStaff path leaves them open in Pending Module Actions, retryable,
     * on a terminated service.
     */
    public function test_left_to_staff_terminate_closes_the_open_failures_it_makes_moot_as_a_done_terminate_would(): void
    {
        $service = $this->mapped();
        // The suspension failed earlier (e.g. the VM was outside the pool then) and was given up.
        ModuleAction::recordFailure($service, 'suspend', 'Proxmox', new RuntimeException('VM 101 is not in the pool «mycs»'));
        ModuleAction::giveUp($service, 'suspend');

        ExtensionHelper::terminateServer($service);

        $this->assertFalse(ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'suspend')->exists(),
            'a stale «suspend failed» row stays open for a terminated service');
    }

    /**
     * Finding 2. ModuleAction::openFor() does not look at the subject, and Proxmox (unlike the offline modules) records
     * real failures too. A terminate that fails after the by-hand task exists overwrites that task's text (the destroy
     * command staff need) with its error, and the job's giveUp() finds the by-hand row (already given up) and tells
     * nobody of the failure.
     */
    public function test_a_failed_terminate_after_the_by_hand_task_neither_overwrites_it_nor_goes_unreported(): void
    {
        $service = $this->mapped();
        ExtensionHelper::terminateServer($service); // the by-hand task, with «qm destroy 101 --purge»

        $this->vm['pool'] = 'other'; // a second terminate (staff, a second job) is refused now
        try {
            ExtensionHelper::terminateServer($service);
            $this->fail('refused outside the pool');
        } catch (RuntimeException) {
        }

        $task = ModuleAction::query()->where('service_id', $service->id)->where('subject', ModuleAction::SUBJECT_BY_HAND)->sole();
        $this->assertStringContainsString('qm destroy 101 --purge', (string) $task->last_error, 'the by-hand task\'s instructions were overwritten');
        $this->assertTrue(ModuleAction::giveUp($service, 'terminate'), 'the failure is never mailed to staff');
    }

    /** Finding 3. A container's by-hand destroy command is «pct destroy», not «qm destroy» (qm fails on a CT). */
    public function test_the_by_hand_destroy_command_of_a_container_is_pct(): void
    {
        $this->vm['type'] = 'lxc';
        $this->config = ['net0' => 'name=eth0,bridge=vmbr0,hwaddr=BC:24:11:00:00:01', 'onboot' => 1];
        $service = $this->mapped(['type' => 'lxc']);

        ExtensionHelper::terminateServer($service);

        $task = ModuleAction::query()->open()->where('service_id', $service->id)->where('subject', ModuleAction::SUBJECT_BY_HAND)->sole();
        $this->assertStringContainsString('pct destroy 101', (string) $task->last_error);
    }

    /**
     * Finding 4. The waits inside one job are longer than the job may run: a destroy waits up to 60 s for the stop and
     * 100 s for the destroy (plus up to 30 s for the lock), and TerminateJob::$timeout is 120 s. The worker kills the job
     * before it fails cleanly («still being destroyed»), leaving the 300 s cache lock held so the next retry also fails.
     */
    public function test_a_destroys_waits_fit_in_the_terminate_jobs_timeout(): void
    {
        $service = $this->mapped(terminate: 'destroy');
        $this->tasks['UPID:vega:0000ABCD:0001:6700:stop:101:mycs@pve!api:'] = 30; // a stop that ends at the last poll
        $this->tasks['UPID:vega:0000ABCD:0001:6700:destroy:101:mycs@pve!api:'] = PHP_INT_MAX; // a big disk still going

        $slept = 0;
        $server = $service->provisioningServer();
        $extension = ExtensionHelper::getExtension('server', 'Proxmox', $server->settings);
        $this->assertInstanceOf(Proxmox::class, $extension);
        $lifecycle = new GuestLifecycle($extension->client(), $server, 'mycs', function (int $seconds) use (&$slept) {
            $slept += $seconds;
        });
        try {
            $lifecycle->terminate($service);
            $this->fail('still being destroyed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('being destroyed', $e->getMessage());
        }

        $this->assertLessThan((new TerminateJob($service))->timeout, $slept, "the job waits {$slept} s: killed by the worker before it fails cleanly");
    }

    /**
     * Finding 5. The «Mapped, outside the MyCS pool» filter hard-codes «mycs», while the column, the «Pool command» and
     * the lifecycle use the server's «MyCS pool» setting.
     */
    public function test_the_outside_pool_filter_uses_the_servers_pool(): void
    {
        $service = $this->mapped(pool: 'vps');
        $inPool = ProxmoxGuest::query()->sole();
        $outside = ProxmoxGuest::query()->create(['server_id' => $inPool->server_id, 'vmid' => 102, 'name' => 'vps2', 'pool' => 'mycs',
            'service_id' => $service->id, 'macs' => ['BC:24:11:00:00:02']]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(ListProxmoxGuests::class)
            ->filterTable('outside_pool', true)
            ->assertCanSeeTableRecords([$outside])
            ->assertCanNotSeeTableRecords([$inPool]);
    }
}
