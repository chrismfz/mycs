<?php

namespace Tests\Feature;

use App\Jobs\Server\CreateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\ProcessPaidInvoiceService;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Service\RenewServiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Predeploy S4: a paid service's CreateJob lost on its way to the queue after the commit that applied its period. The
 * replay of its invoice and app:owed-creates ask for it again (OwedCreates); the server jobs a payment decides go with
 * its transaction on the database queue (AfterCommit::job).
 */
class ProvisioningAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Fixtures/FlakyServer.php';
        config(['settings.credits_auto_use' => false]);
        Mail::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->client = User::factory()->create()->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A pending service of a product set up on payment, on a server, and its open order invoice. */
    private function order(string $autosetup = Product::AUTOSETUP_PAYMENT, string $status = Service::STATUS_PENDING): Invoice
    {
        $server = Server::query()->create(['name' => 'Test server', 'extension' => 'FlakyServer', 'type' => 'server', 'enabled' => true]);
        $product = Product::factory()->create(['server_id' => $server->id, 'autosetup' => $autosetup]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => $status, 'expires_at' => $status === Service::STATUS_PENDING ? null : '2026-11-07']);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    /** A queue whose pushes of CreateJob fail (the database gone right after the commit). */
    private function brokenQueue(): void
    {
        $queue = new class extends SyncQueue
        {
            protected function executeJob($job, $data = '', $queue = null)
            {
                if ($job instanceof CreateJob) {
                    throw new RuntimeException('Queue insert unavailable after commit');
                }

                return 0;
            }
        };
        $queue->setContainer(app());
        Queue::extend('review-broken', fn () => new class($queue)
        {
            public function __construct(private $queue) {}

            public function connect($config)
            {
                return $this->queue;
            }
        });
        config(['queue.default' => 'review-broken', 'queue.connections.review-broken' => ['driver' => 'review-broken']]);
    }

    private function serviceOf(Invoice $invoice): Service
    {
        return Service::findOrFail($invoice->items()->sole()->reference_id);
    }

    /** The reviewer's reproduction (predeploy S4). */
    public function test_paid_service_recovers_a_create_job_lost_after_commit(): void
    {
        $invoice = $this->order();
        $line = $invoice->items()->sole();
        $service = $this->serviceOf($invoice);
        $this->assertTrue($service->fresh()->hasServerActions());
        $this->brokenQueue();
        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'wire-provision');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertNotNull($line->fresh()->applied_at, 'Period committed before queue failed');
        $this->assertNotNull($invoice->fresh()->apply_pending_since, 'Recovery marker is retained after dispatch failure');
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
        Queue::fake();
        (new ProcessPaidInvoiceService)->handle($invoice->fresh());
        $this->assertNull($invoice->fresh()->apply_pending_since, 'Recovery cleared its marker without delivering the service');
        Queue::assertPushed(CreateJob::class);
    }

    public function test_a_failed_push_is_listed_for_staff_and_they_are_told(): void
    {
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $invoice = $this->order();
        $service = $this->serviceOf($invoice);
        $this->brokenQueue();
        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'wire-lock');

        $row = ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'create')->sole();
        $this->assertStringStartsWith(ModuleAction::NOT_QUEUED, (string) $row->last_error, 'in Pending Module Actions, with Retry');
        $this->assertTrue(EmailLog::query()->where('subject', "Module action not queued: create of service #{$service->id}")->exists(), 'staff told');
    }

    public function test_a_create_is_written_with_its_payment_on_the_database_queue(): void
    {
        $invoice = $this->order();
        $service = $this->serviceOf($invoice);
        $line = $invoice->items()->sole();
        config(['queue.default' => 'database']);

        try {
            DB::transaction(function () use ($service, $line) {
                (new RenewServiceService)->handle($service->fresh(), $line->fresh());
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(0, DB::table('jobs')->count(), 'rolled back with it');

        DB::transaction(function () use ($service, $line) {
            (new RenewServiceService)->handle($service->fresh(), $line->fresh());
            $this->assertSame(1, DB::table('jobs')->count(), 'written in the transaction, not after its commit');
        });
        $this->assertStringContainsString(str_replace('\\', '\\\\', CreateJob::class), (string) DB::table('jobs')->value('payload'));
    }

    public function test_the_sweep_tells_staff_of_a_paid_service_still_waiting_and_creates_nothing(): void
    {
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $invoice = $this->order();
        $service = $this->serviceOf($invoice);
        Queue::fake(); // its job lost somewhere past the queue: nothing recorded
        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'wire-sweep');
        Queue::fake();

        $this->artisan('app:owed-creates')->assertExitCode(0);
        $this->assertSame(0, EmailLog::query()->where('subject', 'Paid services waiting for their account')->count(), 'a few minutes: its own job may be on its way');

        Carbon::setTestNow(now()->addMinutes(16));
        $this->artisan('app:owed-creates')->assertExitCode(0);
        $this->artisan('app:owed-creates')->assertExitCode(0);
        Queue::assertNotPushed(CreateJob::class);
        $this->assertSame(1, EmailLog::query()->where('subject', 'Paid services waiting for their account')->where('body', 'like', "%#{$service->id}%")->count(), 'told once');
    }

    public function test_the_sweep_leaves_alone_what_staff_hold_or_wait_for(): void
    {
        // A create that failed (open: staff retry it), one resolved by staff, a product set up on staff's accept, and one
        // paid over a week ago (an import, a service left as it is).
        $failed = $this->order();
        $resolved = $this->order();
        $onAccept = $this->order(Product::AUTOSETUP_ACCEPT);
        $old = $this->order();
        Queue::fake();
        foreach ([$failed, $resolved, $onAccept] as $invoice) {
            (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'wire-' . $invoice->id);
        }
        Carbon::setTestNow('2026-11-01 09:00:00');
        (new RecordPaymentService)->handle($old, null, 10, transactionId: 'wire-old');
        Carbon::setTestNow('2026-11-10 10:00:00');
        ModuleAction::recordFailure($this->serviceOf($failed), 'create', 'FlakyServer', new RuntimeException('down'));
        ModuleAction::recordFailure($this->serviceOf($resolved), 'create', 'FlakyServer', new RuntimeException('down'))
            ->forceFill(['status' => ModuleAction::STATUS_RESOLVED])->save();
        foreach ([$failed, $resolved, $onAccept, $old] as $invoice) {
            $this->assertSame(Service::STATUS_PENDING, $this->serviceOf($invoice)->fresh()->status);
        }

        Queue::fake();
        $this->artisan('app:owed-creates')->assertExitCode(0);
        Queue::assertNotPushed(CreateJob::class);
    }

    public function test_an_unsuspension_is_written_with_its_payment_on_the_database_queue(): void
    {
        $invoice = $this->order(status: Service::STATUS_SUSPENDED);
        $service = $this->serviceOf($invoice);
        $service->forceFill(['dunning_suspended_at' => now()->subDay()])->save();
        $line = $invoice->items()->sole();
        config(['queue.default' => 'database']);

        // Rolled back: no job.
        try {
            DB::transaction(function () use ($service, $line) {
                (new RenewServiceService)->handle($service->fresh(), $line->fresh());
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);

        // Committed: the job is there with it, in the same transaction.
        DB::transaction(function () use ($service, $line) {
            (new RenewServiceService)->handle($service->fresh(), $line->fresh());
            $this->assertSame(1, DB::table('jobs')->count(), 'written in the transaction, not after its commit');
        });
        $this->assertStringContainsString(str_replace('\\', '\\\\', UnsuspendJob::class), (string) DB::table('jobs')->value('payload'));
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
    }
}
