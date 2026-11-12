<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * What MyCS bills for a «Price Override» coupon that recurs (#274, ROADMAP «Promotions: test ανανέωσης για Price
 * Override»), through the real order (PlaceOrderService) and the cron's renewal billing. In WHMCS the same coupon on a
 * pro rata product took a full month's difference off a part month (line 1.97, promo −2.44: a total below zero). In
 * MyCS an override is the line's own price (OrderPricing::line), and a pro rata first period prorates that price
 * (OrderPricing::firstPaymentNet): no separate discount line, nothing below zero. Made-up products and amounts.
 */
class PriceOverrideProrataTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        // Ordered on the 15th of a 31-day month: a pro rata product (day 1) bills 17 days of 31 first.
        Carbon::setTestNow('2026-01-15 10:00:00');
        config(['settings.default_currency' => 'USD']);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A monthly product at 10.00 with an override to 5.00 on every cycle. */
    private function product(?int $prorataDay): array
    {
        $product = $this->createProduct()->product;
        $product->update(['autosetup' => Product::AUTOSETUP_PAYMENT, 'server_id' => null, 'prorata_day' => $prorataDay, 'prorata_next_after_day' => null]);
        $product->plans()->firstOrFail()->prices()->update(['price' => '10.00', 'setup_fee' => '0.00', 'currency_code' => 'USD']);
        $coupon = Coupon::query()->create(['code' => 'OVERRIDE5', 'type' => 'override', 'applies_to' => 'all', 'value' => '5.00', 'recurring' => 0]);
        $coupon->products()->attach($product->id);

        return [$product->fresh(), $coupon->fresh()];
    }

    /** @return array{0: Invoice, 1: Service} the order's invoice and its service */
    private function order(Product $product, Coupon $coupon): array
    {
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD',
            [new OrderLine($product, $product->plans()->firstOrFail()->load('prices'), 1, [])], $coupon);

        return [$placed->invoice->fresh(['items']), $placed->order->fresh()->services()->sole()];
    }

    /** The cron the day before the service ends: its renewal invoice. */
    private function renewal(Service $service, Invoice $first): Invoice
    {
        Carbon::setTestNow($service->fresh()->expires_at->copy()->subDay()->setTime(10, 0));
        $this->artisan('app:cron-job');

        return $service->fresh()->invoices()->whereKeyNot($first->id)->with('items')->sole();
    }

    private function pay(Invoice $invoice): void
    {
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->fresh()->total, transactionId: 'wire-' . $invoice->id);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** @return list<string> each line's total */
    private static function lines(Invoice $invoice): array
    {
        return $invoice->items->map(fn (InvoiceItem $item) => number_format((float) $item->line_total, 2, '.', ''))->values()->all();
    }

    public function test_a_pro_rata_first_order_prorates_the_override_price_and_never_goes_below_zero(): void
    {
        [$product, $coupon] = $this->product(1);
        [$invoice, $service] = $this->order($product, $coupon);

        // One line: the override price (5.00) for 17 of 31 days, prorated as the line would be (10.00 → 5.48).
        $this->assertSame(['2.74'], self::lines($invoice), 'the first invoice\'s lines');
        $this->assertSame(2.74, $invoice->total);
        $this->assertGreaterThanOrEqual(0, $invoice->total);
        $this->assertSame(['2026-01-15', '2026-02-01'], [$invoice->items->sole()->period_start?->toDateString(), $invoice->items->sole()->period_end?->toDateString()]);
        $this->assertSame($coupon->id, $service->coupon_id);
    }

    public function test_a_pro_rata_service_renews_at_the_override_price(): void
    {
        [$product, $coupon] = $this->product(1);
        [$invoice, $service] = $this->order($product, $coupon);
        $this->pay($invoice);
        $this->assertSame('2026-02-01', $service->fresh()->expires_at->toDateString());

        $renewal = $this->renewal($service, $invoice);

        $this->assertSame(['5.00'], self::lines($renewal), 'the first full renewal\'s lines');
        $this->assertSame('2026-02-01', $renewal->items->sole()->period_start?->toDateString());
        $this->assertSame('2026-03-01', $renewal->items->sole()->period_end?->toDateString());
    }

    public function test_without_pro_rata_the_first_period_and_the_renewal_are_at_the_override_price(): void
    {
        [$product, $coupon] = $this->product(null);
        [$invoice, $service] = $this->order($product, $coupon);
        $this->assertSame(['5.00'], self::lines($invoice), 'the first invoice\'s lines');
        $this->pay($invoice);

        $renewal = $this->renewal($service, $invoice);

        $this->assertSame(['5.00'], self::lines($renewal), 'the first renewal\'s lines');
    }
}
