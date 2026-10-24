<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\SuspendJob;
use App\Models\EmailLog;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Offline VM and Offline Dedicated (owner 2026-10-24): machines with no API. Their details are kept on the service, and
 * every server action is a task for staff in Pending Module Actions.
 */
class OfflineModulesTest extends TestCase
{
    use RefreshDatabase;

    private function service(string $module, string $status = 'active'): Service
    {
        $server = Server::query()->create(['name' => $module, 'extension' => $module, 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1,
            'domain' => 'mars.example.test', 'dedicated_ip' => '203.0.113.20']);
    }

    public function test_both_modules_are_offered_for_a_server(): void
    {
        $names = array_column(ExtensionHelper::getExtensions('server'), 'name');

        $this->assertContains('OfflineVm', $names);
        $this->assertContains('OfflineDedicated', $names);
    }

    public function test_a_suspension_is_a_task_for_staff_and_the_service_follows(): void
    {
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $service = $this->service('OfflineDedicated');
        $service->forceFill(['status' => 'suspended'])->save();

        (new SuspendJob($service))->handle();

        $task = ModuleAction::query()->open()->where('service_id', $service->id)->sole();
        $this->assertSame(['suspend', 'OfflineDedicated'], [$task->action, $task->module]);
        $this->assertNotNull($task->given_up_at, 'no retry can do it: staff\'s at once');
        $this->assertStringContainsString('By hand: suspend', (string) $task->last_error);
        $this->assertSame(ModuleAction::SUBJECT_BY_HAND, $task->subject);
        $this->assertStringContainsString('mars.example.test', (string) $task->last_error);
        $this->assertTrue(EmailLog::query()->where('subject', 'like', 'By hand: suspend service #' . $service->id . '%')->exists(), 'staff told once');
        $this->assertSame('suspended', $service->fresh()->status);
    }

    public function test_a_creation_starts_the_service_as_a_module_would(): void
    {
        $service = $this->service('OfflineVm', 'pending');

        ExtensionHelper::createServer($service);

        $this->assertSame('create', ModuleAction::query()->open()->where('service_id', $service->id)->value('action'));
    }

    public function test_staff_keep_the_machine_details_on_the_service(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $service = $this->service('OfflineDedicated');
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertSee('Machine')
            ->callAction('editMachine', ['machine_rack' => 'A2', 'machine_rack_unit' => '12', 'machine_rack_height' => 2,
                'machine_ipmi_ip' => '10.0.0.204', 'machine_ipmi_mac' => '50-9a-4c-a8-fd-00', 'machine_mac' => 'aa:bb:cc:dd:ee:01, AA:BB:CC:DD:EE:02'])
            ->assertHasNoActionErrors()->assertNotified();

        $props = $service->fresh()->properties->pluck('value', 'key');
        $this->assertSame(['A2', '12', '2', '10.0.0.204', '50:9A:4C:A8:FD:00', "AA:BB:CC:DD:EE:01\nAA:BB:CC:DD:EE:02"],
            [$props['machine_rack'], $props['machine_rack_unit'], $props['machine_rack_height'], $props['machine_ipmi_ip'], $props['machine_ipmi_mac'], $props['machine_mac']]);

        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('editMachine', ['machine_ipmi_mac' => 'not-a-mac'])->assertHasActionErrors(['machine_ipmi_mac']);
    }

    public function test_a_module_with_an_api_has_no_machine_section(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $service = $this->service('CPanel');
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertDontSee('Edit machine details');
    }
}
