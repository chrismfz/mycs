<?php

namespace Tests\Feature;

use App\Admin\Resources\OrderResource\Pages\CreateOrder;
use App\Events\Service\Ordered;
use App\Exceptions\CouponUnusableException;
use App\Exceptions\DisplayException;
use App\Livewire\Cart as CartPage;
use App\Models\Cart;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Σ7a (docs/orders.md): one way to place an order and one way to price a line, for the cart, checkout, the staff's
 * «Create Order» and a service's recalculation.
 */
class PlaceOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function product(float $price = 10.00, float $setupFee = 0, array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes + ['name' => 'Hosting']);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => $setupFee, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function line(Product $product, int $quantity = 1, ?string $price = null): OrderLine
    {
        return new OrderLine($product, $product->plans()->with('prices')->firstOrFail(), $quantity, price: $price);
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

    private function exclusiveTax(): void
    {
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
    }

    public function test_the_first_invoice_charges_price_and_setup_fee_with_exclusive_tax(): void
    {
        $this->exclusiveTax();
        $placed = (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($this->product(20.00, 5.00), 2)]);

        $item = $placed->invoice->items()->sole();
        // (20 + 5) + 24% = 31.00 a unit; the service renews at 20 + 24% = 24.80.
        $this->assertSame(['31.00', '2'], [number_format((float) $item->price, 2, '.', ''), (string) $item->quantity]);
        $this->assertSame('24.80', number_format((float) $placed->order->services()->sole()->price, 2, '.', ''));
    }

    public function test_nothing_due_makes_no_invoice_and_starts_the_service(): void
    {
        $placed = (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($this->product(0.00))]);

        $this->assertNull($placed->invoice);
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(Service::STATUS_ACTIVE, $placed->order->services()->sole()->status);
    }

    /** The hook for Σ1 (provisioning on order, custom fields): every ordered service, with its line. */
    public function test_every_ordered_service_fires_ordered_with_its_line(): void
    {
        Event::fake([Ordered::class]);
        $line = new OrderLine(($product = $this->product())->fresh(), $product->plans()->first(), customFields: ['hostname' => 'vps1']);

        $placed = (new PlaceOrderService)->place($this->client(), 'USD', [$line]);

        Event::assertDispatched(Ordered::class, fn (Ordered $event) => $event->service->is($placed->order->services()->sole())
            && $event->line->customFields === ['hostname' => 'vps1']);
    }

    public function test_a_line_without_a_price_in_the_currency_is_refused(): void
    {
        $this->expectException(DisplayException::class);

        (new PlaceOrderService)->place($this->client(), 'EUR', [$this->line($this->product())]);
    }

    /** CART-1: an empty «recurring» is the first cycle only, everywhere: the recalculation does not bring it back. */
    public function test_a_coupon_with_empty_cycles_discounts_the_first_invoice_only(): void
    {
        $coupon = $this->coupon(['recurring' => null]);
        $placed = (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($this->product(20.00))], $coupon);
        $service = $placed->order->services()->sole();

        $this->assertSame('10.00', number_format((float) $placed->invoice->items()->sole()->price, 2, '.', ''));
        $this->assertSame('20.00', number_format((float) $service->price, 2, '.', ''), 'renews at the full price');

        // As if paid (the status only: what the recalculation counts).
        DB::table('invoices')->where('id', $placed->invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        $this->assertSame('20.00', $service->fresh()->calculatePrice(), 'a recalculation keeps the full price');
    }

    public function test_a_coupon_for_all_cycles_stays_in_the_renewal_price(): void
    {
        $coupon = $this->coupon(['recurring' => 0]);
        $placed = (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($this->product(20.00))], $coupon);

        $this->assertSame('10.00', number_format((float) $placed->order->services()->sole()->price, 2, '.', ''));
        $this->assertSame('10.00', $placed->order->services()->sole()->calculatePrice());
    }

    /** CART-2: a coupon for one product is not given to the other services of the order. */
    public function test_a_coupon_goes_only_to_the_products_it_covers(): void
    {
        $covered = $this->product(20.00);
        $other = $this->product(30.00, attributes: ['name' => 'Other']);
        $coupon = $this->coupon(['recurring' => 0]);
        $coupon->products()->attach($covered->id);

        $placed = (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($covered), $this->line($other)], $coupon);
        $services = $placed->order->services()->get()->keyBy('product_id');

        $this->assertSame($coupon->id, $services[$covered->id]->coupon_id);
        $this->assertNull($services[$other->id]->coupon_id);
        $this->assertSame('30.00', $services[$other->id]->calculatePrice());
        $this->assertSame(1, $coupon->services()->count(), 'one use, not two');
    }

    /** CART-5: every discounted line is a use, checked under the coupon's lock. */
    public function test_max_uses_counts_every_discounted_line(): void
    {
        $coupon = $this->coupon(['max_uses' => 1]);
        $product = $this->product();

        try {
            (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($product), $this->line($product)], $coupon);
            $this->fail('two uses of a single-use coupon');
        } catch (CouponUnusableException) {
        }
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Service::query()->count());

        (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($product)], $coupon);
        $this->expectException(CouponUnusableException::class);
        (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($product)], $coupon);
    }

    public function test_max_uses_per_account_counts_this_accounts_uses(): void
    {
        $coupon = $this->coupon(['max_uses_per_user' => 1]);
        $product = $this->product();
        $client = $this->client();
        (new PlaceOrderService)->place($client, 'USD', [$this->line($product)], $coupon);
        (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($product)], $coupon);

        $this->expectException(CouponUnusableException::class);
        (new PlaceOrderService)->place($client, 'USD', [$this->line($product)], $coupon);
    }

    public function test_an_expired_coupon_is_refused(): void
    {
        $coupon = $this->coupon(['expires_at' => now()->subDay()]);

        $this->expectException(CouponUnusableException::class);
        (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($this->product())], $coupon);
    }

    /** CART-8: a fixed coupon comes off the net price before tax, at checkout and on a recalculation alike. */
    public function test_a_fixed_coupon_is_priced_the_same_at_checkout_and_on_recalculation(): void
    {
        $this->exclusiveTax();
        $coupon = $this->coupon(['type' => 'fixed', 'value' => 5, 'applies_to' => 'price', 'recurring' => 0]);
        $placed = (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($this->product(20.00))], $coupon);
        $service = $placed->order->services()->sole();

        // (20 − 5) + 24% = 18.60
        $this->assertSame('18.60', number_format((float) $placed->invoice->items()->sole()->price, 2, '.', ''));
        $this->assertSame('18.60', number_format((float) $service->price, 2, '.', ''));
        $this->assertSame('18.60', $service->calculatePrice());
    }

    /** CART-8: «price and setup fee» with a fixed amount takes the amount once. */
    public function test_a_fixed_coupon_on_price_and_setup_fee_takes_the_amount_once(): void
    {
        $coupon = $this->coupon(['type' => 'fixed', 'value' => 10, 'applies_to' => 'all']);
        $placed = (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($this->product(20.00, 20.00))], $coupon);

        $this->assertSame('30.00', number_format((float) $placed->invoice->items()->sole()->price, 2, '.', ''));
    }

    public function test_stock_and_the_per_account_limit_are_taken_and_cancelled_services_do_not_count(): void
    {
        $product = $this->product(attributes: ['stock' => 1, 'per_user_limit' => 1]);
        $client = $this->client();
        $first = (new PlaceOrderService)->place($client, 'USD', [$this->line($product)]);
        $this->assertSame(0, $product->fresh()->stock);

        try {
            (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($product)]);
            $this->fail('sold past the stock');
        } catch (DisplayException) {
        }

        // CART-18: the unpaid order was cancelled; the account may order the product again.
        $product->update(['stock' => null]);
        $first->order->services()->sole()->update(['status' => Service::STATUS_CANCELLED]);
        (new PlaceOrderService)->place($client, 'USD', [$this->line($product->fresh())]);
        $this->assertSame(2, $client->services()->count());
    }

    /** CART-6: the same cart checked out twice (two tabs) is one order. */
    public function test_a_cart_is_checked_out_once(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $cart = Cart::create(['currency_code' => 'USD']);
        $cart->items()->create(['product_id' => $product->id, 'plan_id' => $product->plans()->first()->id,
            'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);

        $tab = fn () => Livewire::withCookie('cart', $cart->ulid)->actingAs($user)->test(CartPage::class);
        $first = $tab();
        $second = $tab();
        $first->call('checkout');
        Once::flush();
        $second->call('checkout');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Invoice::query()->count());
        $this->assertNull(Cart::query()->find($cart->id));
    }

    /** CART-7: the invoice comes from the lines at checkout, not from the total the page was opened with. */
    public function test_checkout_prices_the_cart_as_it_is_now(): void
    {
        $user = User::factory()->create();
        $free = $this->product(0.00);
        $paid = $this->product(15.00, attributes: ['name' => 'Paid']);
        $cart = Cart::create(['currency_code' => 'USD']);
        $cart->items()->create(['product_id' => $free->id, 'plan_id' => $free->plans()->first()->id, 'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);

        $page = Livewire::withCookie('cart', $cart->ulid)->actingAs($user)->test(CartPage::class);
        // Another tab adds a paid item after the page computed a free total.
        $cart->items()->create(['product_id' => $paid->id, 'plan_id' => $paid->plans()->first()->id, 'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);
        Once::flush();
        $page->call('checkout');

        $invoice = Invoice::query()->sole();
        $this->assertSame('15.00', number_format((float) $invoice->items()->sole()->price, 2, '.', ''));
    }

    /** CART-19: a coupon that can no longer be used is taken off the cart, so the customer is not stuck. */
    public function test_an_unusable_coupon_is_removed_from_the_cart(): void
    {
        $user = User::factory()->create();
        $product = $this->product();
        $coupon = $this->coupon(['max_uses' => 1]);
        (new PlaceOrderService)->place($this->client(), 'USD', [$this->line($product)], $coupon);
        $cart = Cart::create(['currency_code' => 'USD', 'coupon_id' => $coupon->id]);
        $cart->items()->create(['product_id' => $product->id, 'plan_id' => $product->plans()->first()->id, 'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);

        Livewire::withCookie('cart', $cart->ulid)->actingAs($user)->test(CartPage::class)->call('checkout');

        $this->assertNull($cart->fresh()->coupon_id);
        $this->assertSame(1, Order::query()->count(), 'nothing ordered');
    }

    /** The cart shows what checkout charges (one pricing). */
    public function test_the_cart_shows_the_checkout_price(): void
    {
        $this->exclusiveTax();
        $user = User::factory()->create();
        CurrentClient::defaultFor($user)->primaryContact->update(['country' => 'GR']);
        $product = $this->product(20.00, 5.00);
        $coupon = $this->coupon(['type' => 'fixed', 'value' => 5, 'applies_to' => 'price']);
        $cart = Cart::create(['currency_code' => 'USD', 'coupon_id' => $coupon->id]);
        $item = $cart->items()->create(['product_id' => $product->id, 'plan_id' => $product->plans()->first()->id, 'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);
        $this->actingAs($user)->withSession($this->loginUser($user));

        $price = $item->fresh()->price;
        // (15 + 5) + 24% = 24.80
        $this->assertSame('24.80', $price->total);
        $this->assertTrue($price->hasDiscount());
    }

    /** CART-4: staff's «Create Order» prices as checkout does (setup fee, tax) and charges it once. */
    public function test_staff_create_order_goes_through_the_service(): void
    {
        $this->exclusiveTax();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.orders.viewAny', 'admin.orders.create'])->create();
        $client = $this->client();
        $product = $this->product(20.00, 5.00);

        $this->livewireAs($staff)->test(CreateOrder::class)
            ->fillForm(['client_id' => $client->id, 'currency_code' => 'USD', 'lines' => [
                ['product_id' => $product->id, 'plan_id' => $product->plans()->first()->id, 'quantity' => 1, 'price' => null],
            ]])
            ->call('create')
            ->assertHasNoFormErrors();

        $service = Service::query()->sole();
        $this->assertSame('24.80', number_format((float) $service->price, 2, '.', ''));
        $this->assertSame('31.00', number_format((float) Invoice::query()->sole()->items()->sole()->price, 2, '.', ''));
    }

    public function test_staff_may_give_a_line_its_own_price(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.orders.viewAny', 'admin.orders.create'])->create();
        $client = $this->client();
        $product = $this->product(20.00, 5.00);

        $this->livewireAs($staff)->test(CreateOrder::class)
            ->fillForm(['client_id' => $client->id, 'currency_code' => 'USD', 'lines' => [
                ['product_id' => $product->id, 'plan_id' => $product->plans()->first()->id, 'quantity' => 1, 'price' => '12.00'],
            ]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('12.00', number_format((float) Service::query()->sole()->price, 2, '.', ''));
        $this->assertSame('17.00', number_format((float) Invoice::query()->sole()->items()->sole()->price, 2, '.', ''));
    }
}
