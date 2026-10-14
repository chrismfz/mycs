<?php

namespace Tests\Feature;

use App\Admin\Resources\OrderResource\Pages\CreateOrder;
use App\Admin\Resources\OrderResource\Pages\EditOrder;
use App\Admin\Resources\OrderResource\Pages\ListOrders;
use App\Livewire\Cart as CartPage;
use App\Models\ApiKey;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The order's notes (WHMCS `tblorders.notes`): written at checkout or by staff, kept on the order. */
class OrderNotesTest extends TestCase
{
    use RefreshDatabase;

    private function product(float $price = 10.00): Product
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function cart(Product $product): Cart
    {
        $cart = Cart::create(['currency_code' => 'USD']);
        $cart->items()->create(['product_id' => $product->id, 'plan_id' => $product->plans()->first()->id, 'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);

        return $cart;
    }

    public function test_checkout_keeps_the_customers_notes(): void
    {
        $user = User::factory()->create();
        $cart = $this->cart($this->product());

        Livewire::withCookie('cart', $cart->ulid)->actingAs($user)->test(CartPage::class)
            ->set('notes', "  Please set up before Monday.\n  ")
            ->call('checkout');

        $this->assertSame('Please set up before Monday.', Order::query()->sole()->notes);
    }

    public function test_empty_notes_are_none_and_long_ones_are_refused(): void
    {
        $user = User::factory()->create();
        $product = $this->product();

        Livewire::withCookie('cart', $this->cart($product)->ulid)->actingAs($user)->test(CartPage::class)
            ->set('notes', str_repeat('x', 2001))
            ->call('checkout')
            ->assertHasErrors('notes');
        $this->assertSame(0, Order::query()->count());

        Livewire::withCookie('cart', Cart::query()->sole()->ulid)->actingAs($user)->test(CartPage::class)
            ->set('notes', '   ')
            ->call('checkout');
        $this->assertNull(Order::query()->sole()->notes);
    }

    public function test_staff_write_notes_on_an_order_they_create(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.orders.viewAny', 'admin.orders.create'])->create();
        $client = User::factory()->create()->client();
        $product = $this->product();

        $this->livewireAs($staff)->test(CreateOrder::class)
            ->fillForm(['client_id' => $client->id, 'currency_code' => 'USD', 'notes' => 'Ordered by phone.', 'lines' => [
                ['product_id' => $product->id, 'plan_id' => $product->plans()->first()->id, 'quantity' => 1, 'price' => null],
            ]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('Ordered by phone.', Order::query()->sole()->notes);
    }

    public function test_staff_change_the_notes_and_see_them_in_the_list(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.orders.viewAny', 'admin.orders.update'])->create();
        $order = Order::create(['client_id' => User::factory()->create()->client()->id, 'currency_code' => 'USD', 'notes' => 'Old']);

        $this->livewireAs($staff)->test(EditOrder::class, ['record' => $order->id])
            ->fillForm(['notes' => 'Call before setup.'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('Call before setup.', $order->fresh()->notes);

        $this->livewireAs($staff)->test(ListOrders::class)->assertSee('Call before setup.');
    }

    public function test_the_api_reads_and_writes_the_notes(): void
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 't', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'permissions' => ['admin.orders.create', 'admin.orders.update', 'admin.orders.view']]);
        $client = User::factory()->create()->client();

        $id = $this->withToken($token)->postJson(route('api.v1.admin.orders.store'), ['client_id' => $client->id, 'currency_code' => 'USD', 'notes' => 'From the API'])
            ->assertSuccessful()->assertJsonPath('data.attributes.notes', 'From the API')->json('data.id');

        $this->withToken($token)->putJson(route('api.v1.admin.orders.update', $id), ['notes' => 'Changed'])->assertSuccessful();
        $this->assertSame('Changed', Order::query()->findOrFail($id)->notes);
    }

    /** The cart shows its tax (Σ7b: its total is the invoice lines', with their tax). */
    public function test_the_cart_shows_its_tax(): void
    {
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.default_currency' => 'USD']);
        TaxRate::create(['name' => 'ΦΠΑ', 'rate' => 24, 'country' => 'GR']);
        $user = User::factory()->create();
        $user->client()->primaryContact->update(['country' => 'GR']);

        $page = Livewire::withCookie('cart', $this->cart($this->product(20.00))->ulid)->actingAs($user)->test(CartPage::class);

        $this->assertSame(['24.80', '4.80'], [$page->get('total')->total, $page->get('total')->tax]);
    }
}
