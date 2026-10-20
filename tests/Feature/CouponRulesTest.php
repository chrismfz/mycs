<?php

namespace Tests\Feature;

use App\Exceptions\CouponUnusableException;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Promotions as WHMCS's (ROADMAP 5, nixpal's FREETRIAL, 25OffAgentNode, MOBIAN10/25): a price override; new or existing
 * customers only; once per customer; a required product, in the order or held already; some billing cycles only.
 * Checked again at checkout, under the coupon's lock.
 */
class CouponRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function product(float $price = 20.00, string $unit = 'month', int $period = 1, string $name = 'Agent'): Product
    {
        $product = Product::factory()->create(['name' => $name]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => $unit,
            'billing_unit' => $unit, 'billing_period' => $period, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function line(Product $product): OrderLine
    {
        return new OrderLine($product, $product->plans()->with('prices')->firstOrFail(), 1);
    }

    private function client(): Client
    {
        return User::factory()->create()->client();
    }

    private function coupon(array $attributes = [], array $products = [], array $required = []): Coupon
    {
        $coupon = Coupon::create($attributes + ['code' => 'PROMO' . uniqid(), 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50]);
        $coupon->products()->sync(array_map(fn (Product $p) => $p->id, $products));
        $coupon->requiredProducts()->sync(array_map(fn (Product $p) => $p->id, $required));

        return $coupon->fresh();
    }

    /** @param  list<Product>  $products */
    private function order(Client $client, array $products, ?Coupon $coupon)
    {
        return (new PlaceOrderService)->place($client, 'USD', array_map(fn (Product $p) => $this->line($p), $products), $coupon);
    }

    private function money($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    public function test_a_price_override_free_trial_and_a_fixed_price(): void
    {
        $controller = $this->product(5.00, name: 'Controller');
        $agent = $this->product(2.00);
        // FREETRIAL: 0.00 for its first cycles; 25OffAgentNode: 1.50.
        $trial = $this->coupon(['type' => 'override', 'value' => 0, 'recurring' => 2], [$controller, $agent]);
        $placed = $this->order($this->client(), [$controller, $agent], $trial);
        $this->assertSame(['0.00', '0.00'], $placed->order->services->map(fn (Service $s) => $this->money($s->price))->all());

        $fixed = $this->coupon(['type' => 'override', 'value' => 1.50, 'applies_to' => 'price', 'recurring' => 0], [$agent]);
        $line = $this->order($this->client(), [$agent], $fixed)->invoice->items()->sole();
        $this->assertSame('1.50', $this->money($line->price));
    }

    public function test_only_the_billing_cycles_it_lists(): void
    {
        $monthly = $this->product(10.00);
        $yearly = $this->product(100.00, 'year', 1, 'Yearly');
        $coupon = $this->coupon(['billing_cycles' => ['year:1', 'year:2']]);

        $placed = $this->order($this->client(), [$monthly, $yearly], $coupon);

        $prices = $placed->invoice->items()->orderBy('id')->pluck('price')->map(fn ($p) => $this->money($p))->all();
        $this->assertSame(['10.00', '50.00'], $prices, 'a monthly plan is not discounted by an annual-only promotion');
    }

    public function test_new_or_existing_customers_only(): void
    {
        $product = $this->product();
        $returning = $this->client();
        $this->order($returning, [$product], null);

        $forNew = $this->coupon(['customers' => 'new']);
        $this->order($this->client(), [$product], $forNew); // a new one: fine
        $this->assertRefused(fn () => $this->order($returning->fresh(), [$product], $forNew), 'new customers only');

        $forExisting = $this->coupon(['customers' => 'existing']);
        $this->assertRefused(fn () => $this->order($this->client(), [$product], $forExisting), 'existing customers only');
        Service::query()->where('client_id', $returning->id)->update(['status' => Service::STATUS_ACTIVE]);
        $this->order($returning->fresh(), [$product], $forExisting);
    }

    public function test_once_per_customer_whatever_the_units(): void
    {
        $a = $this->product(name: 'A');
        $b = $this->product(name: 'B');
        $coupon = $this->coupon(['once_per_client' => true]);
        $client = $this->client();

        $this->order($client, [$a, $b], $coupon); // two units, one use by this customer

        $this->assertRefused(fn () => $this->order($client->fresh(), [$a], $coupon), 'once per customer');
        $this->order($this->client(), [$a], $coupon); // another customer: fine
    }

    public function test_a_required_product_in_the_order_or_held_already(): void
    {
        $controller = $this->product(name: 'Controller');
        $agent = $this->product(2.00);
        $coupon = $this->coupon(['type' => 'override', 'value' => 1.50], [$agent], [$controller]);
        $client = $this->client();

        $this->assertRefused(fn () => $this->order($client, [$agent], $coupon), 'requires a controller');
        $this->order($client, [$controller, $agent], $coupon);

        $holds = $this->client();
        $this->order($holds, [$controller], null);
        Service::query()->where('client_id', $holds->id)->update(['status' => Service::STATUS_ACTIVE]);
        $this->assertRefused(fn () => $this->order($holds->fresh(), [$agent], $coupon), 'held, but not allowed');
        $coupon->update(['requires_existing' => true]);
        $this->order($holds->fresh(), [$agent], $coupon->fresh());
    }

    private function assertRefused(\Closure $order, string $why): void
    {
        try {
            $order();
            $this->fail("not refused: {$why}");
        } catch (CouponUnusableException) {
            $this->addToAssertionCount(1);
        }
    }
}
