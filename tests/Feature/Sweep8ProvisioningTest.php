<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Sweep 8, provisioning: adversarial tests (they fail until the findings are fixed). */
class Sweep8ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function service(Server $server, array $more = []): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        Setting::query()->updateOrCreate(['settingable_type' => $product->product->getMorphClass(), 'settingable_id' => $product->product->id, 'key' => 'package'],
            ['value' => 'Small', 'type' => 'string', 'encrypted' => false]);

        return Service::factory()->create($more + ['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
    }

    /**
     * S8-PROVISIONING-1: Change Package on an account that WHM has for another site (a username kept from WHMCS, the case
     * ownAccount() refuses for suspend/unsuspend/terminate/login/password) is sent all the same.
     */
    public function test_s8_provisioning_1_change_package_is_refused_on_a_strangers_account(): void
    {
        $server = Server::query()->create(['name' => 'Rigel', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $calls = [];
        Http::fake(function (Request $request) use (&$calls) {
            $call = basename((string) parse_url($request->url(), PHP_URL_PATH));
            $calls[] = $call;
            $ok = fn (array $data = []) => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => $data]);

            return match ($call) {
                'accountsummary' => $ok(['acct' => [['user' => 'shop', 'domain' => 'stranger.example.test', 'plan' => 'Big', 'owner' => 'root']]]),
                'listpkgs' => $ok(['pkg' => [['name' => 'Small'], ['name' => 'Big']]]),
                'listresellers' => $ok(['reseller' => []]),
                default => $ok(),
            };
        });
        // As imported: the username on the service itself, no cpanel_username property.
        $service = $this->service($server, ['domain' => 'mine.example.test', 'username' => 'shop']);

        $refused = null;
        try {
            ExtensionHelper::upgradeServer($service);
        } catch (\Throwable $e) {
            $refused = $e->getMessage();
        }

        $this->assertNotContains('changepackage', $calls, 'the stranger\'s account was given this service\'s package');
        $this->assertNotContains('editquota', $calls, 'the stranger\'s quota was changed');
        $this->assertNotNull($refused, 'the upgrade counted as done: staff are not told');
    }

    /** The neighbour of S8-PROVISIONING-1: Change Package on the service's own account (WHM names its domain) is sent. */
    public function test_change_package_on_the_services_own_account_is_still_sent(): void
    {
        $server = Server::query()->create(['name' => 'Rigel', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $changed = [];
        Http::fake(function (Request $request) use (&$changed) {
            $call = basename((string) parse_url($request->url(), PHP_URL_PATH));
            $ok = fn (array $data = []) => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => $data]);
            if ($call === 'changepackage') {
                $changed[] = $request->data();
            }

            return match ($call) {
                // The same domain as WHM writes it (www., upper case): the service's own account.
                'accountsummary' => $ok(['acct' => [['user' => 'shop', 'domain' => 'WWW.Mine.example.test', 'plan' => 'Big', 'owner' => 'root']]]),
                'listpkgs' => $ok(['pkg' => [['name' => 'Small'], ['name' => 'Big']]]),
                'listresellers' => $ok(['reseller' => []]),
                default => $ok(),
            };
        });
        $service = $this->service($server, ['domain' => 'mine.example.test', 'username' => 'shop']);

        ExtensionHelper::upgradeServer($service);

        $this->assertCount(1, $changed);
        $this->assertSame('shop', $changed[0]['user'] ?? null);
        $this->assertSame('Small', $changed[0]['pkg'] ?? null);
    }

    /**
     * S8-PROVISIONING-2: «destroy it by hand» stays in Pending Module Actions after staff bring the service back to active.
     */
    public function test_s8_provisioning_2_a_by_hand_terminate_task_closes_when_the_service_is_active_again(): void
    {
        $server = Server::query()->create(['name' => 'Offline', 'extension' => 'OfflineVm', 'type' => 'server', 'enabled' => true]);
        $service = $this->service($server, ['domain' => 'mars.example.test', 'dedicated_ip' => '203.0.113.20']);

        ExtensionHelper::terminateServer($service);
        $service->refresh()->markTerminated();
        $open = fn () => ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'terminate')->count();
        $this->assertSame('terminated', $service->fresh()->status);
        $this->assertSame(1, $open(), 'the task to remove the machine by hand');

        // The customer pays late: staff bring the service back.
        $service->fresh()->applyStaffChanges(['status' => 'active']);

        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(0, $open(), 'the task to destroy the machine of an active service is still open');
    }

    /** The fix of S8-PROVISIONING-2 says why it closed the task, and names no staff member (nobody did it). */
    public function test_the_closed_terminate_task_says_why(): void
    {
        $server = Server::query()->create(['name' => 'Offline', 'extension' => 'OfflineVm', 'type' => 'server', 'enabled' => true]);
        $service = $this->service($server, ['domain' => 'mars.example.test', 'dedicated_ip' => '203.0.113.20']);
        ExtensionHelper::terminateServer($service);
        $service->refresh()->markTerminated();

        $service->fresh()->applyStaffChanges(['status' => 'active']);

        $task = ModuleAction::query()->where('service_id', $service->id)->where('action', 'terminate')->where('subject', ModuleAction::SUBJECT_BY_HAND)->sole();
        $this->assertSame(ModuleAction::STATUS_RESOLVED, $task->status);
        $this->assertSame('closed: the service is active again, do not remove it', $task->resolution);
        $this->assertNotNull($task->resolved_at);
        $this->assertNull($task->resolved_by_staff_id);
    }

    /** The neighbour of S8-PROVISIONING-2: the task of a service that stays ended (terminated, then cancelled) stays open. */
    public function test_a_by_hand_terminate_task_of_a_service_that_stays_ended_stays_open(): void
    {
        $server = Server::query()->create(['name' => 'Offline', 'extension' => 'OfflineVm', 'type' => 'server', 'enabled' => true]);
        $service = $this->service($server, ['domain' => 'mars.example.test', 'dedicated_ip' => '203.0.113.20']);
        $other = $this->service($server, ['domain' => 'venus.example.test', 'dedicated_ip' => '203.0.113.21']);
        foreach ([$service, $other] as $each) {
            ExtensionHelper::terminateServer($each);
            $each->refresh()->markTerminated();
        }
        $open = fn (Service $of) => ModuleAction::query()->open()->where('service_id', $of->id)->where('action', 'terminate')->count();

        $service->fresh()->applyStaffChanges(['notes' => 'the machine is still in the rack']);
        $this->assertSame(1, $open($service), 'a change that is not its status');
        $service->fresh()->applyStaffChanges(['status' => 'cancelled']);
        $this->assertSame('cancelled', $service->fresh()->status);
        $this->assertSame(1, $open($service), 'still ended: the machine is still to be removed');

        // Another service brought back closes its own task only.
        $other->fresh()->applyStaffChanges(['status' => 'active']);
        $this->assertSame(0, $open($other));
        $this->assertSame(1, $open($service));
    }
}
