<?php

namespace Tests\Feature;

use App\Exceptions\CouponUnusableException;
use App\Models\Client;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Services\ServiceUpgrade\ChangeCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #274 (promotions: price override, new/existing customers, once per customer, required products,
 * billing cycles). The `test_c*` tests fail on the branch as reviewed; the `test_holds_*` tests pass and pin attacks that
 * are stopped. Fake data only.
 */
class CouponRulesAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function product(float $price = 2.00, string $unit = 'month', int $period = 1, string $name = 'Agent', float $setupFee = 0): Product
    {
        $product = Product::factory()->create(['name' => $name]);
        $this->plan($product, $price, $unit, $period, $setupFee);

        return $product->fresh();
    }

    private function plan(Product $product, float $price, string $unit = 'month', int $period = 1, float $setupFee = 0, string $currency = 'USD'): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => "{$unit}{$period}",
            'billing_unit' => $unit, 'billing_period' => $period, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => $setupFee, 'currency_code' => $currency]);

        return $plan->fresh();
    }

    private function line(Product $product, ?Plan $plan = null, int $quantity = 1, array $options = []): OrderLine
    {
        return new OrderLine($product->fresh(), ($plan ?? $product->plans()->firstOrFail())->load('prices'), $quantity, $options);
    }

    private function client(): Client
    {
        return User::factory()->create()->client();
    }

    private function coupon(array $attributes = [], array $products = [], array $required = []): Coupon
    {
        $coupon = Coupon::create($attributes + ['code' => 'PROMO' . uniqid(), 'type' => 'override', 'applies_to' => 'all', 'value' => 1.50]);
        $coupon->products()->sync(array_map(fn (Product $p) => $p->id, $products));
        $coupon->requiredProducts()->sync(array_map(fn (Product $p) => $p->id, $required));

        return $coupon->fresh();
    }

    /** @param  list<OrderLine|Product>  $lines */
    private function order(Client $client, array $lines, ?Coupon $coupon, string $currency = 'USD')
    {
        return (new PlaceOrderService)->place($client, $currency, array_map(fn ($l) => $l instanceof Product ? $this->line($l) : $l, $lines), $coupon);
    }

    private function money($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function refused(\Closure $order): bool
    {
        try {
            $order();

            return false;
        } catch (CouponUnusableException) {
            return true;
        }
    }

    // ---------------------------------------------------------------------------------------------------------------

    /**
     * C1 (high): an override for every cycle survives a change of billing cycle. 25OffAgentNode-style: 1.50 forever on
     * the monthly plan (catalog 2.00/month). ChangeCycle refuses a «fixed» coupon or one for N>1 cycles (S3), but not an
     * override for every cycle (cycles() = 0): the customer moves to the yearly plan (24.00/year) and renews at 1.50 a YEAR.
     */
    public function test_c1_an_override_follows_a_change_to_a_longer_cycle(): void
    {
        $agent = $this->product(2.00);
        $yearly = $this->plan($agent, 24.00, 'year');
        $coupon = $this->coupon(['recurring' => 0], [$agent]);
        $client = $this->client();
        $placed = $this->order($client, [$this->line($agent, $agent->plans()->where('billing_unit', 'month')->firstOrFail())], $coupon);
        $service = $placed->order->services()->sole();
        $service->forceFill(['status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addMonth()])->save();
        $this->assertSame('1.50', $this->money($service->price));

        $refusal = ChangeCycle::refusal($service->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL);
        if ($refusal !== null) {
            $this->addToAssertionCount(1); // refused, as a fixed coupon is: fine

            return;
        }
        $this->assertSame('24.00', ChangeCycle::quote($service->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL)['price'],
            'a 1.50/month override must not make the yearly plan 1.50/year');
    }

    /**
     * C2 (high): an override follows the service to another, dearer product. A promotion without a product list (staff
     * restricted it with «Requires» or customers instead) prices the Agent at 1.50; the customer upgrades to the 50.00
     * product and its new recurring price is still 1.50, so the upgrade charges nothing. An override is the price of one
     * product, not a discount that scales.
     */
    public function test_c2_an_override_follows_an_upgrade_to_a_dearer_product(): void
    {
        $agent = $this->product(2.00);
        $big = $this->product(50.00, name: 'Big');
        $coupon = $this->coupon(['recurring' => 0]); // every product, every cycle
        $client = $this->client();
        $service = $this->order($client, [$agent], $coupon)->order->services()->sole();
        $service->forceFill(['status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addMonth()])->save();

        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $big->id, 'plan_id' => $big->plans()->firstOrFail()->id]);

        $this->assertSame('50.00', $this->money($upgrade->fresh()->newRecurringPrice()),
            'an upgrade to a 50.00 product must not keep the 1.50 override');
    }

    /**
     * C3 (medium): the override value is currency-blind. A 1.50 override meant in the default currency (USD) makes a line
     * in another currency 1.50 of that currency: a product of 300 JPY is sold at 1.50 JPY. A «fixed» coupon only takes
     * 1.50 off in that case; an override sets the whole price. Refused or converted is fine, 1.50 JPY is not.
     */
    public function test_c3_an_override_in_another_currency_is_its_value_in_that_currency(): void
    {
        Currency::query()->firstOrCreate(['code' => 'JPY'], ['name' => 'Yen', 'prefix' => '¥', 'format' => '1,000.00']);
        $agent = $this->product(2.00);
        Price::factory()->create(['plan_id' => $agent->plans()->firstOrFail()->id, 'price' => 300, 'setup_fee' => 0, 'currency_code' => 'JPY']);
        $coupon = $this->coupon(['recurring' => 0], [$agent]);

        try {
            $placed = $this->order($this->client(), [$agent], $coupon, 'JPY');
        } catch (CouponUnusableException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->assertNotSame('1.50', $this->money($placed->order->services()->sole()->price),
            'a USD override must not price a JPY line at 1.50 JPY');
    }

    /**
     * C4 (medium): the required product only has to be in the order, not paid. An Agent for 1.50 forever, «requires a
     * Controller»: the customer orders Controller + Agent, pays the Agent's line only, the unpaid Controller is cancelled,
     * and the Agent keeps 1.50 forever (its renewals and any recalculation still apply the coupon).
     */
    public function test_c4_the_required_product_cancelled_unpaid_and_the_discount_stays(): void
    {
        $controller = $this->product(5.00, name: 'Controller');
        $agent = $this->product(2.00);
        $coupon = $this->coupon(['recurring' => 0], [$agent], [$controller]);
        $client = $this->client();
        $placed = $this->order($client, [$controller, $agent], $coupon);
        $agentService = $placed->order->services()->where('product_id', $agent->id)->sole();
        $placed->order->services()->where('product_id', $controller->id)->update(['status' => Service::STATUS_CANCELLED]);
        $agentService->forceFill(['status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addMonth()])->save();

        $this->assertSame('2.00', $this->money($agentService->fresh()->calculatePrice()),
            'without the required product (never paid, cancelled), the agent is not 1.50 any more');
    }

    /**
     * C5 (medium): a free (override 0.00) line is started at once, while the product it requires, in the same order,
     * waits unpaid. FREEAGENT «0.00, requires a Controller»: Controller + Agent in one order; the Agent is active now
     * (nothing to pay), the Controller's invoice is never paid. The free service runs without what made it free.
     */
    public function test_c5_a_free_line_starts_before_the_product_it_requires_is_paid(): void
    {
        $controller = $this->product(5.00, name: 'Controller');
        $agent = $this->product(2.00);
        $coupon = $this->coupon(['value' => 0, 'recurring' => 1], [$agent], [$controller]);
        $placed = $this->order($this->client(), [$controller, $agent], $coupon);

        $this->assertNotNull($placed->invoice, 'the controller is due');
        $this->assertSame('unpaid', $placed->invoice->status);
        $this->assertNotSame(Service::STATUS_ACTIVE, $placed->order->services()->where('product_id', $agent->id)->sole()->status,
            'the free agent must wait for the controller it requires to be paid');
    }

    /**
     * C6 (medium): «new customers only» counts services only. An account that has held domains for years (or ordered
     * anything that is not a service) is «new» and gets the new-signup promotion; WHMCS's «New Signups» looks at the
     * client's orders.
     */
    public function test_c6_a_domain_customer_is_not_a_new_customer(): void
    {
        $product = $this->product();
        $client = $this->client();
        Domain::factory()->create(['client_id' => $client->id]);
        $coupon = $this->coupon(['customers' => 'new', 'value' => 0, 'recurring' => 1]);

        $this->assertTrue($this->refused(fn () => $this->order($client->fresh(), [$product], $coupon)),
            'an account with an active domain is an existing customer');
    }

    /**
     * C7 (low; to decide): an override flattens the product's configurable options into its value at checkout. Agent 2.00
     * + an option of 5.00 with a 1.50 override: the line is 1.50, the option free. The same option added later (an
     * options-only upgrade) costs its full price on top of 1.50: order with every option to get them for nothing. If
     * flattening is intended (as WHMCS?), flip this into a holds test and document it.
     */
    public function test_c7_an_override_makes_the_paid_options_free(): void
    {
        $agent = $this->product(2.00);
        $option = ConfigOption::create(['name' => 'Backups', 'type' => 'select', 'sort' => 0]);
        $option->products()->attach($agent->id);
        $child = ConfigOption::create(['name' => 'Daily', 'sort' => 0, 'parent_id' => $option->id]);
        $plan = Plan::factory()->create(['priceable_id' => $child->id, 'priceable_type' => ConfigOption::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 5.00, 'setup_fee' => 0, 'currency_code' => 'USD']);
        $coupon = $this->coupon(['recurring' => 0], [$agent]);

        $placed = $this->order($this->client(), [$this->line($agent, options: [['option_id' => $option->id, 'value' => $child->id]])], $coupon);

        $this->assertSame('6.50', $this->money($placed->invoice->items()->sole()->price),
            'the override is the product\'s price; the 5.00 option comes on top');
    }

    // --------------------------------------------------------------------------------------------------- holds

    /** Cancelling the free trial and ordering it again: the cancelled service still counts as the use. */
    public function test_holds_cancel_and_reorder_once_per_customer(): void
    {
        $product = $this->product();
        // Author (#274 fixes, C2): an override names its products; without them it discounts nothing.
        $coupon = $this->coupon(['value' => 0, 'once_per_client' => true, 'recurring' => 1], [$product]);
        $client = $this->client();
        $this->order($client, [$product], $coupon);
        Service::query()->where('client_id', $client->id)->update(['status' => Service::STATUS_CANCELLED]);

        $this->assertTrue($this->refused(fn () => $this->order($client->fresh(), [$product], $coupon)));
    }

    /** The trial's cycles ended (coupon_expired_at set by the cron): still used, not usable again. */
    public function test_holds_once_per_customer_after_the_trial_ended(): void
    {
        $product = $this->product();
        // Author (#274 fixes, C2): an override names its products; without them it discounts nothing.
        $coupon = $this->coupon(['value' => 0, 'once_per_client' => true, 'recurring' => 2], [$product]);
        $client = $this->client();
        $this->order($client, [$product], $coupon);
        Service::query()->where('client_id', $client->id)->update(['coupon_expired_at' => now(), 'status' => Service::STATUS_TERMINATED]);

        $this->assertTrue($this->refused(fn () => $this->order($client->fresh(), [$product], $coupon)));
    }

    /** A cancelled, never-paid order still makes the account an existing one for a «new customers» promotion. */
    public function test_holds_new_only_after_a_cancelled_order(): void
    {
        $product = $this->product();
        $client = $this->client();
        $this->order($client, [$product], null);
        Service::query()->where('client_id', $client->id)->update(['status' => Service::STATUS_CANCELLED]);
        $coupon = $this->coupon(['customers' => 'new', 'value' => 0]);

        $this->assertTrue($this->refused(fn () => $this->order($client->fresh(), [$product], $coupon)));
    }

    /** «Or held already» needs an active holding: a suspended or terminated one does not count. */
    public function test_holds_requires_existing_with_a_suspended_or_terminated_holding(): void
    {
        $controller = $this->product(5.00, name: 'Controller');
        $agent = $this->product(2.00);
        $coupon = $this->coupon(['requires_existing' => true, 'recurring' => 0], [$agent], [$controller]);
        foreach ([Service::STATUS_SUSPENDED, Service::STATUS_TERMINATED, Service::STATUS_PENDING] as $status) {
            $client = $this->client();
            $this->order($client, [$controller], null);
            Service::query()->where('client_id', $client->id)->update(['status' => $status]);
            $this->assertTrue($this->refused(fn () => $this->order($client->fresh(), [$agent], $coupon)), $status);
        }
    }

    /** «Existing customers» needs an active service: a suspended-only account is refused. */
    public function test_holds_existing_only_needs_an_active_service(): void
    {
        $product = $this->product();
        $client = $this->client();
        $this->order($client, [$product], null);
        Service::query()->where('client_id', $client->id)->update(['status' => Service::STATUS_SUSPENDED]);
        $coupon = $this->coupon(['customers' => 'existing', 'type' => 'percentage', 'value' => 10]);

        $this->assertTrue($this->refused(fn () => $this->order($client->fresh(), [$product], $coupon)));
    }

    /** An annual-only promotion, then a change to the monthly plan: the monthly price is the full one. */
    public function test_holds_an_annual_only_coupon_after_a_change_to_monthly(): void
    {
        $product = $this->product(100.00, 'year', name: 'Hosting');
        $monthly = $this->plan($product, 10.00, 'month');
        $coupon = $this->coupon(['type' => 'percentage', 'value' => 50, 'recurring' => 0, 'billing_cycles' => ['year:1']]);
        $service = $this->order($this->client(), [$this->line($product, $product->plans()->where('billing_unit', 'year')->firstOrFail())], $coupon)
            ->order->services()->sole();
        $this->assertSame('50.00', $this->money($service->price));
        $service->forceFill(['status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addYear()])->save();

        $this->assertNull(ChangeCycle::refusal($service->fresh(), $monthly, ChangeCycle::NEXT_RENEWAL));
        $this->assertSame('10.00', ChangeCycle::quote($service->fresh(), $monthly, ChangeCycle::NEXT_RENEWAL)['price']);
    }

    /** An override never raises a price, with a group discount, a setup fee and a quantity. */
    public function test_holds_an_override_never_raises_and_counts_only_discounted_lines(): void
    {
        $cheap = $this->product(1.00, name: 'Cheap', setupFee: 0.50);
        $dear = $this->product(20.00, name: 'Dear', setupFee: 10.00);
        // Author (#274 fixes, C2): an override names its products; without them it discounts nothing.
        $coupon = $this->coupon(['value' => 1.50, 'max_uses' => 3, 'recurring' => 0], [$cheap, $dear]);
        $placed = $this->order($this->client(), [$this->line($cheap, quantity: 2), $this->line($dear, quantity: 3)], $coupon);

        $services = $placed->order->services()->orderBy('id')->get();
        $this->assertSame(['1.00', '1.50'], $services->map(fn (Service $s) => $this->money($s->price))->all());
        $this->assertSame([null, $coupon->id], $services->map(fn (Service $s) => $s->coupon_id)->all(), 'the undiscounted line is no use');
        // 1.00 + 0.50 setup for the cheap one; 1.50 + 1.50 setup for the dear one.
        $this->assertSame(['1.50', '3.00'], $placed->invoice->items()->orderBy('id')->pluck('price')->map(fn ($p) => $this->money($p))->all());
    }

    /** A guest's cart is checked again at checkout once logged in: an existing customer gets no «new» promotion. */
    public function test_holds_new_only_through_a_checkout_as_an_existing_customer(): void
    {
        $product = $this->product();
        $client = $this->client();
        $this->order($client, [$product], null);
        $coupon = $this->coupon(['customers' => 'new', 'value' => 0]);
        // The guest path skips the customer rules in the cart (refusalFor(null)); checkout passes the account.
        $this->assertNull($coupon->refusalFor(null, [$product->id]));

        $this->assertTrue($this->refused(fn () => $this->order($client->fresh(), [$product], $coupon)));
    }
}
