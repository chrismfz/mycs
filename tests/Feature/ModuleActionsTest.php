<?php

namespace Tests\Feature;

use App\Admin\Resources\ModuleActionResource;
use App\Admin\Resources\ModuleActionResource\Pages\ListModuleActions;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\SuspendJob;
use App\Mail\Mail as MyCSMail;
use App\Mail\SystemMail;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Service\RenewServiceService;
use Exception;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use Tests\TestCase;

/**
 * Σ1-a: every server action is recorded (module_actions); a failure stays open, retried 3 times by its job, then
 * staff's (Pending Module Actions, one email); a service turns active, and gets its welcome mail, only once its account
 * exists.
 */
class ModuleActionsTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Fixtures/FlakyServer.php';
        FlakyServer::$failures = 0;
        FlakyServer::$calls = [];
        Queue::fake();
        Mail::fake();
        config(['settings.mail_disable' => false, 'settings.system_email_address' => 'staff@example.com']);
        $server = Server::query()->create(['name' => 'Flaky', 'extension' => 'FlakyServer', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $user = User::factory()->create();
        $this->service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'pending', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => null]);
    }

    private function pay(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $item = $invoice->items()->create(['reference_id' => $this->service->id, 'reference_type' => Service::class, 'description' => 'Order',
            'price' => 10, 'quantity' => 1]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);
        (new RenewServiceService)->handle($this->service->fresh(), $item);
    }

    private function welcomeMails(): int
    {
        return Mail::queued(MyCSMail::class, fn (MyCSMail $mail) => $mail->templateKey === 'new_server_created')->count();
    }

    public function test_a_paid_service_turns_active_only_once_its_account_is_created(): void
    {
        $this->pay();

        $service = $this->service->fresh();
        $this->assertSame('pending', $service->status, 'paid, but nothing exists on the server yet');
        $this->assertNotNull($service->expires_at, 'the paid period is kept whatever the server does');
        Queue::assertPushed(CreateJob::class);

        (new CreateJob($service))->handle();

        $this->assertSame('active', $this->service->fresh()->status);
        $this->assertSame(1, $this->welcomeMails());
        $action = ModuleAction::query()->sole();
        $this->assertSame(['create', ModuleAction::STATUS_COMPLETED, 1, 'FlakyServer'], [$action->action, $action->status, $action->attempts, $action->module]);
    }

    public function test_a_service_without_a_server_turns_active_on_payment_as_before(): void
    {
        $this->service->product->update(['server_id' => null]);

        $this->pay();

        $this->assertSame('active', $this->service->fresh()->status);
        Queue::assertNotPushed(CreateJob::class);
        $this->assertSame(0, ModuleAction::query()->count());
    }

    public function test_a_failed_create_stays_pending_without_a_welcome_mail_and_staff_hear_once_after_the_last_retry(): void
    {
        FlakyServer::$failures = 2;
        $job = new CreateJob($this->service);
        $this->assertSame(4, $job->tries);
        $this->assertSame([60, 600, 3600], $job->backoff());

        foreach ([1, 2] as $attempt) {
            try {
                (new CreateJob($this->service))->handle();
                $this->fail('the server refused it');
            } catch (Exception $e) {
                $this->assertStringContainsString('Server unreachable during create', $e->getMessage());
            }
            $action = ModuleAction::query()->sole();
            $this->assertSame([ModuleAction::STATUS_OPEN, $attempt], [$action->status, $action->attempts], 'one row, its attempts counted');
        }
        $this->assertSame('pending', $this->service->fresh()->status);
        $this->assertSame(0, $this->welcomeMails());

        $job->failed(new Exception('Server unreachable during create'));
        $job->failed(new Exception('Server unreachable during create'));
        $this->assertNotNull(ModuleAction::query()->sole()->given_up_at);
        Mail::assertQueued(SystemMail::class, 1);

        (new CreateJob($this->service))->handle(); // fixed: staff retry, or a later run
        $action = ModuleAction::query()->sole();
        $this->assertSame([ModuleAction::STATUS_COMPLETED, 3], [$action->status, $action->attempts]);
        $this->assertSame('active', $this->service->fresh()->status);
        $this->assertSame(1, $this->welcomeMails());
    }

    public function test_the_create_job_leaves_a_service_that_is_no_longer_pending_alone(): void
    {
        $this->service->update(['status' => 'active']); // started by staff meanwhile

        (new CreateJob($this->service))->handle();

        $this->assertSame([], FlakyServer::$calls);
        $this->assertSame(0, $this->welcomeMails());
        $this->assertSame((string) $this->service->id, (new CreateJob($this->service))->uniqueId(), 'one create at a time per service');
    }

    public function test_a_later_opposite_action_supersedes_a_failed_one(): void
    {
        $this->service->update(['status' => 'suspended']);
        FlakyServer::$failures = 1;
        try {
            (new SuspendJob($this->service))->handle();
        } catch (Exception) {
        }
        $this->assertSame(1, ModuleAction::query()->open()->count());

        ExtensionHelper::unsuspendServer($this->service->fresh()); // paid meanwhile: unsuspended

        $suspend = ModuleAction::query()->where('action', 'suspend')->sole();
        $this->assertSame([ModuleAction::STATUS_RESOLVED, 'superseded by unsuspend'], [$suspend->status, $suspend->resolution]);
    }

    public function test_no_module_is_nothing_to_do_but_a_deleted_server_is_a_failure(): void
    {
        $this->service->product->update(['server_id' => null]);
        try {
            ExtensionHelper::suspendServer($this->service->fresh());
        } catch (Exception) {
        }
        $this->assertSame(0, ModuleAction::query()->count());

        $gone = Server::query()->create(['name' => 'Gone', 'extension' => 'FlakyServer', 'type' => 'server', 'enabled' => true]);
        $this->service->update(['server_id' => $gone->id]);
        DB::table('extensions')->where('id', $gone->id)->update(['deleted_at' => now()]);
        try {
            ExtensionHelper::suspendServer($this->service->fresh());
        } catch (Exception) {
        }
        $this->assertStringContainsString('no longer exists', (string) ModuleAction::query()->open()->sole()->last_error);
    }

    public function test_staff_retry_or_resolve_pending_module_actions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        FlakyServer::$failures = 1;
        try {
            ExtensionHelper::createServer($this->service);
        } catch (Exception) {
        }
        $failed = ModuleAction::query()->sole();

        $this->livewireAs($staff)->test(ListModuleActions::class)
            ->assertCanSeeTableRecords([$failed])
            ->callTableAction('retry', $failed)
            ->assertNotified('Done');
        $this->assertSame(ModuleAction::STATUS_COMPLETED, $failed->fresh()->status);
        $this->assertSame('active', $this->service->fresh()->status);
        $this->assertSame(1, $this->welcomeMails(), 'the retry sends what the job would');

        $this->service->update(['status' => 'suspended']);
        FlakyServer::$failures = 1;
        try {
            ExtensionHelper::suspendServer($this->service->fresh());
        } catch (Exception) {
        }
        $suspend = ModuleAction::query()->open()->sole();
        $this->livewireAs($staff)->test(ListModuleActions::class)->callTableAction('resolve', $suspend);
        $suspend->refresh();
        $this->assertSame([ModuleAction::STATUS_RESOLVED, $staff->id], [$suspend->status, $suspend->resolved_by_staff_id]);
    }

    public function test_staff_without_the_services_permissions_do_not_see_them(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $tickets = Staff::factory()->create(['role_id' => Role::create(['name' => 'Support', 'permissions' => ['admin.tickets.viewAny']])->id]);

        $this->actingAs($tickets, 'staff')->get(ModuleActionResource::getUrl())->assertForbidden();
    }
}
