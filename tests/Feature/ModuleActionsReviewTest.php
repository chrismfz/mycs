<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UpgradeJob;
use App\Mail\Mail as MyCSMail;
use App\Mail\SystemMail;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\RenewServiceService;
use App\Services\Service\RetryModuleAction;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use Tests\TestCase;

/** Independent review of feat/module-actions (cd52bc6): each test fails on the branch. */
class ModuleActionsReviewTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Fixtures/FlakyServer.php';
        FlakyServer::$failures = 0;
        FlakyServer::$calls = [];
        Queue::fake();
        Mail::fake();
        config(['settings.mail_disable' => false, 'settings.system_email_address' => 'staff@example.com']);
        $this->server = Server::query()->create(['name' => 'Flaky', 'extension' => 'FlakyServer', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);
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

    /**
     * R1. TerminateJob has no status guard (Suspend/Unsuspend/Create do), and it is now retried for an hour and
     * offered to staff as «Retry». A terminate that failed, retried after staff revived the service, deletes a live
     * account (audit SVC-11 scenario 2, listed under Σ1 in docs/audit-sweep.md).
     */
    public function test_r1_a_retried_terminate_leaves_a_service_staff_revived_alone(): void
    {
        $this->service->update(['status' => 'terminated']);
        FlakyServer::$failures = 1;
        try {
            (new TerminateJob($this->service))->handle(); // the cron's terminate: the server is down
        } catch (Exception) {
        }
        $open = ModuleAction::query()->open()->sole();

        $this->service->fresh()->update(['status' => 'active']); // staff revive it (the customer paid after all)

        // Staff's «Retry» from Pending Module Actions (or the job's own retry an hour later).
        RetryModuleAction::run($open);

        $this->assertNotContains('terminate', array_slice(FlakyServer::$calls, 1), 'a live account was terminated');
    }

    /**
     * R2. Server jobs now have tries = 4 and $timeout = 120, but the queues' retry_after is 90: a create still running
     * after 90 s is reserved again by another worker and, with attempts left, run a second time concurrently (before
     * this change, tries = 1 made the second reservation fail without running). Two accounts on the server.
     */
    public function test_r2_retry_after_is_longer_than_the_server_jobs_timeout(): void
    {
        $timeout = (new CreateJob($this->service))->timeout;

        $this->assertGreaterThan($timeout, (int) config('queue.connections.database.retry_after'));
        $this->assertGreaterThan($timeout, (int) config('queue.connections.redis.retry_after'));
    }

    /**
     * R3. «Mark resolved» on a failed create («an account fixed by hand on the server») closes the action but leaves the
     * paid service pending: out of Pending Module Actions and the dashboard counter, never billed (invoices_created
     * takes active/suspended only), its license suspended. Stuck with no staff visibility.
     */
    public function test_r3_resolving_a_failed_create_does_not_leave_the_paid_service_pending(): void
    {
        $this->pay();
        FlakyServer::$failures = 4;
        try {
            (new CreateJob($this->service))->handle();
        } catch (Exception) {
        }
        $open = ModuleAction::query()->open()->sole();

        $open->markResolved(null); // staff created the account by hand

        $this->assertSame(0, ModuleAction::query()->open()->count());
        $this->assertNotSame('pending', $this->service->fresh()->status, 'paid, account made by hand, resolved: still pending and listed nowhere');
    }

    /**
     * R4. A failure outside the adapter's PHP (the worker's timeout kills a slow create, a MaxAttemptsExceeded) records
     * nothing: failed() finds no open row, so no «needs staff», no email, and the paid service stays pending with no
     * entry in Pending Module Actions.
     */
    public function test_r4_a_create_job_that_times_out_is_staffs(): void
    {
        $this->pay();
        $this->assertSame('pending', $this->service->fresh()->status);

        (new CreateJob($this->service))->failed(new TimeoutExceededException('App\Jobs\Server\CreateJob has timed out.'));

        $this->assertSame(1, ModuleAction::query()->open()->whereNotNull('given_up_at')->count(), 'nothing listed for staff');
        Mail::assertQueued(SystemMail::class, 1);
    }

    /**
     * R5. The product lost its server between the payment and the job (staff detached it while the create was waiting
     * for a retry): CreateJob swallows «No server assigned», mails the welcome, and leaves the service pending for ever
     * (no row, nothing to retry). Payment without a server activates the service; the job does not.
     */
    public function test_r5_a_create_with_no_server_left_does_not_leave_the_service_pending(): void
    {
        $this->pay();
        $this->service->product->update(['server_id' => null]);

        (new CreateJob($this->service->fresh()))->handle();

        $this->assertSame('active', $this->service->fresh()->status, 'welcome mail sent, still pending');
        $this->assertSame(1, $this->welcomeMails());
    }

    /**
     * R6. A module without upgradeServer (DirectAdmin, Plesk, Virtfusion; audit SVC-17) fails every upgrade: now each
     * paid upgrade is retried 3 times and ends as a «needs staff» row plus a system email that no retry can ever clear.
     */
    public function test_r6_an_upgrade_on_a_module_without_upgrade_is_not_a_pending_module_action(): void
    {
        $da = Server::query()->create(['name' => 'DA', 'extension' => 'DirectAdmin', 'type' => 'server', 'enabled' => true]);
        $this->service->update(['status' => 'active', 'server_id' => $da->id]);

        try {
            (new UpgradeJob($this->service->fresh()))->handle();
        } catch (Exception) {
        }

        $this->assertSame(0, ModuleAction::query()->open()->count(), ModuleAction::query()->value('last_error') ?? '');
    }

    /**
     * R7. Staff's «Trigger Extension Action → Create» on an unpaid order now also starts it (startCreated): active with
     * no expiry, it leaves orders_cancelled (pending only) and is never billed or suspended (both need expires_at).
     */
    public function test_r7_a_manual_create_on_an_unpaid_order_stays_in_reach_of_the_cron(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD',
            'due_at' => now()->subDays(20)]);
        $invoice->items()->create(['reference_id' => $this->service->id, 'reference_type' => Service::class, 'description' => 'Order',
            'price' => 10, 'quantity' => 1]);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => now()->subDays(20)]);
        DB::table('services')->where('id', $this->service->id)->update(['created_at' => now()->subDays(20)]);

        ExtensionHelper::createServer($this->service->fresh()); // staff, before payment

        $this->artisan('app:cron-job')->assertExitCode(0);
        $service = $this->service->fresh();
        $this->assertFalse($service->status === 'active' && $service->expires_at === null,
            'active, unpaid, no expiry: never cancelled, billed or suspended');
    }
}
