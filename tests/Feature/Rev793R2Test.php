<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Exceptions\CouponUnusableException;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Coupon;
use App\Models\Credit;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\CancelOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Adversarial review of #793, second pass (the fixes of REV-793-1..7, head 0a6fa025). Failing tests for what was
 * found; nothing here fixes app code. Invented data only, no network.
 */
class Rev793R2Test extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Cache::flush();
        Carbon::setTestNow('2026-09-20 10:00:00');
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function credit(): string
    {
        return number_format((float) (Credit::query()->where('client_id', $this->user->client()->id)->sum('amount')), 2, '.', '');
    }

    /**
     * REV-793-R2-1 (claim 2, a false refusal on imported data, introduced by the fix). movedParts() takes the
     * lowest-id line of each service of the order for «the order's first billing» and refuses when it is on an invoice
     * other than `orders.invoice_id`. The importer copies `tblorders.invoiceid` as it is (ImportFromWhmcs::importOrders),
     * also when WHMCS deleted that invoice since (it keeps the order and the id; the importer says so itself where it
     * sets the invoice counter: «the invoice ids lines, payments and orders still name»). The service's lowest line is
     * then a RENEWAL, on a paid invoice.
     *
     * WHMCS order #77 named invoice #470 (deleted in WHMCS, not imported). Its service #31 came with two paid renewals,
     * #480 and #501, 10.00 each. «Cancel & Refund» of the order: refused «invoice #470 of this order was split, and #480
     * bills a part of it … refund each invoice from its page». Nothing was split, #470 does not exist, and #480 is a
     * renewal, which by the action's own rule stays. On main (and before the fix) the order is cancelled, with nothing
     * of its own to refund.
     *
     * Should: not refused; the service is cancelled, the renewals keep their money (as «renewals paid later stay»).
     */
    public function test_rev_793_r2_1_an_imported_order_whose_invoice_whmcs_deleted_is_not_refused_as_split(): void
    {
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $client = $this->user->client();
        $this->whmcs($client->id, $product->product->id);
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-08-10', date: '2026-08-01');
        $this->whmcsInvoice(501, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->importWhmcs();
        $this->imported(480);
        // As importOrders() writes it: the order row with WHMCS's invoiceid, whether or not that invoice came.
        DB::table('orders')->insert(['id' => 77, 'client_id' => $client->id, 'currency_code' => 'EUR', 'status' => Order::STATUS_ACTIVE,
            'invoice_id' => 470, 'created_at' => '2024-01-01 10:00:00', 'updated_at' => '2024-01-01 10:00:00']);
        DB::table('services')->where('id', 31)->update(['order_id' => 77]);
        $this->assertNull(Invoice::query()->find(470), 'precondition: the order\'s own invoice was deleted in WHMCS');

        try {
            CancelOrder::handle(Order::query()->findOrFail(77), refund: 'manual');
        } catch (DisplayException $e) {
            $this->fail('an imported order that was never split is refused, for a renewal: ' . $e->getMessage());
        }

        $this->assertSame(Service::STATUS_CANCELLED, Service::query()->findOrFail(31)->status);
        $this->assertSame([Invoice::STATUS_PAID, Invoice::STATUS_PAID], [Invoice::query()->findOrFail(480)->status, Invoice::query()->findOrFail(501)->status], 'its renewals stay');
    }

    private function product(): Product
    {
        $product = $this->createProduct()->product;
        $product->update(['autosetup' => Product::AUTOSETUP_PAYMENT, 'server_id' => null]);

        return $product->fresh();
    }

    private function place(Product $product, Coupon $coupon)
    {
        return (new PlaceOrderService)->place($this->user->client(), 'USD',
            [new OrderLine($product, $product->plans()->firstOrFail()->load('prices'), 1, [])], $coupon->fresh());
    }

    private function refusal(Product $product, Coupon $coupon): ?string
    {
        try {
            $this->place($product, $coupon);
        } catch (CouponUnusableException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * REV-793-R2-2 (claim 4, the fix of REV-793-5 burns a use without a delivered order). A use is now «cancelled, and
     * an invoice of it has a succeeded payment». A payment that arrives AFTER the order lapsed (a bank transfer, a slow
     * gateway) is recorded on the cancelled invoice and all of it becomes account credit: nothing is delivered, the
     * service stays cancelled, the invoice stays cancelled. That row is «a succeeded payment on an invoice of it».
     *
     * Once-per-client 50% coupon; the order lapses unpaid and is cancelled (0 uses: S8-CLIENT-PORTAL-3 as fixed). The
     * customer's payment of the 50% price then lands: it is their account credit. They order again, to pay with that
     * credit: «You have already used this promotion.» Before this fix round (status read) the coupon was theirs again.
     *
     * Should: an order never delivered, whose late money sits in the customer's credit, is not a use.
     */
    public function test_rev_793_r2_2_a_late_payment_on_the_cancelled_invoice_of_a_lapsed_order_does_not_use_the_coupon_up(): void
    {
        $this->markTestIncomplete('REV-793-R2-2 accepted (2026-11-11): the cost of «ever paid = a use»; rare, no money lost, staff give the discount by hand, and the opposite rule let a charged-back order free the coupon.');

        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $product = $this->product();
        $coupon = Coupon::query()->create(['code' => 'R2LATE', 'type' => 'percentage', 'value' => 50, 'once_per_client' => true, 'max_uses_per_user' => 1]);
        $placed = $this->place($product, $coupon);
        $invoice = Invoice::query()->findOrFail($placed->order->invoice_id);
        $total = (float) $invoice->total;
        CancelOrder::handle($placed->order->fresh());
        $this->assertSame([Invoice::STATUS_CANCELLED, 0], [$invoice->fresh()->status, $coupon->uses()->count()], 'precondition: lapsed unpaid, not a use');

        ExtensionHelper::addPayment($invoice->id, 'Stripe', $total, transactionId: 'pi_r2_late', currency: 'usd');

        $this->assertSame([Invoice::STATUS_CANCELLED, Service::STATUS_CANCELLED, number_format($total, 2, '.', '')],
            [$invoice->fresh()->status, Service::query()->where('order_id', $placed->order->id)->sole()->status, $this->credit()],
            'precondition: nothing delivered, the late payment is the customer\'s credit');
        $this->assertNull($this->refusal($product, $coupon), 'the lapsed order became a use when its late payment was turned into credit');
    }

    /**
     * REV-793-R2-3 (claim 4, same rule). An order paid IN PART, never delivered, cancelled with «Cancel & Refund» (the
     * part goes back): its invoice has a succeeded payment (refunded in full), so it is a use for ever. The PR's own
     * rule is «cancelled, billed and never paid: nobody got the discount».
     *
     * 50% coupon on a 10.00 plan (5.00 due); 2.00 paid by bank; staff cancel and refund the 2.00. The same customer
     * orders again with the coupon: refused. Before this fix round it was accepted.
     *
     * Should: not a use (never paid in full, never delivered, the money back).
     */
    public function test_rev_793_r2_3_a_part_payment_refunded_with_the_cancelled_order_does_not_use_the_coupon_up(): void
    {
        $this->markTestIncomplete('REV-793-R2-3 accepted (2026-11-11): the cost of «ever paid = a use»; rare, no money lost, staff give the discount by hand, and the opposite rule let a charged-back order free the coupon.');

        $product = $this->product();
        $coupon = Coupon::query()->create(['code' => 'R2PART', 'type' => 'percentage', 'value' => 50, 'once_per_client' => true, 'max_uses_per_user' => 1]);
        $placed = $this->place($product, $coupon);
        $invoice = Invoice::query()->findOrFail($placed->order->invoice_id);
        (new RecordPaymentService)->handle($invoice, null, 2.00, transactionId: 'bank-part');
        $this->assertSame([Invoice::STATUS_UNPAID, Service::STATUS_PENDING], [$invoice->fresh()->status, Service::query()->where('order_id', $placed->order->id)->sole()->status],
            'precondition: paid in part, not delivered');

        CancelOrder::handle($placed->order->fresh(), refund: 'manual');

        $this->assertSame(0, RecordPaymentService::toCents($invoice->transactions()->sole()->refundable_amount), 'precondition: the 2.00 went back');
        $this->assertSame(Service::STATUS_CANCELLED, Service::query()->where('order_id', $placed->order->id)->sole()->status);
        $this->assertNull($this->refusal($product, $coupon), 'an order never paid in full nor delivered, its part payment refunded, used the coupon up');
    }

    /**
     * REV-793-R2-4 (claim 1, low; the fix of REV-793-6 reads «money went back» as «this click refunded something»).
     * The order's payment was refunded BEFORE the click: in the gateway's dashboard (its webhook recorded it) or from
     * the invoice's page. «Cancel & Refund» then finds nothing left to refund, counts 0 and tells nothing: the order's
     * money is back with the customer, the domain stays registered and Active, no notice and no task. That is
     * S8-DOMAINS-1 in two steps. (Before this fix round the task was opened.)
     *
     * Order of one service, 10.00 paid and refunded in full by hand; a domain of the order is Active.
     * Should: staff are told of the registered domain when the order's own invoice was refunded, whenever that happened.
     */
    public function test_rev_793_r2_4_a_registered_domain_is_told_when_the_orders_money_went_back_before_the_click(): void
    {
        $product = $this->product();
        $order = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')])->order->fresh();
        $invoice = Invoice::query()->findOrFail($order->invoice_id);
        $domain = Domain::factory()->create(['client_id' => $this->user->client()->id, 'order_id' => $order->id, 'sld' => 'rev793r2', 'tld' => 'test',
            'fqdn' => 'rev793r2.test', 'status' => DomainStatus::Active]);
        (new RecordPaymentService)->handle($invoice, null, 10.00, transactionId: 'bank-1');
        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 10.00, notify: false);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status, 'precondition: refunded in full before the click');

        $left = CancelOrder::handle($order->fresh(), refund: 'manual');

        $told = isset($left["domain #{$domain->id}"])
            || ModuleAction::query()->where('domain_id', $domain->id)->where('action', 'domain_refunded')->exists();
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
        $this->assertTrue($told, 'the order is cancelled, its 10.00 refunded, rev793r2.test stays registered and Active, and staff were told nothing');
        $this->assertSame(0, InvoiceItem::query()->where('reference_type', Domain::class)->count()); // the fixture bills no domain line: nothing else to tell
    }
}
