<?php

namespace Tests\Feature;

use App\Jobs\Server\CreateJob;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Service\OwedCreates;
use App\Services\Service\RenewServiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use RuntimeException;
use Tests\TestCase;

/** Independent review of #582 (OwedCreates / AfterCommit::job). */
class Review582Test extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Fixtures/FlakyServer.php';
        FlakyServer::$calls = [];
        FlakyServer::$failures = 0;
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

    private function service(string $autosetup = Product::AUTOSETUP_PAYMENT, string $planType = 'recurring', float $price = 10): Service
    {
        $server = Server::query()->create(['name' => 'Test server', 'extension' => 'FlakyServer', 'type' => 'server', 'enabled' => true]);
        $product = Product::factory()->create(['server_id' => $server->id, 'autosetup' => $autosetup]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => $planType]);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'currency_code' => 'USD']);

        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => $price, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_PENDING, 'expires_at' => null]);
    }

    private function invoiceFor(Service $service): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        return $invoice->fresh();
    }

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

    /**
     * R1. A create whose first attempt died mid-call (worker killed on its 120 s timeout, after the module was asked):
     * the retry finds `service-create:{id}` still held (600 s), returns on CreateSkipped, the job completes and its
     * unique lock goes; no module_actions row was ever written. OwedCreates cannot tell this from a lost job and asks
     * the module to create again, every 15 minutes for 7 days while the same thing repeats.
     */
    public function test_a_create_in_doubt_is_not_made_again_by_the_sweep(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service);
        $real = app('queue');
        Queue::fake();
        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'wire-doubt');
        Queue::assertPushed(CreateJob::class);

        // Attempt 1: took the create lock, asked the module (the account is being made), then the worker was killed.
        Cache::lock("service-create:{$service->id}", 600)->get();
        FlakyServer::$calls[] = 'create';
        // Attempt 2 (retry_after 300 s later): the lock is still held, CreateSkipped, the job ends «successfully».
        (new CreateJob($service->fresh()))->handle();
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
        $this->assertSame(0, ModuleAction::query()->where('service_id', $service->id)->count(), 'no trace of the attempt');

        // The job ended (CallQueuedHandler releases its unique lock); 16 minutes on, the create lock (600 s) expired.
        Cache::lock('laravel_unique_job:' . CreateJob::class . ':' . $service->id)->forceRelease();
        Cache::lock("service-create:{$service->id}")->forceRelease();
        Carbon::setTestNow(now()->addMinutes(16));
        Queue::swap($real); // the sync queue: the sweep's job runs
        $this->artisan('app:owed-creates')->assertExitCode(0);

        $this->assertSame(['create'], FlakyServer::$calls, 'the module was asked to create the account a second time');
    }

    /**
     * R2. An order with nothing to pay (a free plan, a 100% coupon): PlaceOrderService starts it through
     * RenewServiceService with no line. Its CreateJob lost after the commit is the same S4 hole, but OwedCreates needs a
     * line (applied, or a paid invoice) and the sweep's query needs one too: never asked again. (Fixed: the failed push
     * writes its own «not queued» row, listed for staff with Retry, and staff are told.)
     */
    public function test_a_free_order_whose_create_was_lost_is_asked_again(): void
    {
        $service = $this->service(price: 0);
        $this->brokenQueue();
        try {
            (new RenewServiceService)->handle($service); // as PlaceOrderService does when nothing is due
        } catch (RuntimeException) {
        }
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
        $this->assertNotNull($service->fresh()->expires_at, 'its period was given');

        $this->assertTrue(ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'create')
            ->where('last_error', 'like', ModuleAction::NOT_QUEUED . '%')->exists(), 'listed for staff, with Retry');
    }

    /**
     * R3. An import at cut-over: WHMCS's pending services keep their regdate as created_at. One of a product set up on
     * order, ordered in WHMCS in the last 7 days and never paid or ordered here, is created by the sweep on its own,
     * although the PR says imports stay with staff.
     */
    public function test_an_imported_pending_service_is_left_to_staff(): void
    {
        $service = $this->service(Product::AUTOSETUP_ORDER);
        $service->forceFill(['created_at' => now()->subDays(2), 'whmcs_server_id' => 3])->save(); // regdate, from the import
        Queue::fake();

        $this->artisan('app:owed-creates')->assertExitCode(0);
        Queue::assertNotPushed(CreateJob::class);
    }

    /**
     * R4. The payment was refunded in full while the account was still owed (nothing reached the customer, the customer
     * asked for the money back): the cron's paid_applied stops there (keepsItsMoney), the sweep does not.
     */
    public function test_owes_ignores_a_refunded_payment(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service);
        $this->brokenQueue();
        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'wire-refund');
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
        $invoice->transactions()->update(['refunded_amount' => 10]);

        $this->assertFalse(OwedCreates::owes($service->fresh()), 'a refunded payment owes no account');
    }
}
