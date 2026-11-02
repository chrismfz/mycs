<?php

namespace Tests\Feature;

use App\Admin\Resources\ModuleActionResource\Pages\ListModuleActions;
use App\Events\Service\Updated as ServiceUpdated;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\ProcessPaidInvoiceService;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\AcceptOrder;
use Filament\Facades\Filament;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Round-2 adversarial review of #582 (AfterCommit::job, «Not queued» module actions and their replay, CreateJob no longer
 * unique). Each test fails on the PR head (dc76fbc3) for the reason in its docblock.
 */
#[Group('adversarial')]
class ProvisioningAfterCommitRound2AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Fixtures/FlakyServer.php';
        FlakyServer::$failures = 0;
        FlakyServer::$calls = [];
        config(['settings.credits_auto_use' => false, 'settings.mail_disable' => false, 'settings.system_email_address' => 'staff@example.test']);
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

    /** A product (on a server of $extension, or none), its monthly plan and price. */
    private function product(string $autosetup = Product::AUTOSETUP_PAYMENT, ?string $extension = 'FlakyServer'): Product
    {
        $server = $extension === null ? null : Server::query()->create(['name' => 'Test server', 'extension' => $extension, 'type' => 'server', 'enabled' => true]);
        $product = Product::factory()->create(['server_id' => $server?->id, 'autosetup' => $autosetup]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);

        return $product;
    }

    private function service(Product $product, string $status = Service::STATUS_PENDING, array $attributes = []): Service
    {
        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $product->plans()->first()->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => $status,
            'expires_at' => $status === Service::STATUS_PENDING ? null : '2026-11-07'] + $attributes);
    }

    /** An open invoice of one line for $service. */
    private function invoiceFor(Service $service): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    /** A queue other than the database one (redis, sqs) whose pushes of $failing fail after the commit; the others run. */
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
        Queue::extend('adv2-broken', fn () => new class($queue)
        {
            public function __construct(private $queue) {}

            public function connect($config)
            {
                return $this->queue;
            }
        });
        config(['queue.default' => 'adv2-broken', 'queue.connections.adv2-broken' => ['driver' => 'adv2-broken']]);
    }

    /** The queue is back (the sync one: a job pushed runs at once). */
    private function queueBack(): void
    {
        config(['queue.default' => 'sync']);
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
    }

    private function mails(string $template): int
    {
        return EmailLog::query()->where('template_key', $template)->count();
    }

    /**
     * ADV2-582-1. A server job the queue never took (AfterCommit::job on a queue other than the database one) is written
     * by RetriesModuleAction::notQueued() through recordFailure(): open, given_up_at null. The staff mail says «It is
     * listed in Pending Module Actions: retry it there», and the PR says «staff are emailed and can Retry». But
     * ModuleActionResource shows Retry only once given_up_at is set, and nothing ever sets it (the job never ran, so its
     * failed() never runs). The only button left is «Mark resolved», which for a create starts the service with no account
     * (ModuleAction::markResolved → startCreated). The cron's suspension written so has nothing else either: askAgainNotQueued
     * never re-sends a suspend, and the cron never picks a suspended service again, so it runs on unsuspended for good.
     */
    public function test_a_server_job_the_queue_never_took_can_be_retried_by_staff(): void
    {
        $service = $this->service($this->product());
        $invoice = $this->invoiceFor($service);
        $this->brokenQueue(CreateJob::class);
        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'wire-adv2-1');
        $create = ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'create')->sole();
        $this->assertStringStartsWith(ModuleAction::NOT_QUEUED, (string) $create->last_error, 'precondition: written as not queued');

        $suspended = $this->service($this->product(), Service::STATUS_SUSPENDED, ['dunning_suspended_at' => now()]);
        (new SuspendJob($suspended))->notQueued(new RuntimeException('Queue push unavailable after commit')); // as AfterCommit::job does
        $suspend = ModuleAction::query()->open()->where('service_id', $suspended->id)->where('action', 'suspend')->sole();

        $staff = $this->staff();
        $this->livewireAs($staff)->test(ListModuleActions::class)->assertTableActionVisible('retry', $create);
        $this->livewireAs($staff)->test(ListModuleActions::class)->assertTableActionVisible('retry', $suspend);
    }

    /**
     * ADV2-582-2. «On another queue, each failed push leaves its own row» (PR comment, answering round 1's AcceptOrder
     * suspicion) does not hold. AfterCommit::job's callback rethrows after notQueued(), and Laravel runs the after-commit
     * callbacks of one commit in a row (DatabaseTransactionRecord::executeCallbacks, no try/catch): the first failed push
     * aborts the rest. Staff accept an order of two services set up on accept while the queue is down: the first gets its
     * «Not queued» row and mail; the second's create is never pushed, never recorded, nobody is told, and nothing asks for
     * it again (it is unpaid: no replay, no sweep). The same drops the Service\Updated event (Stripe, chat) a renewal saves
     * after the unsuspension's callback, the cron's suspension the same way, and checkout's later services (SetUpOnOrder).
     */
    public function test_every_service_of_an_accepted_order_whose_create_was_not_queued_is_recorded(): void
    {
        $product = $this->product(Product::AUTOSETUP_ACCEPT);
        $order = Order::query()->forceCreate(['client_id' => $this->client->id, 'currency_code' => 'USD', 'status' => Order::STATUS_PENDING]);
        $first = $this->service($product, attributes: ['order_id' => $order->id]);
        $second = $this->service($product, attributes: ['order_id' => $order->id]);
        $this->brokenQueue(CreateJob::class);

        try {
            AcceptOrder::handle($order, null, startUnpaid: true);
        } catch (Throwable) {
            // the error goes on to whoever committed (AfterCommit::job)
        }

        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status, 'precondition: the acceptance committed');
        foreach ([$first, $second] as $service) {
            $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
            $this->assertTrue(ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'create')
                ->where('last_error', 'like', ModuleAction::NOT_QUEUED . '%')->exists(),
                "service #{$service->id}: its create was never pushed and nothing records it");
        }
    }

    /**
     * ADV2-582-2 (b). Same cause: RenewServiceService::activate() calls AfterCommit::job(UnsuspendJob) before it saves the
     * service, so ServiceObserver's after-commit Service\Updated (status suspended → active, the new expires_at: Stripe's
     * subscription, PayPal, chat) is registered after it. The push fails, the callback rethrows, and Updated never fires.
     * The replay finds the line applied and saves nothing, so it never fires later either. ServiceObserver's own comment
     * says why its callback never throws: «a listener that throws would skip the other services'».
     */
    public function test_a_failed_unsuspension_push_does_not_swallow_the_services_updated_event(): void
    {
        $service = $this->service($this->product(), Service::STATUS_SUSPENDED, ['dunning_suspended_at' => now()->subDay()]);
        $invoice = $this->invoiceFor($service);
        $updated = [];
        Event::listen(ServiceUpdated::class, function (ServiceUpdated $event) use (&$updated, $service) {
            if ((int) $event->service->id === (int) $service->id && array_key_exists('status', $event->changes)) {
                $updated[] = $event->changes['status'];
            }
        });
        $this->brokenQueue(UnsuspendJob::class);

        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'wire-adv2-2b');

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: saved active');
        $this->assertSame([Service::STATUS_ACTIVE], $updated, 'its Service\Updated never fired');
    }

    /**
     * ADV2-582-3. A «Not queued» row is closed only by recordSuccess() in ExtensionHelper::serverAction. A paid unsuspension
     * of a service without a server goes through the queue all the same (RenewServiceService: «its job only mails then»),
     * so a failed push writes «Not queued: unsuspend». Re-sent by the replay, the job runs, the module has nothing to do
     * (NothingToDoOnServer, caught by UnsuspendJob) and the customer gets «unsuspended», but the row stays open for good,
     * in Pending Module Actions as a failure, and every later replay of a line of that service (askAgainNotQueued is per
     * service, not per invoice) sends the unsuspension and its mail again. The same for a re-sent create of a service no
     * longer pending (CreateJob returns early) or with nothing to do on its server.
     */
    public function test_a_replayed_unsuspension_closes_its_not_queued_row_and_mails_once(): void
    {
        $service = $this->service($this->product(extension: null), Service::STATUS_SUSPENDED, ['dunning_suspended_at' => now()->subDay()]);
        $invoice = $this->invoiceFor($service);
        $this->brokenQueue(UnsuspendJob::class);
        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'wire-adv2-3');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: paid and shown active');
        $this->assertTrue(ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'unsuspend')->exists(), 'precondition: not queued');

        $this->queueBack();
        (new ProcessPaidInvoiceService)->handle($invoice->fresh()); // the cron's paid_applied
        $this->assertSame(1, $this->mails('server_unsuspended'), 'precondition: the replay re-sent it and it ran');

        $this->assertFalse(ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'unsuspend')->exists(),
            'the unsuspension ran, its «Not queued» row is still open');
        (new ProcessPaidInvoiceService)->handle($invoice->fresh()); // a later replay of a line of the service
        $this->assertSame(1, $this->mails('server_unsuspended'), 'the customer is told «unsuspended» again');
    }

    /**
     * ADV2-582-4. CreateJob is no longer ShouldBeUnique: the payment's and staff's Accept (or a replay's) both sit in the
     * queue. «A second job is harmless: the create takes its own lock and re-checks still pending under it.» Not for a
     * module without createServer (Proxmox: NothingToDoOnServer, every create of it): createServer releases its lock when
     * checkServer throws, and only then does CreateJob start the service (ExtensionHelper::startCreated). A second job that
     * takes the lock in between still reads pending, starts it too, and both send the welcome mail.
     */
    public function test_two_creates_of_a_module_without_create_send_one_welcome_mail(): void
    {
        $service = $this->service($this->product(extension: 'Proxmox'));
        $this->assertTrue($service->hasServerActions(), 'precondition: it waits for its create');
        $payment = new CreateJob($service->fresh());
        $accept = new CreateJob($service->fresh());

        // The second worker picks its job right after the first released the create's lock, before it started the service.
        $raced = false;
        Event::listen(TransactionBeginning::class, function () use (&$raced, $service, $accept) {
            if ($raced || Service::query()->whereKey($service->id)->value('status') !== Service::STATUS_PENDING) {
                return;
            }
            $lock = Cache::lock("service-create:{$service->id}", 1);
            if (!$lock->get()) {
                return; // the first job still holds it
            }
            $lock->release();
            $raced = true;
            $accept->handle();
        });
        $payment->handle();

        $this->assertTrue($raced, 'precondition: the second job ran in the window');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertSame(1, $this->mails('new_server_created'), 'two welcome mails for one service');
    }
}
