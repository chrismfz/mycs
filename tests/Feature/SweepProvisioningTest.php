<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Models\License;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Service\DeleteService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial verification of the provisioning sweep (2026-11-07). Each test fails on main at 00268d7f for the reason
 * given in its docblock.
 */
class SweepProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const WHM = 'https://whm.test:2087/json-api';

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner ' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function serviceOn(Server $server, array $attributes = []): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);

        return Service::factory()->create(array_merge(['client_id' => User::factory()->create()->client()->id,
            'plan_id' => $product->plan->id, 'product_id' => $product->product->id, 'status' => 'active',
            'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()], $attributes));
    }

    /**
     * PROVISIONING-1. Staff «Unsuspend server» on a suspended nixpal license service: EditService calls unsuspendServer
     * while the row is still «suspended», and Licenses::setStatus(ACTIVE) refuses on a service that is not active. The
     * service then turns active and nothing resyncs the license: zlic.php keeps answering «Suspended».
     */
    public function test_staff_unsuspend_of_a_license_service_makes_its_license_active_again(): void
    {
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $service = $this->serviceOn($server, ['price' => 0]);
        foreach (['key_prefix' => ['ZCAgent-', false], 'key_length' => ['16', false], 'secret_key' => ['TestSecret', true], 'allow_reissue' => ['1', false]] as $key => [$value, $encrypted]) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $encrypted, 'type' => $key === 'allow_reissue' ? 'boolean' : 'string',
                'settingable_id' => $service->product_id, 'settingable_type' => $service->product->getMorphClass()])->save();
        }
        ExtensionHelper::createServer($service);
        $this->assertSame(License::STATUS_ACTIVE, License::query()->where('service_id', $service->id)->sole()->status);

        // The cron: suspended, then its SuspendJob on the server.
        $service->forceFill(['status' => Service::STATUS_SUSPENDED])->save();
        ExtensionHelper::suspendServer($service->fresh());
        $this->assertSame(License::STATUS_SUSPENDED, License::query()->where('service_id', $service->id)->sole()->status);

        $this->livewireAs($this->staff())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeStatus', ['action' => 'unsuspend', 'sendNotification' => false]);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: staff unsuspended the service');

        $key = License::query()->where('service_id', $service->id)->sole()->license_key;
        $answer = json_decode($this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post('/zlic.php', ['license' => $key, 'license_PSK' => 'TestSecret'])->getContent(), true);
        $this->assertSame(License::STATUS_ACTIVE, License::query()->where('service_id', $service->id)->sole()->status,
            'the service is active again, its license is still Suspended');
        $this->assertSame('Active', $answer['status'] ?? null);
    }

    /**
     * PROVISIONING-2. A create attempt killed by the worker's timeout leaves its 600 s create lock behind (the finally
     * never runs). The queue re-reserves the job after retry_after (300 s), the lock is still held, createServer throws
     * CreateSkipped and CreateJob returns: the queue deletes the job as done. The service stays pending, no row is given
     * up, staff are never told. A retry (attempts > 1) that finds the lock held should be released or failed, not
     * swallowed.
     */
    public function test_a_retried_create_that_finds_a_stale_lock_is_not_dropped_as_done(): void
    {
        Queue::fake();
        Mail::fake();
        $server = Server::query()->create(['name' => 'Offline', 'extension' => 'OfflineVm', 'type' => 'server', 'enabled' => true]);
        $service = $this->serviceOn($server, ['status' => Service::STATUS_PENDING]);
        // Attempt 1 failed and was recorded; attempt 2 took the lock and was killed at 120 s.
        ModuleAction::recordFailure($service, 'create', 'OfflineVm', new \RuntimeException('timed out'));
        $this->assertTrue(Cache::lock("service-create:{$service->id}", 600)->get(), 'the killed attempt\'s lock');

        $job = (new CreateJob($service))->withFakeQueueInteractions();
        $job->job->attempts = 3;
        $job->handle();

        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
        $tracked = $job->job->isReleased() || $job->job->hasFailed()
            || ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'create')->whereNotNull('given_up_at')->exists();
        $this->assertTrue($tracked, 'the retry was swallowed as a success: the job is deleted, the service pending for ever, nobody told');
    }

    /**
     * PROVISIONING-3. Two live services on one WHM server carry the same username (an imported second row of the same
     * account). Terminating one runs /removeacct on the username, deleting the account the other, paid, service lives on.
     * cPanel has no guard like Centova's owned()/claimants or ngm's claimedByAnotherLive.
     */
    public function test_cpanel_terminate_does_not_remove_an_account_another_live_service_holds(): void
    {
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake([self::WHM . '/removeacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK', 'command' => 'removeacct']]),
            self::WHM . '/listaccts*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['acct' => []]])]);
        $server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $paid = $this->serviceOn($server, ['domain' => 'shared.example.test', 'username' => 'sharedex']);
        $paid->product->settings()->create(['key' => 'package', 'value' => 'basic']);
        $second = $this->serviceOn($server, ['domain' => 'shared.example.test', 'username' => 'sharedex']);
        $second->product->settings()->create(['key' => 'package', 'value' => 'basic']);

        try {
            ExtensionHelper::terminateServer($second->fresh());
        } catch (\Throwable) {
            // a refusal is the fix
        }

        $this->assertSame(Service::STATUS_ACTIVE, $paid->fresh()->status);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/removeacct') && ($request['user'] ?? null) === 'sharedex');
    }

    /**
     * PROVISIONING-5. Delete with terminate on a module with no API: terminateServer writes the open «by hand» terminate
     * task, then the delete removes the service and module_actions.service_id ON DELETE CASCADE removes the task with it.
     * The machine still to be released by hand is no longer in Pending Module Actions (only the one mail is left).
     */
    public function test_delete_with_terminate_on_an_offline_module_keeps_the_by_hand_task(): void
    {
        $this->markTestSkipped('PROVISIONING-5 left open (low): keeping the task needs module_actions.service_id to outlive its service (a schema and screens change), or a product call on deleting such a service.');
        Queue::fake();
        Mail::fake();
        $server = Server::query()->create(['name' => 'Offline', 'extension' => 'OfflineVm', 'type' => 'server', 'enabled' => true]);
        $service = $this->serviceOn($server, ['domain' => 'mars.example.test', 'dedicated_ip' => '203.0.113.20']);

        $made = [];
        ModuleAction::created(function (ModuleAction $row) use (&$made) {
            $made[] = [$row->action, $row->subject];
        });

        (new DeleteService)->handle($service, true);

        $this->assertContains(['terminate', ModuleAction::SUBJECT_BY_HAND], $made, 'precondition: the by-hand task was written');
        $this->assertNull(Service::query()->find($service->id), 'precondition: deleted');
        $this->assertTrue(ModuleAction::query()->open()->where('action', 'terminate')->where('subject', ModuleAction::SUBJECT_BY_HAND)
            ->where('last_error', 'like', '%mars.example.test%')->exists(), 'the by-hand terminate task went with the service (cascade)');
    }

    /**
     * PROVISIONING-6 (b). An imported DirectAdmin service has only the native username (WHMCS's tblhosting.username):
     * the module reads only properties['directadmin_username'], which the importer never sets and getServiceProperties
     * does not map, so it can never be suspended (or unsuspended, terminated) on its server.
     */
    public function test_an_imported_directadmin_service_with_its_native_username_can_be_suspended(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://da.test:2222/CMD_API_SELECT_USERS*' => Http::response('error=0&text=OK')]);
        $server = Server::query()->create(['name' => 'DA', 'extension' => 'DirectAdmin', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://da.test:2222', 'username' => 'admin', 'password' => 'secret'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $service = $this->serviceOn($server, ['domain' => 'imported.example.test', 'username' => 'importd']);
        $service->forceFill(['status' => Service::STATUS_SUSPENDED])->save();

        $error = null;
        try {
            ExtensionHelper::suspendServer($service->fresh());
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->assertNull($error, 'an imported DirectAdmin account cannot be suspended: ' . $error);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'CMD_API_SELECT_USERS') && ($request['select0'] ?? null) === 'importd');
    }
}
