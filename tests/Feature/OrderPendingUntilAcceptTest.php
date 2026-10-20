<?php

namespace Tests\Feature;

use App\Admin\Resources\OrderResource\Pages\EditOrder;
use App\Admin\Resources\OrderResource\Pages\ListOrders;
use App\Classes\Settings;
use App\Jobs\Server\CreateJob;
use App\Models\Invoice;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use Tests\TestCase;

/**
 * Every order waits in Pending Orders for staff's Accept, as WHMCS (owner 2026-10-19): set up or not, paid or not.
 * Accept is staff's look at what came in: it sets up what waits for staff, and leaves a service waiting for its
 * payment as it is (it starts when paid, or the cron cancels it) unless staff start it. Bulk Accept / Cancel from the
 * list, and its Payment Status, as WHMCS's Pending Orders.
 */
class OrderPendingUntilAcceptTest extends TestCase
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
        config(['settings.cronjob_order_cancel' => 7]);
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

        return (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')])->order->fresh();
    }

    private function pay(Order $order): void
    {
        $invoice = Invoice::query()->findOrFail($order->invoice_id);
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->total, transactionId: 'bank-' . $invoice->id);
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);
    }

    public function test_a_paid_order_set_up_on_payment_waits_for_staff_and_accept_changes_nothing_else(): void
    {
        $order = $this->order(Product::AUTOSETUP_PAYMENT);
        $this->pay($order);
        (new CreateJob($order->services()->sole()))->handle();
        $service = $order->services()->sole();
        $this->assertSame(Service::STATUS_ACTIVE, $service->status, 'set up on payment, as before');
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status, 'in Pending Orders until staff look at it');
        Queue::fake(); // what the payment queued

        AcceptOrder::handle($order, null);

        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        Queue::assertNotPushed(CreateJob::class);
    }

    public function test_accept_leaves_a_service_waiting_for_its_payment_and_the_cron_still_cancels_it(): void
    {
        $order = $this->order(Product::AUTOSETUP_PAYMENT);

        AcceptOrder::handle($order, null);

        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status, 'looked at');
        $this->assertSame(Service::STATUS_PENDING, $order->services()->sole()->status, 'not started: not paid');
        Queue::assertNotPushed(CreateJob::class);
        $this->assertNull($order->services()->sole()->started_unpaid_at);

        Carbon::setTestNow('2026-01-20 10:00:00'); // Order Days Grace passed, never paid
        $this->artisan('app:cron-job');

        $this->assertSame(Service::STATUS_CANCELLED, $order->services()->sole()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, Invoice::query()->findOrFail($order->invoice_id)->status);
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status, 'nothing left: cancelled, as WHMCS');
    }

    public function test_by_default_the_cron_cancels_no_order_only_staff_do(): void
    {
        // As WHMCS (OrderDaysGrace is the first invoice's term, not a cancellation): owner 2026-10-19.
        $default = collect(Settings::settings())->flatten(1)->firstWhere('name', 'cronjob_order_cancel')['default'] ?? null;
        $this->assertSame(0, $default);
        config(['settings.cronjob_order_cancel' => $default]);
        $order = $this->order(Product::AUTOSETUP_PAYMENT);

        Carbon::setTestNow('2026-03-01 10:00:00'); // two months, never paid
        $this->artisan('app:cron-job');

        $this->assertSame(Service::STATUS_PENDING, $order->services()->sole()->status);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status, 'left in Pending Orders for staff');
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail($order->invoice_id)->status);
    }

    public function test_paid_later_it_is_set_up_as_usual(): void
    {
        $order = $this->order(Product::AUTOSETUP_PAYMENT);
        AcceptOrder::handle($order, null);

        $this->pay($order);

        Queue::assertPushed(CreateJob::class);
    }

    public function test_staff_start_an_unpaid_one_and_the_cron_leaves_the_order_alone(): void
    {
        $order = $this->order(Product::AUTOSETUP_PAYMENT);

        AcceptOrder::handle($order, null, startUnpaid: true);

        Queue::assertPushed(CreateJob::class);
        $this->assertNotNull($order->services()->sole()->started_unpaid_at);
        FlakyServer::$failures = 99; // its create keeps failing (ADV-4)
        try {
            (new CreateJob($order->services()->sole()))->handle();
        } catch (\Throwable) {
        }
        $this->assertSame(Service::STATUS_PENDING, $order->services()->sole()->status);

        Carbon::setTestNow('2026-01-20 10:00:00');
        $this->artisan('app:cron-job');

        $this->assertSame(Service::STATUS_PENDING, $order->services()->sole()->status, 'staff started it: not cancelled as unpaid');
    }

    public function test_a_product_set_up_on_accept_is_still_set_up_by_accept_unpaid(): void
    {
        $order = $this->order(Product::AUTOSETUP_ACCEPT);

        AcceptOrder::handle($order, null);

        Queue::assertPushed(CreateJob::class);
        $this->assertNotNull($order->services()->sole()->started_unpaid_at, 'started not paid: the cron leaves it');
    }

    public function test_accept_tries_again_a_failed_create_of_a_product_set_up_on_order(): void
    {
        // Set up on the order, its create failed: it waits for staff, not for a payment (code review, 2026-10-19).
        $order = $this->order(Product::AUTOSETUP_ORDER);
        $this->assertSame(Service::STATUS_PENDING, $order->services()->sole()->status);
        Queue::fake();

        AcceptOrder::handle($order, null);

        Queue::assertPushed(CreateJob::class);
    }

    public function test_an_order_accepted_before_keeps_the_cron_away(): void
    {
        // Accepted under the old Accept (it started everything): the migration gives its services accepted_at.
        $order = $this->order(Product::AUTOSETUP_PAYMENT);
        $other = $this->order(Product::AUTOSETUP_PAYMENT);
        $order->forceFill(['status' => Order::STATUS_ACTIVE, 'accepted_at' => '2026-01-02 09:00:00'])->save();
        $migration = require database_path('migrations/2026_12_04_000000_services_started_unpaid_at.php');
        $migration->down();
        $migration->up();
        $this->assertSame('2026-01-02 09:00:00', $order->services()->sole()->started_unpaid_at?->toDateTimeString());
        $this->assertNull($other->services()->sole()->started_unpaid_at, 'not accepted: none');

        Carbon::setTestNow('2026-01-20 10:00:00');
        $this->artisan('app:cron-job');

        $this->assertSame(Service::STATUS_PENDING, $order->services()->sole()->status);
    }

    public function test_the_accept_modal_offers_to_start_the_unpaid_ones(): void
    {
        $order = $this->order(Product::AUTOSETUP_PAYMENT);

        $this->livewireAs($this->staff())->test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('accept', data: ['run_create' => true, 'send_email' => false, 'start_unpaid' => true])
            ->assertNotified('Order accepted');

        Queue::assertPushed(CreateJob::class);
    }

    public function test_bulk_accept_and_cancel_from_pending_orders(): void
    {
        $paid = $this->order(Product::AUTOSETUP_NEVER, server: false);
        $this->pay($paid);
        $unpaid = $this->order(Product::AUTOSETUP_PAYMENT);
        $odd = $this->order(Product::AUTOSETUP_PAYMENT);
        $staff = $this->staff();

        $this->livewireAs($staff)->test(ListOrders::class, ['activeTab' => Order::STATUS_PENDING])
            ->assertSee('Payment Status')->assertSee('Paid')->assertSee('Unpaid')
            ->callTableBulkAction('acceptOrders', [$paid, $unpaid])
            ->assertNotified('2 order(s) accepted');
        $this->assertSame([Order::STATUS_ACTIVE, Order::STATUS_ACTIVE], [$paid->fresh()->status, $unpaid->fresh()->status]);
        $this->assertSame(Service::STATUS_PENDING, $unpaid->services()->sole()->status, 'unpaid: still waiting');

        $this->livewireAs($staff)->test(ListOrders::class)->callTableBulkAction('cancelOrders', [$odd]);
        $this->assertSame(Order::STATUS_CANCELLED, $odd->fresh()->status);
        $this->assertSame(Service::STATUS_CANCELLED, $odd->services()->sole()->status);
    }

    public function test_bulk_actions_need_the_permission(): void
    {
        $order = $this->order(Product::AUTOSETUP_PAYMENT);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $viewer = Staff::factory()->create(['role_id' => Role::create(['name' => 'Viewer', 'permissions' => ['admin.orders.viewAny']])->id]);

        $this->livewireAs($viewer)->test(ListOrders::class)->assertTableBulkActionHidden('acceptOrders')->assertTableBulkActionHidden('cancelOrders');
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }
}
