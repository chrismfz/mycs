<?php

namespace Tests\Feature;

use App\Admin\Resources\OrderResource;
use App\Admin\Resources\OrderResource\Pages\EditOrder;
use App\Admin\TopMenu;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\TerminateJob;
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
use App\Services\Order\CancelOrder;
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
 * Σ1-b2: an order's status (Δ4) and staff's order buttons, as WHMCS: Accept, Cancel, Cancel & Refund, Set as Fraud,
 * Set back to Pending; Pending Orders.
 */
class OrderStatusTest extends TestCase
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

    private function order(string $autosetup, bool $server = true, int $lines = 1): Order
    {
        $this->product->update(['autosetup' => $autosetup, 'server_id' => $server ? $this->server->id : null, 'stock' => 10]);
        $product = $this->product->fresh();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD',
            array_fill(0, $lines, new OrderLine($product, $product->plans()->first(), price: '10.00')));

        return $placed->order->fresh();
    }

    private function payInvoice(Order $order): Invoice
    {
        $invoice = Invoice::query()->whereHas('items', fn ($items) => $items->whereIn('reference_id', $order->services()->pluck('id'))
            ->where('reference_type', Service::class))->firstOrFail();
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->total, transactionId: 'bank-' . $invoice->id);

        return $invoice->fresh();
    }

    private function staff(array $permissions = ['*']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    public function test_an_order_starts_pending_and_turns_active_once_its_services_are_set_up(): void
    {
        $order = $this->order(Product::AUTOSETUP_PAYMENT);
        $this->assertSame(Order::STATUS_PENDING, $order->status);

        $this->payInvoice($order);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status, 'paid, the account not made yet');
        (new CreateJob($order->services()->sole()))->handle();

        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status);
    }

    public function test_a_paid_order_of_a_product_staff_set_up_stays_pending_until_accepted(): void
    {
        $order = $this->order(Product::AUTOSETUP_NEVER, server: false);
        $this->payInvoice($order);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);

        Carbon::setTestNow('2026-01-11 10:00:00'); // the dedicated server is ready
        $staff = $this->staff();
        AcceptOrder::handle($order, $staff, runCreate: false, sendEmail: false);

        $order->refresh();
        $service = $order->services()->sole();
        $this->assertSame([Order::STATUS_ACTIVE, $staff->id], [$order->status, $order->accepted_by_staff_id]);
        $this->assertSame('active', $service->status);
        $this->assertSame('2026-02-11', $service->expires_at->toDateString(), 'the month paid counts from the day it started');
    }

    public function test_accept_runs_the_module_create_even_unpaid(): void
    {
        $order = $this->order(Product::AUTOSETUP_ACCEPT);

        AcceptOrder::handle($order, null);

        Queue::assertPushed(CreateJob::class, fn (CreateJob $job) => $job->sendNotification === true);
        (new CreateJob($order->services()->sole()))->handle();
        $service = $order->services()->sole();
        $this->assertSame(['active', '2026-01-01'], [$service->status, $service->expires_at->toDateString()], 'unpaid: due from the order date');
    }

    public function test_an_unpaid_order_the_cron_cancels_is_cancelled(): void
    {
        config(['settings.cronjob_order_cancel' => 7]);
        $order = $this->order(Product::AUTOSETUP_PAYMENT);

        Carbon::setTestNow('2026-01-20 10:00:00');
        $this->artisan('app:cron-job');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_cancel_order_ends_its_services_and_their_open_invoices_and_terminates_only_when_asked(): void
    {
        $order = $this->order(Product::AUTOSETUP_ORDER, lines: 2);
        foreach ($order->services as $service) {
            (new CreateJob($service))->handle();
        }
        $this->assertSame(8, $this->product->fresh()->stock);

        $left = CancelOrder::handle($order, terminate: false);

        $this->assertSame([], $left);
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(['cancelled', 'cancelled'], $order->services()->pluck('status')->all());
        $this->assertSame(Invoice::STATUS_CANCELLED, Invoice::query()->sole()->status);
        $this->assertSame(10, $this->product->fresh()->stock, 'stock back');
        Queue::assertNotPushed(TerminateJob::class);

        $other = $this->order(Product::AUTOSETUP_ORDER);
        (new CreateJob($other->services()->sole()))->handle();
        CancelOrder::handle($other, terminate: true, fraud: true);
        $this->assertSame(Order::STATUS_FRAUD, $other->fresh()->status);
        Queue::assertPushed(TerminateJob::class);
    }

    public function test_cancel_and_refund_gives_back_the_payments_of_an_invoice_that_bills_only_this_order(): void
    {
        $order = $this->order(Product::AUTOSETUP_PAYMENT);
        $invoice = $this->payInvoice($order);

        $left = CancelOrder::handle($order, refund: 'manual');

        $this->assertSame([], $left);
        $payment = $invoice->transactions()->sole();
        $this->assertSame(0.0, (float) $payment->fresh()->refundable_amount, 'refunded in full');
        $this->assertSame('cancelled', $order->services()->sole()->status);
    }

    public function test_an_invoice_that_also_bills_something_else_is_left_to_staff(): void
    {
        $order = $this->order(Product::AUTOSETUP_PAYMENT);
        $invoice = Invoice::query()->sole();
        $elsewhere = Service::factory()->create(['client_id' => $this->user->client()->id, 'product_id' => $this->product->id,
            'plan_id' => $this->product->plans()->first()->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 5, 'quantity' => 1]);
        $invoice->items()->create(['reference_id' => $elsewhere->id, 'reference_type' => Service::class, 'description' => 'Another', 'price' => 5, 'quantity' => 1]);
        $this->payInvoice($order);

        $left = CancelOrder::handle($order, refund: 'manual');

        $this->assertArrayHasKey("refund #{$invoice->id}", $left);
        $this->assertGreaterThan(0, (float) $invoice->transactions()->sole()->refundable_amount, 'not refunded');
    }

    public function test_staff_use_the_order_buttons(): void
    {
        $staff = $this->staff();
        $order = $this->order(Product::AUTOSETUP_NEVER, server: false);

        $this->livewireAs($staff)->test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('accept', data: ['run_create' => false, 'send_email' => false])
            ->assertNotified('Order accepted');
        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status);

        $this->livewireAs($staff)->test(EditOrder::class, ['record' => $order->getRouteKey()])->callAction('pending');
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);

        $this->livewireAs($staff)->test(EditOrder::class, ['record' => $order->getRouteKey()])->callAction('cancel', data: ['terminate' => false]);
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    public function test_cancel_and_refund_needs_the_permission_to_refund(): void
    {
        $staff = $this->staff(['admin.orders.viewAny', 'admin.orders.update']);
        $order = $this->order(Product::AUTOSETUP_PAYMENT);
        $this->payInvoice($order);

        $this->livewireAs($staff)->test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('cancelRefund', data: ['terminate' => false, 'method' => 'manual'])
            ->assertNotified('Refunds need the permission to change transactions');

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status, 'nothing done');
    }

    public function test_pending_orders_in_the_menu_and_the_existing_orders_get_their_status(): void
    {
        $this->actingAs($this->staff(), 'staff');
        $items = collect(TopMenu::groups())->firstWhere('label', 'Orders')['items'];
        $this->assertContains(OrderResource::getUrl('index', ['activeTab' => 'pending']), array_column($items, 'url'));

        $active = $this->order(Product::AUTOSETUP_ORDER, server: false);
        $ended = $this->order(Product::AUTOSETUP_PAYMENT);
        $ended->services()->update(['status' => 'cancelled']);
        $waiting = $this->order(Product::AUTOSETUP_PAYMENT);
        DB::table('orders')->update(['status' => 'pending']);

        (require database_path('migrations/2026_11_05_000001_add_status_to_orders.php'))->backfill();

        $this->assertSame([Order::STATUS_ACTIVE, Order::STATUS_CANCELLED, Order::STATUS_PENDING],
            [$active->fresh()->status, $ended->fresh()->status, $waiting->fresh()->status]);
    }
}
