<?php

namespace Tests\Feature;

use App\Exceptions\CouponUnusableException;
use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of Σ7a (PlaceOrderService / OrderPricing). Each test asserts the correct behaviour: a failing test
 * is a defect.
 */
class PlaceOrderAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private function product(float $price = 10.00, float $setupFee = 0, array $attributes = [], string $type = 'recurring'): Product
    {
        $product = Product::factory()->create($attributes + ['name' => 'Hosting']);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => $type === 'free' ? null : 'month', 'billing_period' => $type === 'free' ? null : 1, 'type' => $type]);
        if ($type !== 'free') {
            Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => $setupFee, 'currency_code' => 'USD']);
        }

        return $product->fresh();
    }

    private function line(Product $product, int $quantity = 1, ?string $price = null, array $options = []): OrderLine
    {
        return new OrderLine($product->fresh(), $product->plans()->with('prices')->firstOrFail(), $quantity, $options, price: $price);
    }

    private function client(string $country = 'GR'): Client
    {
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['country' => $country]);

        return $client->fresh();
    }

    private function coupon(array $attributes = []): Coupon
    {
        return Coupon::create($attributes + ['code' => 'SAVE', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50]);
    }

    /** A select option on the product with one child priced 5.00/month (USD). */
    private function pricedOption(Product $product, string $type = 'select'): array
    {
        $option = ConfigOption::create(['name' => 'Backups', 'type' => $type, 'sort' => 0]);
        $option->products()->attach($product->id);
        $child = ConfigOption::create(['name' => 'Daily', 'sort' => 0, 'parent_id' => $option->id]);
        $plan = Plan::factory()->create(['priceable_id' => $child->id, 'priceable_type' => ConfigOption::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 5.00, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return [$option, $child];
    }

    private function invoiceTotal(?Invoice $invoice): string
    {
        if ($invoice === null) {
            return '0.00';
        }

        return number_format((float) $invoice->items()->get()->sum(fn ($item) => (float) $item->line_total), 2, '.', '');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // ADV-1 (high): a single-use coupon discounts every unit of a quantity line.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * max_uses = 1 and a fixed 10.00 coupon ("one amount per line", docs/orders.md). One line of 5 units of a 20.00
     * product: the invoice line is (20 − 10) × 5 = 50.00, i.e. 50.00 off for one «use». Correct: either refused (5
     * units > 1 use) or at most one coupon amount off (≥ 90.00).
     */
    public function test_a_single_use_fixed_coupon_does_not_discount_every_unit_of_a_line(): void
    {
        $coupon = $this->coupon(['type' => 'fixed', 'value' => 10, 'max_uses' => 1, 'recurring' => 0]);

        try {
            $placed = (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($this->product(20.00), 5)], $coupon);
        } catch (CouponUnusableException) {
            $this->assertSame(0, Order::query()->count());

            return;
        }

        $this->assertGreaterThanOrEqual(90.00, (float) $this->invoiceTotal($placed->invoice),
            'a single-use 10.00 coupon took ' . (100 - (float) $this->invoiceTotal($placed->invoice)) . ' off');
    }

    /**
     * ADV-2 (high, same root): max_uses_per_user = 1 with a 100% coupon. One line with quantity 10 gives 10 free units
     * and starts them at once (nothing due → RenewServiceService). Correct: refused, or only one unit free.
     */
    public function test_a_once_per_account_free_coupon_cannot_be_multiplied_by_quantity(): void
    {
        $coupon = $this->coupon(['value' => 100, 'max_uses_per_user' => 1, 'recurring' => 1]);

        try {
            $placed = (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($this->product(20.00), 10)], $coupon);
        } catch (CouponUnusableException) {
            $this->assertSame(0, Service::query()->count());

            return;
        }

        $this->assertGreaterThanOrEqual(180.00, (float) $this->invoiceTotal($placed->invoice),
            'one per-account use gave ' . $placed->order->services()->sum('quantity') . ' units for ' . $this->invoiceTotal($placed->invoice));
    }

    // ---------------------------------------------------------------------------------------------------------------
    // ADV-3 (medium): the per-account limit mixes units (new line) and services (existing ones).
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * per_user_limit = 3, product allows a quantity. Order 3 units (one service, quantity 3), then 2 more: the check is
     * existing SERVICES (1) + new UNITS (2) = 3 ≤ 3, so the account ends with 5 units. Correct: the second is refused.
     */
    public function test_the_per_account_limit_counts_the_units_of_existing_services(): void
    {
        $product = $this->product(attributes: ['per_user_limit' => 3, 'allow_quantity' => 'combined']);
        $client = $this->client();
        (new PlaceOrderService)->place($client, 'USD', [$this->line($product, 3)]);

        try {
            (new PlaceOrderService)->place($client, 'USD', [$this->line($product, 2)]);
        } catch (DisplayException) {
        }

        $this->assertLessThanOrEqual(3, (int) $client->services()->where('product_id', $product->id)->sum('quantity'));
    }

    // ---------------------------------------------------------------------------------------------------------------
    // ADV-4 (medium): a first-cycle coupon becomes a lifetime discount after a recalculation before the first payment.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Checkout stores the full 20.00 as the renewal price for a first-cycle coupon. calculatePrice() ("the price it
     * renews at … what a recalculation stores") on the pending service counts paid invoices (0) + 1 = cycle 1 and
     * returns 10.00. Staff's «recalculate» (ServiceResource) stores it, and the cron never restores it for
     * cycles() === 1 (CronJob::billRenewal only recalculates when cycles() > 1) → 10.00 forever.
     */
    public function test_recalculating_a_pending_service_keeps_the_full_renewal_price_of_a_first_cycle_coupon(): void
    {
        $coupon = $this->coupon(['recurring' => null]);
        $placed = (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($this->product(20.00))], $coupon);
        $service = $placed->order->services()->sole();
        $this->assertSame('20.00', number_format((float) $service->price, 2, '.', ''));

        $this->assertSame('20.00', $service->fresh()->calculatePrice(), 'recalculation of a pending service with a first-cycle coupon');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // ADV-5 (low): a free plan gives priced options away.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * OrderPricing::catalog returns 0.00 for a free plan before looking at options, yet createService still gives the
     * service the chosen priced option (5.00). Correct: the option is charged (or the order refused), never given free.
     */
    public function test_a_free_plan_does_not_give_a_priced_option_away(): void
    {
        $product = $this->product(type: 'free');
        [$option, $child] = $this->pricedOption($product);

        try {
            $placed = (new PlaceOrderService)->place($this->client(), 'USD',
                [$this->line($product, options: [['option_id' => $option->id, 'value' => $child->id]])]);
        } catch (DisplayException) {
            $this->assertSame(0, Service::query()->count());

            return;
        }

        $service = $placed->order->services()->sole();
        $hasOption = $service->configs()->where('config_value_id', $child->id)->exists();
        $this->assertFalse($hasOption && $placed->invoice === null, 'priced option given with no invoice');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // ADV-6 / ADV-7 (low, defence in depth): the service trusts its OrderLine.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * A negative staff price (the form has minValue(0), the service does not check): firstPayment −10 → no invoice,
     * service started at once with price −10, and the cron renews «price <= 0» for free forever.
     */
    public function test_a_negative_staff_price_is_refused(): void
    {
        try {
            (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($this->product(20.00), price: '-10.00')]);
        } catch (Throwable) {
            $this->assertSame(0, Service::query()->count());

            return;
        }

        $this->fail('a negative recurring price was accepted: service price ' . Service::query()->sole()->price . ', status ' . Service::query()->sole()->status);
    }

    /** A line whose plan belongs to another (cheaper) product is priced from that plan. Correct: refused. */
    public function test_a_plan_of_another_product_is_refused(): void
    {
        $expensive = $this->product(100.00, attributes: ['name' => 'Dedicated']);
        $cheap = $this->product(1.00, attributes: ['name' => 'Tiny']);
        $line = new OrderLine($expensive, $cheap->plans()->with('prices')->firstOrFail());

        try {
            $placed = (new PlaceOrderService)->place($this->client(), 'USD', [$line]);
        } catch (DisplayException) {
            $this->assertSame(0, Service::query()->count());

            return;
        }

        $this->assertGreaterThanOrEqual(100.00, (float) $this->invoiceTotal($placed->invoice), 'product ordered at another product\'s price');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Regression guards (pass today).
    // ---------------------------------------------------------------------------------------------------------------

    public function test_a_coupon_that_has_not_started_is_refused(): void
    {
        $coupon = $this->coupon(['starts_at' => now()->addDay()]);

        $this->expectException(CouponUnusableException::class);
        (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($this->product())], $coupon);
    }

    public function test_quantity_below_one_is_refused(): void
    {
        $this->expectException(DisplayException::class);
        (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($this->product(), 0)]);
    }

    /** A used-up coupon that covers none of the lines does not block the order and is not recorded. */
    public function test_a_used_up_coupon_for_other_products_does_not_block_or_count(): void
    {
        $covered = $this->product(attributes: ['name' => 'Covered']);
        $other = $this->product(attributes: ['name' => 'Other']);
        $coupon = $this->coupon(['max_uses' => 1]);
        $coupon->products()->attach($covered->id);
        (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($covered)], $coupon);

        $placed = (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($other)], $coupon->fresh());

        $this->assertNull($placed->order->services()->sole()->coupon_id);
        $this->assertSame(1, $coupon->services()->count());
    }

    /** A select value that is a child of another option is neither charged nor given to the service. */
    public function test_a_foreign_select_value_is_neither_priced_nor_stored(): void
    {
        $product = $this->product(20.00);
        [$option] = $this->pricedOption($product);
        $foreign = ConfigOption::create(['name' => 'Elsewhere', 'type' => 'select', 'sort' => 1]);
        $foreignChild = ConfigOption::create(['name' => 'Gold', 'sort' => 0, 'parent_id' => $foreign->id]);

        $placed = (new PlaceOrderService)->place($this->client(), 'USD',
            [$this->line($product, options: [['option_id' => $option->id, 'value' => $foreignChild->id]])]);

        $this->assertSame('20.00', $this->invoiceTotal($placed->invoice));
        $this->assertSame(0, $placed->order->services()->sole()->configs()->count());
    }

    /** A chosen priced option is charged on the first invoice and kept in the renewal price. */
    public function test_a_chosen_option_is_charged_and_renewed(): void
    {
        $product = $this->product(20.00);
        [$option, $child] = $this->pricedOption($product);

        $placed = (new PlaceOrderService)->place($this->client(), 'USD',
            [$this->line($product, 2, options: [['option_id' => $option->id, 'value' => $child->id]])]);

        $this->assertSame('50.00', $this->invoiceTotal($placed->invoice));
        $service = $placed->order->services()->sole();
        $this->assertSame('25.00', number_format((float) $service->price, 2, '.', ''));
        $this->assertSame('25.00', $service->fresh()->calculatePrice());
    }

    /** Inclusive tax: the fixed coupon comes off the gross catalog amount; a first-cycle coupon renews at full. */
    public function test_inclusive_tax_fixed_first_cycle_coupon(): void
    {
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'inclusive']);
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        $coupon = $this->coupon(['type' => 'fixed', 'value' => 5, 'applies_to' => 'price', 'recurring' => 1]);

        $placed = (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($this->product(20.00, 4.00))], $coupon);

        // Δ1: stored net (15/1.24 + 4/1.24 = 15.33) and taxed per line: 19.01. An inclusive catalog can differ by a
        // cent from its gross (neither company uses inclusive, docs/decisions.md Δ1).
        $this->assertEqualsWithDelta(19.00, (float) $this->invoiceTotal($placed->invoice), 0.011);
        // Δ1: the service keeps its net price (20 / 1.24); a renewal charges the catalog's 20.00 again.
        $this->assertSame(2000, $placed->order->services()->sole()->recurringGrossCents());
    }
}
