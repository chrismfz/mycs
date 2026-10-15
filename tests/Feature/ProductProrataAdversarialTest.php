<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Adversarial review of PR D (decision 3): product pro rata billing. `test_bug_*` fail on the branch and show a defect;
 * `test_holds_*` pass and pin behaviour that an attack did not break.
 */
class ProductProrataAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(int $day = 1, ?int $nextAfter = 20, float $price = 30.00, string $unit = 'month', int $count = 1, float $setup = 0.0): Product
    {
        $product = $this->createProduct(['prorata_day' => $day, 'prorata_next_after_day' => $nextAfter])->product;
        $plan = $product->plans()->firstOrFail();
        $plan->update(['billing_unit' => $unit, 'billing_period' => $count]);
        Price::query()->where('plan_id', $plan->id)->update(['price' => $price, 'setup_fee' => $setup]);

        return $product->fresh();
    }

    private function place(string $at, Product $product, ?Coupon $coupon = null, int $quantity = 1, ?string $staffPrice = null): ?Invoice
    {
        Carbon::setTestNow($at);
        $plan = $product->plans()->firstOrFail();
        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $plan, $quantity, price: $staffPrice)], $coupon);

        return $placed->invoice?->fresh('items');
    }

    private function pay(Invoice $invoice, string $ref): void
    {
        (new RecordPaymentService)->handle($invoice->fresh(), null, (float) $invoice->fresh()->total, transactionId: $ref);
    }

    private function cron(string $at): void
    {
        Carbon::setTestNow($at);
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /** @return list<string> [start, end, price] of the service's open renewal line */
    private function openRenewal(Service $service): array
    {
        $line = $service->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)->sole()->items->sole();

        return [$line->period_start->toDateString(), $line->period_end->toDateString(), self::money($line->price)];
    }

    // ---------------------------------------------------------------- defects

    /**
     * D-ADV-1. Pro rata day 28 (the importer clamps WHMCS 29–31 to 28): ordered on Jan 31, the first period ends on Feb 28,
     * but the payment remembers billing day 31 (Jan 31 is not ambiguous) and Feb 28 *is* ambiguous, so the first renewal
     * runs Feb 28 → Mar 31 and the service leaves the product's day for good (and the grouping on the 28th).
     */
    public function test_bug_day_28_ordered_on_the_31st_renews_to_the_31st_not_the_28th(): void
    {
        $product = $this->product(28, null);
        $invoice = $this->place('2026-01-31 10:00:00', $product);
        $this->assertSame('2026-02-28', $invoice->items->sole()->period_end->toDateString());
        $this->pay($invoice, 'wire-31');

        $this->cron('2026-02-21 10:00:00');

        $service = Service::query()->sole();
        $this->assertSame(['2026-02-28', '2026-03-28', '30.00'], $this->openRenewal($service),
            'the renewal after the pro rata period must run to the product day (28th), not to the order day');
    }

    /**
     * D-ADV-2. A first invoice of 0 (100% first-cycle coupon; also a staff price of 0, or a stub that rounds to 0.00) skips
     * the invoice and starts the service through RenewServiceService without a line: a whole month from the order day,
     * Jan 10 → Feb 10. The service is never aligned to the product day, and renews on the 10th forever.
     */
    public function test_bug_a_free_first_period_is_not_aligned_to_the_product_day(): void
    {
        $product = $this->product(1, 20);
        $coupon = Coupon::query()->create(['code' => 'FREE1', 'type' => 'percentage', 'value' => 100, 'recurring' => 1]);

        $invoice = $this->place('2026-01-10 10:00:00', $product, $coupon);

        $this->assertNull($invoice);
        $this->assertSame('2026-02-01', Service::query()->sole()->expires_at?->toDateString(),
            'a pro rata service must expire on the product day even when its first period is free');
    }

    /**
     * D-ADV-3. An upgrade during the first (partial) period: ServiceUpgrade::calculatePrice prices the monthly difference
     * over the line's own days (Jan 10 → Feb 1 = 22 of 22), so the customer pays the whole month's difference (30.00) for
     * 22 days of a 31-day month (21.29). A downgrade credits the same way: too much back.
     */
    public function test_bug_upgrade_in_the_partial_first_period_charges_a_whole_months_difference(): void
    {
        $product = $this->product(1, 20);
        $this->pay($this->place('2026-01-10 10:00:00', $product), 'wire-up');
        $bigger = $this->product(1, 20, 60.00);
        $service = Service::query()->sole();

        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'product_id' => $bigger->id,
            'plan_id' => $bigger->plans()->firstOrFail()->id, 'status' => ServiceUpgrade::STATUS_PENDING]);

        $this->assertSame('21.29', self::money($upgrade->calculatePrice()->price), '30.00 more a month, for 22 of 31 days');
    }

    /** D-ADV-3b. The same, the other way: the extended first period (Jun 25 → Aug 1, 6/30 + July) is under-charged. */
    public function test_bug_upgrade_in_the_extended_first_period_undercharges(): void
    {
        $product = $this->product(1, 20);
        $this->pay($this->place('2026-06-25 10:00:00', $product), 'wire-up2');
        $bigger = $this->product(1, 20, 60.00);
        $service = Service::query()->sole();

        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'product_id' => $bigger->id,
            'plan_id' => $bigger->plans()->firstOrFail()->id, 'status' => ServiceUpgrade::STATUS_PENDING]);

        $this->assertSame('36.00', self::money($upgrade->calculatePrice()->price), '30.00 × 6/30 + a full July, as the order billed');
    }

    /**
     * D-ADV-4. Paid late (after the product day): the service starts already expired (documented) and the same cron run
     * that bills February also suspends it, because «Suspend Days» counts from Feb 1, before it was ever provisioned. The
     * customer pays 21.29 for days that passed while it was pending and is suspended on the day it is set up. Reachable when
     * the order is not auto-cancelled first (cronjob_order_cancel 0 or longer, a staff-made invoice with a later due date,
     * staff recording the payment), so no cron runs between the order and the payment here.
     */
    public function test_bug_paid_after_the_product_day_it_is_suspended_the_day_it_is_set_up(): void
    {
        $product = $this->product(1, 20);
        $invoice = $this->place('2026-01-10 10:00:00', $product);

        Carbon::setTestNow('2026-02-15 09:00:00');
        $this->pay($invoice, 'wire-late');
        $service = Service::query()->sole();
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);

        $this->cron('2026-02-15 10:00:00');

        $this->assertNotSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'set up today, suspended today');
    }

    // ---------------------------------------------------------------- regressions that held

    public function test_holds_the_second_renewal_stays_on_the_1st(): void
    {
        $product = $this->product(1, 20);
        $this->pay($this->place('2026-01-10 10:00:00', $product), 'wire-a');
        $service = Service::query()->sole();

        $this->cron('2026-01-26 10:00:00');
        $this->assertSame(['2026-02-01', '2026-03-01', '30.00'], $this->openRenewal($service));
        $this->pay($service->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)->sole(), 'wire-b');

        $this->cron('2026-02-23 10:00:00');
        $this->assertSame(['2026-03-01', '2026-04-01', '30.00'], $this->openRenewal($service));
    }

    public function test_holds_no_renewal_while_the_order_invoice_is_unpaid_and_none_twice_after(): void
    {
        $product = $this->product(1, 20);
        $invoice = $this->place('2026-01-19 10:00:00', $product);
        $this->assertSame('12.58', self::money($invoice->items->sole()->price)); // 13 of 31 days

        $this->cron('2026-01-25 10:00:00');
        $this->assertSame(1, Invoice::query()->count(), 'no renewal for a pending service');

        Carbon::setTestNow('2026-01-25 11:00:00');
        $this->pay($invoice, 'wire-c');
        $this->cron('2026-01-25 12:00:00');
        $this->cron('2026-01-26 10:00:00');

        $service = Service::query()->sole();
        $this->assertSame('2026-02-01', $service->fresh()->expires_at->toDateString());
        $this->assertSame(['2026-02-01', '2026-03-01', '30.00'], $this->openRenewal($service));
    }

    public function test_holds_quarterly_is_priced_on_the_quarter_and_renews_from_the_1st(): void
    {
        $product = $this->product(1, 20, 30.00, 'month', 3);
        $invoice = $this->place('2026-01-10 10:00:00', $product);
        $line = $invoice->items->sole();
        $this->assertSame(['2026-02-01', '7.33'], [$line->period_end->toDateString(), self::money($line->price)]); // 22 of 90 days
        $this->pay($invoice, 'wire-q');

        $this->cron('2026-01-26 10:00:00');
        $this->assertSame(['2026-02-01', '2026-05-01', '30.00'], $this->openRenewal(Service::query()->sole()));
    }

    public function test_holds_yearly_ordered_on_the_next_after_day_bills_the_days_and_a_year(): void
    {
        $product = $this->product(1, 20, 365.00, 'year', 1);
        $line = $this->place('2026-01-20 10:00:00', $product)->items->sole();

        $this->assertSame(['2026-01-20', '2027-02-01', '377.00'],
            [$line->period_start->toDateString(), $line->period_end->toDateString(), self::money($line->price)]);
    }

    public function test_holds_leap_day_order(): void
    {
        $product = $this->product(1, 20);
        $line = $this->place('2028-02-29 23:59:00', $product)->items->sole();

        // 1 of 29 days (Feb 29 → Mar 29) and, from the 20th on, April too.
        $this->assertSame(['2028-02-29', '2028-04-01', '31.03'],
            [$line->period_start->toDateString(), $line->period_end->toDateString(), self::money($line->price)]);
    }

    public function test_holds_staff_price_is_prorated_and_kept_for_renewals(): void
    {
        $product = $this->product(1, 20);
        $invoice = $this->place('2026-01-10 10:00:00', $product, staffPrice: '50.00');
        $this->assertSame('35.48', self::money($invoice->items->sole()->price)); // 50 × 22/31
        $this->pay($invoice, 'wire-s');

        $this->cron('2026-01-26 10:00:00');
        $this->assertSame(['2026-02-01', '2026-03-01', '50.00'], $this->openRenewal(Service::query()->sole()));
    }

    public function test_holds_cart_matches_the_invoice_with_quantity_setup_fee_and_tax(): void
    {
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
        $this->user->client()->primaryContact->update(['country' => 'GR']);
        $product = $this->product(1, 20, 30.00, 'month', 1, 5.00);

        Carbon::setTestNow('2026-01-10 10:00:00');
        $this->actingAs($this->user);
        $cart = Cart::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD']);
        $item = CartItem::query()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'plan_id' => $product->plans()->first()->id, 'quantity' => 3]);
        $cartTotal = $item->fresh()->lineTotal();

        $invoice = $this->place('2026-01-10 10:00:00', $product, quantity: 3);

        $this->assertSame(self::money($cartTotal), self::money($invoice->fresh()->total));
        $this->assertSame('97.80', self::money($invoice->fresh()->total)); // (21.29 + 5.00) × 3 = 78.87, + 24% = 18.93
    }

    public function test_holds_existing_services_are_untouched_when_the_product_turns_pro_rata(): void
    {
        $plain = $this->createProduct()->product;
        $invoice = $this->place('2026-01-10 10:00:00', $plain);
        $this->pay($invoice, 'wire-e');
        $plain->update(['prorata_day' => 1, 'prorata_next_after_day' => 20]);

        $this->cron('2026-02-04 10:00:00');
        $this->assertSame(['2026-02-10', '2026-03-10', '10.00'], $this->openRenewal(Service::query()->sole()));
    }

    public function test_holds_importer_maps_the_edge_values(): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, gid INT, name TEXT, description TEXT, slug TEXT, hidden INT, tax INT,
            proratabilling INT, proratadate INT, proratachargenextmonth INT, autosetup TEXT, stockcontrol INT, qty INT, allowqty INT,
            paytype TEXT, created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblproduct_upgrade_products (product_id INT, upgrade_product_id INT)');
        $whmcs->exec('CREATE TABLE tblproductconfiglinks (pid INT, gid INT)');
        $category = Category::factory()->create();
        // [id, proratabilling, proratadate, chargenextmonth] => [prorata_day, prorata_next_after_day]
        $cases = [
            9001 => [[1, 1, 20], [1, 20]],
            9002 => [[1, 0, 20], [null, 20]],
            9003 => [[1, 29, 0], [28, null]],
            9004 => [[1, 31, 31], [28, 31]],
            9005 => [[1, 5, 50], [5, null]],
            9006 => [[0, 1, 20], [null, null]],
            9007 => [[1, 1, 32], [1, null]],
        ];
        foreach ($cases as $id => [[$on, $date, $next]]) {
            $whmcs->exec("INSERT INTO tblproducts VALUES ({$id}, {$category->id}, 'P{$id}', '', 'p{$id}', 0, 1, {$on}, {$date}, {$next}, 'payment', 0, 0, 0, 'free', '2024-01-02 10:00:00', '2024-01-02 10:00:00')");
        }

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        (new ReflectionMethod($command, 'importProducts'))->invoke($command);

        foreach ($cases as $id => [, $expected]) {
            $product = Product::query()->findOrFail($id);
            $this->assertSame($expected, [$product->prorata_day, $product->prorata_next_after_day], "product {$id}");
            // A free plan never prorates, whatever the day.
            $this->assertFalse($product->proratesPlan(Plan::query()->where('priceable_id', $id)->first()));
        }
    }
}
