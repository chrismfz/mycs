<?php

namespace Tests\Feature;

use App\Admin\Resources\OrderResource;
use App\Admin\Resources\OrderResource\Pages\ListOrders;
use App\Admin\TopMenu;
use App\Console\Commands\ImportFromWhmcs;
use App\Mail\Mail as MyCSMail;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\AcceptOrder;
use App\Services\Order\CancelOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\Service\DeleteService;
use Filament\Facades\Filament;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/** Independent code review of Σ1-b2 (order status, Accept / Cancel / Cancel & Refund / Fraud, Pending Orders). */
class OrderStatusReviewTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-01-01 10:00:00');
        $this->product = $this->createProduct()->product;
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $autosetup, int $lines = 1): Order
    {
        $this->product->update(['autosetup' => $autosetup, 'server_id' => null]);
        $product = $this->product->fresh();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD',
            array_fill(0, $lines, new OrderLine($product, $product->plans()->first(), price: '10.00')));

        return $placed->order->fresh();
    }

    private function invoiceFor(Service $service, string $price = '10.00'): Invoice
    {
        $invoice = Invoice::query()->create(['client_id' => $this->user->client()->id, 'due_at' => now()->addDays(7),
            'currency_code' => 'USD', 'status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class,
            'price' => $price, 'quantity' => 1, 'description' => 'Renewal']);

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice, ?float $amount = null): void
    {
        (new RecordPaymentService)->handle($invoice, null, $amount ?? (float) $invoice->fresh()->total, transactionId: 'bank-' . $invoice->id . '-' . uniqid());
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner ' . uniqid(), 'permissions' => ['*']])->id]);
    }

    /**
     * R1. The importer inserts orders with DB::table() and no status: every WHMCS order lands as `pending` (the column's
     * default). The backfill only runs in the migration, i.e. before the cut-over import: ~7.000 orders in Pending
     * Orders, each with an «Accept Order» button. tblorders.status (Active / Pending / Cancelled / Fraud) is not mapped.
     */
    public function test_r1_the_importer_brings_the_whmcs_order_status(): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $whmcs->exec('CREATE TABLE tblorders (id INTEGER PRIMARY KEY, userid INT, date TEXT, notes TEXT, status TEXT, invoiceid INT)');
        $clientId = $this->user->client()->id;
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $whmcs->exec("INSERT INTO tblclients VALUES ({$clientId}, 1)");
        $whmcs->exec("INSERT INTO tblorders VALUES (9001, {$clientId}, '2020-01-02 10:00:00', '', 'Active', 0)");
        $whmcs->exec("INSERT INTO tblorders VALUES (9002, {$clientId}, '2020-01-02 10:00:00', '', 'Cancelled', 0)");
        $whmcs->exec("INSERT INTO tblorders VALUES (9003, {$clientId}, '2020-01-02 10:00:00', '', 'Fraud', 0)");

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        (new ReflectionMethod($command, 'importOrders'))->invoke($command);

        $this->assertSame([Order::STATUS_ACTIVE, Order::STATUS_CANCELLED, Order::STATUS_FRAUD],
            [Order::query()->find(9001)->status, Order::query()->find(9002)->status, Order::query()->find(9003)->status]);
        $this->assertNull(OrderResource::getNavigationBadge(), 'no imported order is waiting for staff');
    }

    /**
     * R2. Cancel & Refund refunds every paid invoice that bills only the order's services: on an order that has run
     * for a while, all its renewals too (periods already delivered). WHMCS refunds the order's own invoice.
     */
    public function test_r2_cancel_and_refund_does_not_refund_renewals_already_delivered(): void
    {
        $order = $this->order(Product::AUTOSETUP_PAYMENT);
        $service = $order->services()->sole();
        $first = Invoice::query()->sole();
        $this->pay($first);
        $this->assertSame('active', $service->fresh()->status);

        Carbon::setTestNow('2026-06-01 10:00:00');
        $renewal = $this->invoiceFor($service->fresh());
        $this->pay($renewal);
        $this->assertSame(Invoice::STATUS_PAID, $renewal->fresh()->status);

        CancelOrder::handle($order, refund: 'manual');

        $this->assertGreaterThan(0, (float) $renewal->transactions()->sole()->fresh()->refundable_amount,
            'a renewal paid months later is not the order\'s payment: it must not be refunded by Cancel & Refund');
    }

    /**
     * R3. Cancel & Refund refunds only PAID invoices. A first invoice paid in part by a gateway is still open: it is
     * neither refunded nor cancelled (CancelOpenInvoices leaves it, as it holds gateway money), although the action
     * promises «every payment of an invoice that bills only this order goes back».
     */
    public function test_r3_cancel_and_refund_refunds_a_part_payment_of_the_order_invoice(): void
    {
        $order = $this->order(Product::AUTOSETUP_PAYMENT);
        $invoice = Invoice::query()->sole();
        $this->pay($invoice, 5.0);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        CancelOrder::handle($order, refund: 'manual');

        $this->assertSame(0.0, (float) $invoice->transactions()->sole()->fresh()->refundable_amount, 'the part payment goes back');
    }

    /**
     * R4. The order follows its services only on a status change (ServiceObserver::updated). Deleting the one service
     * that kept it pending (never paid, removed by staff) leaves it in Pending Orders for ever.
     */
    public function test_r4_deleting_the_service_that_kept_the_order_pending_refreshes_it(): void
    {
        $order = $this->order(Product::AUTOSETUP_NEVER, lines: 2);
        [$kept, $dropped] = $order->services()->orderBy('id')->get()->all();
        $kept->update(['status' => Service::STATUS_ACTIVE]);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        Invoice::query()->get()->each(fn (Invoice $invoice) => $invoice->items()->where('reference_id', $dropped->id)->delete());

        (new DeleteService)->handle($dropped->fresh());

        $this->assertNull(Service::query()->find($dropped->id));
        $this->assertSame(Order::STATUS_ACTIVE, $order->fresh()->status, 'nothing waits any more');
    }

    /**
     * R5. «Send welcome email» is ignored when «Run module create» is unticked (built by hand): the welcome mail is
     * gated on $runCreate in AcceptOrder. WHMCS's two checkboxes are independent; the modal shows it ticked.
     */
    public function test_r5_accept_sends_the_welcome_mail_when_asked_also_for_a_service_built_by_hand(): void
    {
        config(['settings.mail_disable' => false]); // mail is off until SMTP is set (the setting's default)
        $order = $this->order(Product::AUTOSETUP_NEVER);

        AcceptOrder::handle($order, null, runCreate: false, sendEmail: true);

        $this->assertSame('active', $order->services()->sole()->status);
        $this->assertSame(1, Mail::queued(MyCSMail::class, fn (MyCSMail $mail) => $mail->templateKey === 'new_server_created')->count());
    }

    /** R6. TopMenu compares request()->url(), which has no query string: «Pending Orders» is never the active item. */
    public function test_r6_pending_orders_is_the_active_menu_item_on_its_tab(): void
    {
        $this->actingAs($this->staff(), 'staff');
        $url = OrderResource::getUrl('index', ['tab' => 'pending']);
        $this->app->instance('request', Request::create($url));

        $items = collect(collect(TopMenu::groups())->firstWhere('label', 'Orders')['items'])->keyBy('label');

        $this->assertTrue($items['Pending Orders']['active']);
        $this->assertFalse($items['List All Orders']['active']);
    }

    /**
     * R7. Filament 5's list keeps its tab in `?tab=` (ListRecords: #[Url(as: 'tab')] $activeTab), not `?activeTab=`:
     * the «Pending Orders» menu item and the dashboard counter open the «All» tab.
     */
    public function test_r7_the_pending_orders_link_opens_the_pending_tab(): void
    {
        $staff = $this->staff();
        $waiting = $this->order(Product::AUTOSETUP_NEVER);
        $done = $this->order(Product::AUTOSETUP_NEVER);
        AcceptOrder::handle($done, $staff, runCreate: false, sendEmail: false);

        parse_str((string) parse_url(OrderResource::getUrl('index', ['tab' => 'pending']), PHP_URL_QUERY), $query);

        $this->livewireAs($staff)->withQueryParams($query)->test(ListOrders::class)
            ->assertSet('activeTab', 'pending')
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$done]);
    }
}
