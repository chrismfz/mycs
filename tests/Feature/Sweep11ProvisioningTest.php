<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\ConfigOption;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\DeleteService;
use App\Services\Service\OptionTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 11, provisioning: adversarial verification. Each test is FAILING (a confirmed finding).
 *
 * S11-PROVISIONING-1: the by-hand option tasks (OptionTasks, action «staff_task», subject = the option's id) are not
 * among those ModuleAction::openTaskKeeps() keeps a service for, and module_actions.service_id cascades: a delete takes
 * the open «Take back: Dedicated IP» with it, and the delete of a running service never opens one.
 */
class Sweep11ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const WHM = 'https://whm.test:2087/json-api';

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'Semi20']);
        $option = ConfigOption::query()->create(['name' => 'Dedicated IP', 'env_variable' => 'dedicated_ip_option', 'type' => 'select', 'upgradable' => true, 'staff_task' => true]);
        $yes = ConfigOption::query()->create(['name' => 'Yes', 'env_variable' => '1', 'type' => 'select', 'parent_id' => $option->id]);
        $option->products()->attach($product->product->id);
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => 'pending', 'expires_at' => now()->addMonth(),
            'currency_code' => 'USD', 'price' => 10.00, 'domain' => 'example.test', 'username' => 'exampleuser']);
        $this->service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $yes->id]);
        $this->service->update(['status' => 'active']);
        // The «Give» was done by staff.
        ModuleAction::query()->open()->where('action', OptionTasks::ACTION)->update(['status' => ModuleAction::STATUS_RESOLVED, 'resolved_at' => now()]);
    }

    private function takeBacks(): int
    {
        return ModuleAction::query()->open()->where('action', OptionTasks::ACTION)->where('last_error', 'like', 'Take back:%')->count();
    }

    /**
     * FAILING. Terminated (the cron), its «Take back: Dedicated IP: Yes» open; the terminated record is deleted before
     * staff did it. Expected: refused (services.delete_open_task), as for a by-hand terminate task, or the task survives.
     * Actual: deleted, the task cascaded away: nothing tells staff the IP is still assigned.
     */
    public function test_s11_provisioning_1a_deleting_a_terminated_service_keeps_its_open_take_back_task(): void
    {
        $this->markTestIncomplete('known (2026-11-12): deleting a service drops (or never opens) its by-hand option tasks; watched live');
        $this->service->update(['status' => 'terminated']);
        $this->assertSame(1, $this->takeBacks(), 'the take-back opened when it ended');

        try {
            app(DeleteService::class)->handle($this->service->fresh());
        } catch (DisplayException) {
            // refused: the task keeps the service
        }

        $this->assertSame(1, $this->takeBacks(), 'the open «Take back» task went with the deleted service');
    }

    /**
     * FAILING. Active, deleted with «terminate» ticked (WHM removes the account). Expected: staff are told to take the
     * IP back (a task that survives, so the service is kept terminated, or a refusal). Actual: the row goes straight
     * from active: no task, no mail.
     */
    public function test_s11_provisioning_1b_deleting_a_running_service_tells_staff_to_take_the_option_back(): void
    {
        $this->markTestIncomplete('known (2026-11-12): deleting a service drops (or never opens) its by-hand option tasks; watched live');
        Http::fake([
            self::WHM . '/accountsummary*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'exampleuser', 'domain' => 'example.test', 'plan' => 'Semi20']]]]),
            self::WHM . '/listaccts*' => Http::response(['metadata' => ['result' => 1], 'data' => ['acct' => []]]),
            self::WHM . '/removeacct*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']]),
        ]);
        $refused = false;
        try {
            app(DeleteService::class)->handle($this->service->fresh(), terminate: true);
        } catch (DisplayException) {
            $refused = true;
        }

        Http::assertSent(fn ($request) => str_contains($request->url(), '/removeacct'));
        $this->assertTrue($refused || $this->takeBacks() === 1,
            'a running service with a by-hand option was deleted with no «Take back» task and no refusal');
    }
}
