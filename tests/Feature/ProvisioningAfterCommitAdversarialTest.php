<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Client;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Adversarial review of #582 (predeploy S4: OwedCreates, app:owed-creates, AfterCommit::job). Each test fails on the PR
 * head for the reason in its docblock.
 */
#[Group('adversarial')]
class ProvisioningAfterCommitAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Fixtures/FlakyServer.php';
        FlakyServer::$failures = 0;
        FlakyServer::$calls = [];
        config(['settings.credits_auto_use' => false]);
        Mail::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->client = User::factory()->create()->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        FlakyServer::$calls = [];
        parent::tearDown();
    }

    /** A product on a FlakyServer, its plan and price. */
    private function product(string $autosetup = Product::AUTOSETUP_PAYMENT, string $type = 'recurring'): Product
    {
        $server = Server::query()->create(['name' => 'Test server', 'extension' => 'FlakyServer', 'type' => 'server', 'enabled' => true]);
        $product = Product::factory()->create(['server_id' => $server->id, 'autosetup' => $autosetup]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => $type]);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);

        return $product;
    }

    private function service(Product $product, string $status = Service::STATUS_PENDING): Service
    {
        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $product->plans()->first()->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => $status, 'expires_at' => $status === Service::STATUS_PENDING ? null : '2026-11-07']);
    }

    /** A pending service of a product set up on payment, on a server, and its open order invoice. */
    private function order(string $status = Service::STATUS_PENDING): Invoice
    {
        $service = $this->service($this->product(), $status);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    private function serviceOf(Invoice $invoice): Service
    {
        return Service::findOrFail($invoice->items()->sole()->reference_id);
    }

    /** A queue (not the database one: redis, sqs) whose pushes of $failing fail after the commit; the others run. */
    private function brokenQueue(string $failing): void
    {
        $queue = new class($failing) extends SyncQueue
        {
            public function __construct(private string $failing)
            {
                parent::__construct();
            }

            protected function executeJob($job, $data = '', $queue = null)
            {
                if ($job instanceof $this->failing) {
                    throw new RuntimeException('Queue push unavailable after commit');
                }

                return 0;
            }
        };
        $queue->setContainer(app());
        Queue::extend('adv-broken', fn () => new class($queue)
        {
            public function __construct(private $queue) {}

            public function connect($config)
            {
                return $this->queue;
            }
        });
        config(['queue.default' => 'adv-broken', 'queue.connections.adv-broken' => ['driver' => 'adv-broken']]);
    }

    /**
     * ADV-582-1. A pending service staff made by hand (ServiceResource's «Create»: status pending by default, no order, no
     * invoice) on a product set up «as soon as an order is placed». No order was placed and nothing was paid: before #582
     * it waited for staff's «Create». OwedCreates::owes() calls any pending on-order service owed, and the sweep makes
     * its account on the server 15 minutes later, unordered and unpaid.
     */
    public function test_sweep_never_sets_up_a_pending_service_staff_made_without_an_order(): void
    {
        $service = $this->service($this->product(Product::AUTOSETUP_ORDER));
        $this->assertNull($service->order_id);
        $this->assertSame(0, $service->invoiceItems()->count());

        Carbon::setTestNow(now()->addMinutes(16));
        Queue::fake();
        $this->artisan('app:owed-creates')->assertExitCode(0);

        $this->assertCount(0, Queue::pushed(CreateJob::class), 'an account made for a service nobody ordered or paid, staff not asked');
    }

    /**
     * ADV-582-2. Paid, its create lost after the commit (the case #582 is for), and then the payment refunded in full: the
     * customer has his money back. The cron's own replay checks that (CronJob::keepsItsMoney: «refunded, not applied»);
     * the sweep only looks at applied_at, so 15 minutes later it makes the account anyway: delivered without payment.
     */
    public function test_sweep_does_not_make_the_account_of_a_payment_refunded_since(): void
    {
        $invoice = $this->order();
        $service = $this->serviceOf($invoice);
        $this->brokenQueue(CreateJob::class);
        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'wire-refunded');
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);

        $payment = $invoice->fresh()->transactions()->sole();
        ExtensionHelper::refundLocally($payment, 10, notify: false);
        $this->assertEqualsWithDelta(10.0, (float) $payment->fresh()->refunded_amount, 0.001, 'the whole payment went back');

        Carbon::setTestNow(now()->addMinutes(16));
        Queue::fake();
        $this->artisan('app:owed-creates')->assertExitCode(0);

        $this->assertCount(0, Queue::pushed(CreateJob::class), 'an account made for a payment that was refunded');
    }

    /**
     * ADV-582-3. Its account was made (the create completed on the server), and the service is pending again (staff set it
     * so, or the start after the create never committed). owes() only skips open or resolved creates, not a completed
     * one: the sweep asks again and the module makes a second account for the same service.
     */
    public function test_sweep_never_makes_a_second_account_for_a_service_whose_create_completed(): void
    {
        $invoice = $this->order();
        $service = $this->serviceOf($invoice);
        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'wire-made'); // sync queue: the account is made
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertSame(['create'], FlakyServer::$calls);
        $this->assertTrue(ModuleAction::query()->where('service_id', $service->id)->where('action', 'create')->where('status', ModuleAction::STATUS_COMPLETED)->exists());

        // Pending again, its account on the server.
        $service->fresh()->forceFill(['status' => Service::STATUS_PENDING])->save();

        Carbon::setTestNow(now()->addMinutes(16));
        $this->artisan('app:owed-creates')->assertExitCode(0);

        $this->assertSame(['create'], FlakyServer::$calls, 'a second account made for a service whose create completed');
    }

    /**
     * ADV-582-4. A WHMCS import on cut-over day: a service WHMCS left pending (its create failed there, or staff hold it),
     * one-time plan, its invoice paid in WHMCS three days before. The importer brings its line without applied_at and
     * its created_at/paid_at from WHMCS. #582 says imports stay with staff, but the 7-day bound is the only guard: the
     * sweep finds a paid one-time invoice within the week and makes the account on the server by itself.
     */
    public function test_sweep_leaves_a_pending_service_imported_from_whmcs_to_staff(): void
    {
        $service = $this->service($this->product(Product::AUTOSETUP_PAYMENT, 'one-time'));
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-07']);
        $invoice->items()->create(['description' => 'Hosting (WHMCS)', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        // As ImportFromWhmcs writes them: raw rows, WHMCS's dates, no applied_at.
        DB::table('services')->where('id', $service->id)->update(['created_at' => '2026-11-07 10:00:00', 'whmcs_server_id' => 3]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => '2026-11-07 10:05:00']);
        $this->assertNull($invoice->items()->sole()->applied_at);

        Queue::fake();
        $this->artisan('app:owed-creates')->assertExitCode(0);

        $this->assertCount(0, Queue::pushed(CreateJob::class), 'an imported WHMCS pending service set up by the sweep, not by staff');
    }

    /**
     * ADV-582-5. On another queue than the database one (redis is configured in config/queue.php), the unsuspension of a
     * service paid out of its suspension is pushed after the commit. The push fails: the service is saved active, its
     * account stays suspended on the server, and the invoice keeps its marker. AfterCommit::job's doc says the marker
     * then gets it done again, but the replay finds the line applied and asks again only for a create (OwedCreates):
     * paid, shown active, still suspended on the server, for good. (The same for UpgradeJob in ServiceUpgradeService.)
     */
    public function test_a_lost_unsuspension_is_asked_for_again_by_the_payments_replay(): void
    {
        $invoice = $this->order(Service::STATUS_SUSPENDED);
        $service = $this->serviceOf($invoice);
        $service->forceFill(['dunning_suspended_at' => now()->subDay()])->save();
        $this->brokenQueue(UnsuspendJob::class);

        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'wire-unsuspend');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'shown active');
        $this->assertNotContains('unsuspend', FlakyServer::$calls, 'never unsuspended on the server');
        $this->assertNotNull($invoice->fresh()->apply_pending_since, 'the marker is kept for the replay');

        Queue::fake();
        (new ProcessPaidInvoiceService)->handle($invoice->fresh());

        Queue::assertPushed(UnsuspendJob::class, 'the replay never asks again for the unsuspension it lost');
    }
}
