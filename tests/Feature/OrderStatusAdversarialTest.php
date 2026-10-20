<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Exceptions\DisplayException;
use App\Jobs\Server\CreateJob;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\AcceptOrder;
use App\Services\Order\CancelOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\Service\DeleteService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Adversarial review of Σ1-b2 (bfa488b): order status, Accept / Cancel / Cancel & Refund / Fraud.
 * Every test here fails on bfa488b and states the expected behaviour.
 */
class OrderStatusAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Server $server;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Fixtures/FlakyServer.php';
        FlakyServer::$failures = 0;
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-01-01 10:00:00');
        $this->server = Server::query()->create(['name' => 'Flaky', 'extension' => 'FlakyServer', 'type' => 'server', 'enabled' => true]);
        $this->product = $this->createProduct()->product;
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $autosetup, bool $server = true): Order
    {
        $this->product->update(['autosetup' => $autosetup, 'server_id' => $server ? $this->server->id : null, 'stock' => 10]);
        $product = $this->product->fresh();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')]);

        return $placed->order->fresh();
    }

    private function firstInvoice(Order $order): Invoice
    {
        return Invoice::query()->whereHas('items', fn ($items) => $items->whereIn('reference_id', $order->services()->pluck('id'))
            ->where('reference_type', Service::class))->orderBy('id')->firstOrFail();
    }

    private function pay(Invoice $invoice, float $amount, string $ref): void
    {
        (new RecordPaymentService)->handle($invoice, null, $amount, transactionId: $ref);
    }

    private function renewalInvoice(Service $service, string $from, string $to): Invoice
    {
        $invoice = Invoice::query()->create(['client_id' => $service->client_id, 'due_at' => $from, 'currency_code' => 'USD', 'status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'price' => 10, 'quantity' => 1,
            'description' => 'Renewal', 'period_start' => $from, 'period_end' => $to]);

        return $invoice;
    }

    private function refundable(Invoice $invoice): float
    {
        return (float) $invoice->transactions()->get()->sum(fn ($t) => (float) $t->refundable_amount);
    }

    /**
     * ADV-1. Cancel & Refund refunds EVERY paid invoice that ever billed the order's services: months of renewals
     * already delivered, not the order's own invoice (WHMCS refunds tblorders.invoiceid only).
     */
    public function test_cancel_and_refund_does_not_refund_renewals_already_delivered(): void
    {
        $order = $this->order(Product::AUTOSETUP_PAYMENT, server: false);
        $first = $this->firstInvoice($order);
        $this->pay($first, 10.0, 'bank-first');
        $service = $order->services()->sole();
        $this->assertSame('active', $service->status);

        Carbon::setTestNow('2026-06-01 10:00:00'); // five months on: a renewal, paid and used
        $renewal = $this->renewalInvoice($service, '2026-02-01', '2026-03-01');
        $this->pay($renewal, 10.0, 'bank-renewal');
        $this->assertSame(Invoice::STATUS_PAID, $renewal->fresh()->status);

        CancelOrder::handle($order, refund: 'manual');

        $this->assertSame(10.0, $this->refundable($renewal->fresh()), 'a renewal paid and delivered months ago was refunded by «Cancel & Refund» of the order');
    }

    /**
     * ADV-2. A paid upgrade invoice of the order's service bills only this order, yet is neither refunded nor
     * reported to staff (the query looks only for Service lines): silently kept, while the UI promises «every
     * payment of an invoice that bills only this order goes back».
     */
    public function test_a_paid_upgrade_invoice_of_the_order_is_left_as_whmcs_does(): void
    {
        $order = $this->order(Product::AUTOSETUP_PAYMENT, server: false);
        $this->pay($this->firstInvoice($order), 10.0, 'bank-first');
        $service = $order->services()->sole();
        $plan = $this->product->plans()->first();
        $invoice = Invoice::query()->create(['client_id' => $service->client_id, 'due_at' => now(), 'currency_code' => 'USD', 'status' => Invoice::STATUS_UNPAID]);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'plan_id' => $plan->id, 'product_id' => $this->product->id,
            'status' => 'pending', 'invoice_id' => $invoice->id]);
        $invoice->items()->create(['reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class, 'price' => 3, 'quantity' => 1, 'description' => 'Upgrade']);
        $this->pay($invoice, 3.0, 'bank-upgrade');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $left = CancelOrder::handle($order, refund: 'manual');

        // Not fixed, by design (the author's decision, as WHMCS): Cancel & Refund gives back the order's own invoice
        // only; an upgrade paid and applied later stays (staff refund it from its invoice), as a renewal does (R2/ADV-1).
        $this->assertSame(3.0, $this->refundable($invoice->fresh()));
        $this->assertSame([], $left);
    }

    /**
     * ADV-3. An open invoice of the order paid in part by bank/gateway: CancelOpenInvoices leaves it open (it holds
     * money) BEFORE the refund pass runs, and the refund pass only looks at PAID invoices, so «Cancel & Refund» does
     * not refund that payment (it could: a refund of an open invoice is supported, then the invoice cancels).
     */
    public function test_cancel_and_refund_refunds_a_part_payment_of_the_orders_open_invoice(): void
    {
        $order = $this->order(Product::AUTOSETUP_PAYMENT, server: false);
        $invoice = $this->firstInvoice($order);
        $this->pay($invoice, 4.0, 'bank-part');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        CancelOrder::handle($order, refund: 'manual');

        $this->assertSame(0.0, $this->refundable($invoice->fresh()), 'the part payment on the order\'s own invoice was not refunded');
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    /**
     * ADV-4. Staff accepted an unpaid order (decision 4: set up, paid or not); the module create failed, so the
     * service is still pending. The cron's «cancel unpaid orders» ignores the order's status and cancels it a week
     * later: staff's Accept is undone and the order stays «active» with a cancelled service.
     */
    public function test_the_cron_does_not_cancel_an_order_staff_accepted(): void
    {
        config(['settings.cronjob_order_cancel' => 7]);
        $order = $this->order(Product::AUTOSETUP_ACCEPT);
        AcceptOrder::handle($order, null);
        FlakyServer::$failures = 5;
        try {
            (new CreateJob($order->services()->sole()))->handle();
        } catch (\Throwable) {
            // server unreachable: the job would retry / fail
        }
        $this->assertSame('pending', $order->services()->sole()->status);

        Carbon::setTestNow('2026-01-20 10:00:00');
        $this->artisan('app:cron-job');

        $this->assertSame('pending', $order->services()->sole()->status, 'the cron cancelled a service of an order staff accepted');
    }

    /**
     * ADV-5. The WHMCS importer (re-runnable, run at cut-over after the migrations) ignores tblorders.status: every
     * imported order lands «pending» (column default) — 6.850 Active / 196 Cancelled / 2 Fraud on myip all show up
     * in Pending Orders, and inserting services does not fire ServiceObserver::updated to fix it.
     */
    public function test_the_importer_keeps_whmcs_order_status(): void
    {
        $clientId = $this->user->client()->id;
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $whmcs->exec('CREATE TABLE tblorders (id INTEGER PRIMARY KEY, userid INT, notes TEXT, date TEXT, status TEXT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $whmcs->exec("INSERT INTO tblclients VALUES ({$clientId}, 1)");
        $whmcs->exec("INSERT INTO tblorders VALUES (9001, {$clientId}, '', '2024-01-02 10:00:00', 'Active')");
        $whmcs->exec("INSERT INTO tblorders VALUES (9002, {$clientId}, '', '2024-01-02 10:00:00', 'Fraud')");
        $whmcs->exec("INSERT INTO tblorders VALUES (9003, {$clientId}, '', '2024-01-02 10:00:00', 'Cancelled')");

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        (new ReflectionMethod($command, 'importOrders'))->invoke($command);

        $this->assertSame(['active', 'fraud', 'cancelled'], Order::query()->whereIn('id', [9001, 9002, 9003])->orderBy('id')->pluck('status')->all());
    }

    /**
     * ADV-6 (low). AcceptOrder has no guard on the order's state: an order whose services were all cancelled (fraud),
     * set back to pending (or called directly), turns «active» with nothing alive. Set back to Pending on such an
     * order also leaves it in Pending Orders for good (nothing will change a service's status to refresh it).
     */
    public function test_accept_does_not_make_a_fraud_order_with_only_ended_services_active(): void
    {
        $order = $this->order(Product::AUTOSETUP_PAYMENT);
        CancelOrder::handle($order, fraud: true);
        $order->refresh()->forceFill(['status' => Order::STATUS_PENDING])->save(); // «Set back to Pending»

        try {
            AcceptOrder::handle($order, null); // refused (fix): nothing is left to set up
        } catch (DisplayException) {
        }

        $this->assertNotSame(Order::STATUS_ACTIVE, $order->fresh()->status, 'accepted with every service cancelled');
    }

    /**
     * ADV-7 (low). The order follows its services only on ServiceObserver::updated: deleting the one pending service
     * of an order whose other service is active leaves the order «pending» (in Pending Orders) for good.
     */
    public function test_deleting_the_last_pending_service_leaves_the_order_for_staff_to_accept(): void
    {
        $this->product->update(['autosetup' => Product::AUTOSETUP_NEVER, 'server_id' => null, 'stock' => 10]);
        $product = $this->product->fresh();
        $plan = $product->plans()->first();
        $order = (new PlaceOrderService)->place($this->user->client(), 'USD',
            [new OrderLine($product, $plan, price: '10.00'), new OrderLine($product, $plan, price: '10.00')])->order;
        [$kept, $dropped] = $order->services()->orderBy('id')->get()->all();
        $kept->update(['status' => Service::STATUS_ACTIVE]);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);

        (new DeleteService)->handle($dropped);

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status, 'nothing waits, but staff have not seen it (owner 2026-10-19: every order waits for staff\'s Accept, as WHMCS)');
        AcceptOrder::handle($order->fresh(), null);
        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status, 'and Accept is not refused');
    }
}
