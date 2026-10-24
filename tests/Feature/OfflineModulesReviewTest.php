<?php

namespace Tests\Feature;

use App\Admin\Resources\ModuleActionResource\Pages\ListModuleActions;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\EmailLog;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Independent review of #443 (offline modules): failing tests for what was found. */
class OfflineModulesReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
    }

    private function service(string $status = 'active'): Service
    {
        $server = Server::query()->create(['name' => 'Rack', 'extension' => 'OfflineDedicated', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1,
            'domain' => 'mars.example.test', 'dedicated_ip' => '203.0.113.20']);
    }

    private function customerMails(Service $service, string $key): int
    {
        return EmailLog::query()->where('related_type', Service::class)->where('related_id', $service->id)->where('template_key', $key)->count();
    }

    private function staffTasksMailed(Service $service, string $action): int
    {
        return EmailLog::query()->where('subject', 'like', "By hand: {$action} service #{$service->id}%")->count();
    }

    /**
     * R1: «Retry» is offered on an offline task (given up, not a staff_task), and runs the job again: nothing can be done
     * on the machine, but the customer is mailed «suspended» a second time and staff are told «Done» while the task stays
     * open. OptionTasks' staff tasks hide Retry (and the MCP refuses to propose it); these should too.
     */
    public function test_retry_on_an_offline_task_mails_the_customer_nothing_more(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $service = $this->service();
        $service->forceFill(['status' => 'suspended'])->save();
        (new SuspendJob($service))->handle();
        $task = ModuleAction::query()->open()->where('service_id', $service->id)->sole();
        $this->assertSame(1, $this->customerMails($service, 'server_suspended'));

        $component = $this->livewireAs($staff)->test(ListModuleActions::class);
        try {
            $component->callTableAction('retry', $task);
        } catch (\Throwable) {
            // hidden: nothing to run
        }

        $this->assertSame(1, $this->customerMails($service, 'server_suspended'), 'a Retry of a task by hand mails the customer again');
        $this->assertSame(ModuleAction::STATUS_OPEN, $task->fresh()->status);
    }

    /**
     * R2: a task still open (done by hand but not marked, or not done) is reused by the next action of the same kind:
     * suspend, unsuspend, suspend again bumps the first suspend row, already given up, so staff get no mail for the
     * second suspension. Nothing supersedes an offline task, so open rows pile up and this is the common case.
     */
    public function test_a_new_suspension_while_an_old_task_is_open_tells_staff_again(): void
    {
        $service = $this->service();

        $service->forceFill(['status' => 'suspended'])->save();
        (new SuspendJob($service))->handle();
        $service->forceFill(['status' => 'active'])->save();
        (new UnsuspendJob($service))->handle();
        $service->forceFill(['status' => 'suspended'])->save();
        (new SuspendJob($service))->handle();

        $this->assertSame(1, $this->staffTasksMailed($service, 'unsuspend'));
        $this->assertSame(2, $this->staffTasksMailed($service, 'suspend'), 'the second suspension is never told to staff');
    }

    /**
     * R3: a suspension its job skipped (paid before the worker reached it: #418's mark) was never asked of staff, yet the
     * payment's unsuspension opens a task and mails staff to unsuspend a machine nobody suspended. For an offline module
     * the mark is proof: its suspension never fails silently (it would have opened a task).
     */
    public function test_an_unsuspension_of_a_suspension_never_asked_is_no_task(): void
    {
        $service = $this->service();
        Cache::forget(SuspendJob::neverSuspendedKey($service->id));

        (new SuspendJob($service))->handle(); // service active again: skipped, marked
        $this->assertTrue(Cache::get(SuspendJob::neverSuspendedKey($service->id)));
        (new UnsuspendJob($service))->handle();

        $this->assertSame(0, ModuleAction::query()->open()->where('service_id', $service->id)->count(), 'a task to unsuspend a machine never suspended');
        $this->assertSame(0, $this->staffTasksMailed($service, 'unsuspend'));
    }
}
