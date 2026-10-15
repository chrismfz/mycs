<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PR D (decision 3, sweep Π1.4): a pro rata product's services renew on the same day of the month, as nixpal's WHMCS
 * (336 of 350 live services, the 1st, «charge next month» after the 20th). The first invoice is for the days up to it;
 * ordered on or after the «charge next month» day, it bills the next full month too.
 */
class ProductProrataTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.cronjob_invoice' => 7]);
        $this->user = User::factory()->create();
        $this->product = $this->createProduct(['prorata_day' => 1, 'prorata_next_after_day' => 20])->product;
        Price::query()->where('plan_id', $this->product->plans()->first()->id)->update(['price' => 30.00]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $on, ?Product $product = null, ?Coupon $coupon = null): Invoice
    {
        Carbon::setTestNow($on . ' 10:00:00');
        $product ??= $this->product;
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->firstOrFail())], $coupon);

        return $placed->invoice->fresh('items');
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    public function test_the_first_invoice_is_for_the_days_to_the_1st(): void
    {
        $line = $this->order('2026-01-10')->items->sole();

        $this->assertSame('21.29', $this->money($line->price)); // 22 of 31 days of 30.00
        $this->assertSame(['2026-01-10', '2026-02-01'], [$line->period_start->toDateString(), $line->period_end->toDateString()]);
        $this->assertStringContainsString('Feb 01, 2026', $line->description);
    }

    public function test_ordered_after_the_20th_the_next_month_is_billed_too(): void
    {
        $line = $this->order('2026-06-25')->items->sole();

        $this->assertSame('36.00', $this->money($line->price)); // 6 of 30 days, and July
        $this->assertSame('2026-08-01', $line->period_end->toDateString());
    }

    public function test_ordered_on_the_1st_a_full_month(): void
    {
        $line = $this->order('2026-03-01')->items->sole();

        $this->assertSame('30.00', $this->money($line->price));
        $this->assertSame('2026-04-01', $line->period_end->toDateString());
    }

    public function test_paid_it_renews_on_the_1st_and_the_cron_bills_whole_months_from_there(): void
    {
        $invoice = $this->order('2026-01-10');
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->fresh()->total, transactionId: 'wire-p');
        $service = Service::query()->sole();
        $this->assertSame('2026-02-01', $service->fresh()->expires_at->toDateString());

        Carbon::setTestNow('2026-01-26 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        $renewal = $service->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)->sole()->items->sole();
        $this->assertSame(['2026-02-01', '2026-03-01', '30.00'], [$renewal->period_start->toDateString(), $renewal->period_end->toDateString(), $this->money($renewal->price)]);
    }

    public function test_services_ordered_on_different_days_renew_together(): void
    {
        foreach (['2026-01-05', '2026-01-17'] as $day) {
            $invoice = $this->order($day);
            (new RecordPaymentService)->handle($invoice, null, (float) $invoice->fresh()->total, transactionId: 'wire-' . $day);
        }

        Carbon::setTestNow('2026-01-26 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        $renewals = Invoice::query()->where('status', Invoice::STATUS_UNPAID)->with('items')->get();
        $this->assertCount(1, $renewals, 'one grouped renewal invoice on the 1st (PR C1)');
        $this->assertCount(2, $renewals->sole()->items);
    }

    public function test_the_setup_fee_is_whole_and_a_coupon_is_taken_before_the_days(): void
    {
        Price::query()->where('plan_id', $this->product->plans()->first()->id)->update(['setup_fee' => 5.00]);
        $coupon = Coupon::query()->create(['code' => 'HALF', 'type' => 'percentage', 'value' => 50, 'recurring' => 0]);

        $line = $this->order('2026-04-19', coupon: $coupon)->items->sole();

        $this->assertSame('8.50', $this->money($line->price)); // (30.00 − 50%) × 12/30 = 6.00, and the setup fee 5.00 − 50%
    }

    public function test_a_product_without_pro_rata_starts_when_paid(): void
    {
        $plain = $this->createProduct()->product;

        $line = $this->order('2026-01-10', $plain)->items->sole();

        $this->assertNull($line->period_start);
        $this->assertSame('10.00', $this->money($line->price));
    }

    public function test_the_cart_shows_what_the_invoice_charges(): void
    {
        Carbon::setTestNow('2026-01-10 10:00:00');
        $cart = Cart::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD']);
        $item = CartItem::query()->create(['cart_id' => $cart->id, 'product_id' => $this->product->id, 'plan_id' => $this->product->plans()->first()->id, 'quantity' => 1]);

        $this->assertSame(21.29, $item->fresh()->lineTotal());
    }

    private function pay(Invoice $invoice, string $on): void
    {
        Carbon::setTestNow($on . ' 10:00:00');
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->fresh()->total, transactionId: 'wire-' . $invoice->id);
    }

    public function test_set_up_by_staff_after_a_late_payment_it_keeps_the_1st(): void
    {
        // Code review of PR D: a service started later than ordered is not moved off the product's day.
        $this->product->update(['autosetup' => Product::AUTOSETUP_ACCEPT]);
        $this->pay($this->order('2026-01-10'), '2026-01-25');
        $service = Service::query()->sole();
        $this->assertSame(Service::STATUS_PENDING, $service->fresh()->status, 'precondition: it waits for staff');

        $service->fresh()->applyStaffChanges(['status' => 'active']);

        $this->assertSame('2026-02-01', $service->fresh()->expires_at->toDateString());
    }

    public function test_a_free_first_period_ordered_on_the_10th_ends_on_the_1st(): void
    {
        Carbon::setTestNow('2026-01-10 10:00:00');
        $coupon = Coupon::query()->create(['code' => 'FREE', 'type' => 'percentage', 'value' => 100, 'recurring' => 0]);

        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD',
            [new OrderLine($this->product, $this->product->plans()->firstOrFail())], $coupon);

        $this->assertNull($placed->invoice);
        $this->assertSame('2026-02-01', Service::query()->sole()->expires_at->toDateString());
    }

    public function test_day_28_ordered_on_the_30th_stays_on_the_28th(): void
    {
        $this->product->update(['prorata_day' => 28, 'prorata_next_after_day' => null]);
        $this->pay($this->order('2027-01-30'), '2027-01-30');
        $service = Service::query()->sole();
        $this->assertSame('2027-02-28', $service->fresh()->expires_at->toDateString());

        Carbon::setTestNow('2027-02-25 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        $renewal = $service->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)->sole()->items->sole();
        $this->assertSame(['2027-02-28', '2027-03-28'], [$renewal->period_start->toDateString(), $renewal->period_end->toDateString()]);
    }

    public function test_a_yearly_plan_is_aligned_and_priced_on_the_year(): void
    {
        $plan = $this->product->plans()->first();
        $plan->update(['billing_unit' => 'year']);
        Price::query()->where('plan_id', $plan->id)->update(['price' => 365.00]);

        $line = $this->order('2026-01-10')->items->sole();

        $this->assertSame(['22.00', '2026-02-01'], [$this->money($line->price), $line->period_end->toDateString()]);
    }

    public function test_a_staff_price_is_the_renewal_price_and_the_first_invoice_is_its_days(): void
    {
        Carbon::setTestNow('2026-01-10 10:00:00');
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD',
            [new OrderLine($this->product, $this->product->plans()->firstOrFail(), price: '62.00')]);

        $this->assertSame('44.00', $this->money($placed->invoice->items->sole()->price)); // 62.00 × 22/31
        $this->assertSame('62.00', $this->money(Service::query()->sole()->price));
    }

    public function test_paid_after_the_1st_the_cron_bills_the_next_month_at_once(): void
    {
        $this->pay($this->order('2026-01-10'), '2026-02-05');
        $service = Service::query()->sole();
        $this->assertSame('2026-02-01', $service->fresh()->expires_at->toDateString(), 'as WHMCS: the first period was to the 1st');

        $this->artisan('app:cron-job')->assertExitCode(0);

        $renewal = $service->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)->sole()->items->sole();
        $this->assertSame(['2026-02-01', '2026-03-01'], [$renewal->period_start->toDateString(), $renewal->period_end->toDateString()]);
    }
}
