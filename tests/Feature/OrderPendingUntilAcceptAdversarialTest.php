<?php

namespace Tests\Feature;

use App\Admin\Resources\OrderResource\Pages\ListOrders;
use App\Enums\DomainStatus;
use App\Jobs\Server\CreateJob;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\AcceptOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use Tests\TestCase;

/**
 * Adversarial review of «every order stays pending until staff accept it» (923e32d). Each test fails on that commit and
 * describes what it should do instead.
 */
class OrderPendingUntilAcceptAdversarialTest extends TestCase
{
    use RefreshDatabase;

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
        $this->user = User::factory()->create();
        config(['settings.cronjob_order_cancel' => 7]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $autosetup, bool $server = true): Product
    {
        $product = $this->createProduct()->product;
        $product->update(['autosetup' => $autosetup, 'server_id' => $server ? $this->server->id : null, 'stock' => null]);

        return $product->fresh();
    }

    /** @param  list<Product>  $products */
    private function order(array $products): Order
    {
        $lines = array_map(fn (Product $p) => new OrderLine($p, $p->plans()->first(), price: '10.00'), $products);

        return (new PlaceOrderService)->place($this->user->client(), 'USD', $lines)->order->fresh();
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);
    }

    /**
     * ADV-1 (high). started_unpaid_at is per order, the decision per service: Accept starts a product set up on Accept
     * (unpaid) and leaves another service of the same order waiting for its payment. The flag then keeps the cron off the
     * waiting one for ever: never started, never cancelled, its line due on the invoice (reminders, a debt for something
     * never delivered). Before 923e32d Accept started it too.
     */
    public function test_adv1_a_service_left_waiting_beside_one_accept_started_unpaid_is_still_cancelled_unpaid(): void
    {
        $onAccept = $this->product(Product::AUTOSETUP_ACCEPT, server: false);
        $onPayment = $this->product(Product::AUTOSETUP_PAYMENT);
        $order = $this->order([$onAccept, $onPayment]);
        $waiting = Service::query()->where('order_id', $order->id)->where('product_id', $onPayment->id)->sole();

        AcceptOrder::handle($order, null);

        $this->assertSame(Service::STATUS_PENDING, $waiting->fresh()->status, 'not paid: left waiting by Accept');
        $this->assertNotNull($order->fresh()->started_unpaid_at, 'the other one was started unpaid');

        Carbon::setTestNow('2026-02-01 10:00:00'); // Order Days Grace long gone, never paid
        $this->artisan('app:cron-job');

        $this->assertSame(Service::STATUS_CANCELLED, $waiting->fresh()->status,
            'neither started by Accept nor paid: the cron must still cancel it (only what Accept started unpaid is exempt)');
        $this->assertFalse(InvoiceItem::query()->where('invoice_id', $order->invoice_id)->where('reference_type', Service::class)
            ->where('reference_id', $waiting->id)->exists(), 'its line off the invoice: the customer does not owe it');
    }

    /**
     * ADV-2 (medium). A product set up «as soon as an order is placed» whose create failed (or never ran) is not waiting
     * for its payment: it was promised before payment. waitsForPayment() uses setsUpOnPayment(), which is true for
     * AUTOSETUP_ORDER too, so Accept (and bulk Accept) silently skips it and the cron later cancels it.
     */
    public function test_adv2_accept_retries_the_create_of_a_product_set_up_on_order(): void
    {
        $order = $this->order([$this->product(Product::AUTOSETUP_ORDER)]);
        $service = $order->services()->sole();
        FlakyServer::$failures = 99;
        try {
            (new CreateJob($service))->handle(); // the create on order fails
        } catch (\Throwable) {
        }
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status);
        Queue::fake();

        AcceptOrder::handle($order, null);

        Queue::assertPushed(CreateJob::class, 'set up on order, its create failed: staff\'s Accept sets it up, as WHMCS');
    }

    /**
     * ADV-3 (low). unpaidDomainOrders() lost its accepted_at guard and has no started_unpaid_at one: an order staff
     * accepted with «Also start the services not paid yet» (or accepted before the migration, backfilled) has its
     * domain cancelled by the cron and its line taken off the invoice the started service is still billed on.
     */
    public function test_adv3_the_domain_of_an_order_staff_started_unpaid_is_left_as_its_services(): void
    {
        $order = $this->order([$this->product(Product::AUTOSETUP_PAYMENT)]);
        $domain = Domain::factory()->create(['client_id' => $this->user->client()->id, 'order_id' => $order->id,
            'status' => DomainStatus::PendingRegister, 'registered_at' => null, 'expires_at' => null]);
        Invoice::query()->findOrFail($order->invoice_id)->items()->create(['description' => 'Register', 'price' => '12.00', 'quantity' => 1,
            'reference_type' => Domain::class, 'reference_id' => $domain->id, 'kind' => InvoiceItem::KIND_REGISTER]);

        AcceptOrder::handle($order, null, startUnpaid: true);
        $this->assertNotNull($order->fresh()->started_unpaid_at);

        Carbon::setTestNow('2026-02-01 10:00:00');
        $this->artisan('app:cron-job');

        $this->assertSame(DomainStatus::PendingRegister, $domain->fresh()->status,
            'staff started the order unpaid: the cron leaves it all, as it left every accepted order before');
    }

    /**
     * ADV-4 (low). Staff give an order for free (its invoice cancelled) and accept it: nothing open, so Accept does not
     * set started_unpaid_at; its create keeps failing (ADV-4 of #82). Before 923e32d every accepted order was exempt;
     * now the cron cancels the service staff gave, as «never paid».
     */
    public function test_adv4_a_service_staff_accepted_for_free_whose_create_fails_is_not_cancelled(): void
    {
        $order = $this->order([$this->product(Product::AUTOSETUP_PAYMENT)]);
        DB::table('invoices')->where('id', $order->invoice_id)->update(['status' => Invoice::STATUS_CANCELLED]);

        AcceptOrder::handle($order, null);
        Queue::assertPushed(CreateJob::class);
        FlakyServer::$failures = 99;
        try {
            (new CreateJob($order->services()->sole()))->handle();
        } catch (\Throwable) {
        }

        Carbon::setTestNow('2026-02-01 10:00:00');
        $this->artisan('app:cron-job');

        $this->assertSame(Service::STATUS_PENDING, $order->services()->sole()->status, 'staff started it: not cancelled as unpaid');
    }

    /**
     * ADV-5 (medium). Paid and set-up orders now sit in Pending Orders until Accept, next to the odd ones. Bulk Cancel
     * (no terminate, no refund, no per-order choice) on a paid order with a running service cancels the service in
     * billing (no renewals, no suspension: its account, if any, runs free) and keeps the customer's money. It should
     * refuse an order with a payment or a running service, and send staff to its page.
     */
    public function test_adv5_bulk_cancel_refuses_a_paid_order_with_a_running_service(): void
    {
        $order = $this->order([$this->product(Product::AUTOSETUP_PAYMENT, server: false)]);
        $invoice = Invoice::query()->findOrFail($order->invoice_id);
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->total, transactionId: 'bank-' . $invoice->id);
        $this->assertSame(Service::STATUS_ACTIVE, $order->services()->sole()->status, 'paid: set up');
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status, 'in Pending Orders');

        $this->livewireAs($this->staff())->test(ListOrders::class)->callTableBulkAction('cancelOrders', [$order]);

        $this->assertSame(Service::STATUS_ACTIVE, $order->services()->sole()->status, 'paid and running: not cancelled in bulk');
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }
}
