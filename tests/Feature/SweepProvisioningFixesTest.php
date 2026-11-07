<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The edges of the provisioning sweep's fixes (2026-11-07): what they still let through, and what else they keep. */
class SweepProvisioningFixesTest extends TestCase
{
    use RefreshDatabase;

    private const WHM = 'https://whm.test:2087/json-api';

    private function serviceOn(Server $server, array $attributes = []): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);

        return Service::factory()->create(array_merge(['client_id' => User::factory()->create()->client()->id,
            'plan_id' => $product->plan->id, 'product_id' => $product->product->id, 'status' => 'active',
            'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()], $attributes));
    }

    private function whmServer(): Server
    {
        $server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }

        return $server;
    }

    private function daServer(): Server
    {
        $server = Server::query()->create(['name' => 'DA', 'extension' => 'DirectAdmin', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://da.test:2222', 'username' => 'admin', 'password' => 'secret'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }

        return $server;
    }

    /** PROVISIONING-2: on its first attempt too, a held create lock sends the job back to the queue, never ends it. */
    public function test_a_first_create_that_finds_the_lock_held_is_released(): void
    {
        Queue::fake();
        $server = Server::query()->create(['name' => 'Offline', 'extension' => 'OfflineVm', 'type' => 'server', 'enabled' => true]);
        $service = $this->serviceOn($server, ['status' => Service::STATUS_PENDING]);
        Cache::lock("service-create:{$service->id}", CreateJob::CREATE_LOCK_SECONDS)->get();

        $job = (new CreateJob($service))->withFakeQueueInteractions();
        $job->handle();

        $job->assertReleased(delay: CreateJob::CREATE_LOCK_SECONDS); // tried again once the lock has surely gone
    }

    /** PROVISIONING-2: a create that finds its service started meanwhile still ends as done (nothing to try again). */
    public function test_a_create_of_a_service_no_longer_pending_ends(): void
    {
        Queue::fake();
        $server = Server::query()->create(['name' => 'Offline', 'extension' => 'OfflineVm', 'type' => 'server', 'enabled' => true]);
        $service = $this->serviceOn($server, ['status' => Service::STATUS_ACTIVE]);

        $job = (new CreateJob($service))->withFakeQueueInteractions();
        $job->handle();

        $this->assertFalse($job->job->isReleased());
        $this->assertFalse($job->job->hasFailed());
    }

    /** PROVISIONING-3: a suspension is refused the same way; the shared account is left as it is. */
    public function test_cpanel_suspend_does_not_suspend_an_account_another_live_service_holds(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::WHM . '/*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']])]);
        $server = $this->whmServer();
        $paid = $this->serviceOn($server, ['username' => 'sharedex']);
        $second = $this->serviceOn($server, ['username' => ' SharedEx ', 'status' => Service::STATUS_SUSPENDED]);
        $second->product->settings()->create(['key' => 'package', 'value' => 'basic']);

        try {
            ExtensionHelper::suspendServer($second->fresh());
            $this->fail('suspended an account another live service holds');
        } catch (\Exception $e) {
            $this->assertStringContainsString("#{$paid->id}", $e->getMessage());
        }
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/suspendacct'));
    }

    /** PROVISIONING-3: an ended service with the same name does not hold the account: the terminate goes ahead. */
    public function test_cpanel_terminate_goes_ahead_when_the_other_service_has_ended(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::WHM . '/removeacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']]),
            self::WHM . '/listaccts*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['acct' => []]])]);
        $server = $this->whmServer();
        $this->serviceOn($server, ['username' => 'sharedex', 'status' => Service::STATUS_TERMINATED]);
        $live = $this->serviceOn($server, ['username' => 'sharedex']);
        $live->product->settings()->create(['key' => 'package', 'value' => 'basic']);

        ExtensionHelper::terminateServer($live->fresh());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/removeacct') && $request['user'] === 'sharedex');
    }

    /** PROVISIONING-3: the same name on another server is another account. */
    public function test_cpanel_terminate_goes_ahead_when_the_same_name_is_on_another_server(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::WHM . '/removeacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']]),
            self::WHM . '/listaccts*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['acct' => []]])]);
        $this->serviceOn($this->whmServer(), ['username' => 'sharedex']);
        $live = $this->serviceOn($this->whmServer(), ['username' => 'sharedex']);
        $live->product->settings()->create(['key' => 'package', 'value' => 'basic']);

        ExtensionHelper::terminateServer($live->fresh());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/removeacct'));
    }

    /** PROVISIONING-6: an imported DirectAdmin account is removed by its native name, which goes with it. */
    public function test_an_imported_directadmin_service_is_terminated_by_its_native_username(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://da.test:2222/CMD_API_SELECT_USERS*' => Http::response('error=0&text=OK')]);
        $service = $this->serviceOn($this->daServer(), ['username' => 'importd']);

        ExtensionHelper::terminateServer($service->fresh());

        Http::assertSent(fn (Request $request) => ($request['delete'] ?? null) === 'yes' && $request['select0'] === 'importd');
        $this->assertNull($service->fresh()->username, 'a later action must not name a removed account');
    }

    /** PROVISIONING-6 with -3: two live DirectAdmin services with one native name: not removed, but still unsuspended. */
    public function test_a_shared_directadmin_account_is_not_terminated_but_is_unsuspended(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://da.test:2222/CMD_API_SELECT_USERS*' => Http::response('error=0&text=OK')]);
        $server = $this->daServer();
        $this->serviceOn($server, ['username' => 'importd']);
        $second = $this->serviceOn($server, ['username' => 'importd']);

        try {
            ExtensionHelper::terminateServer($second->fresh());
            $this->fail('removed an account another live service holds');
        } catch (\Exception $e) {
            $this->assertStringContainsString('too', $e->getMessage());
        }
        Http::assertNotSent(fn (Request $request) => ($request['delete'] ?? null) === 'yes');

        ExtensionHelper::unsuspendServer($second->fresh());
        Http::assertSent(fn (Request $request) => ($request['suspend'] ?? null) === 'unsuspend' && $request['select0'] === 'importd');
    }
}
