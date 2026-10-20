<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\OrderResource;
use App\Admin\Resources\OrderResource\Pages\CreateOrder;
use App\Livewire\Cart as CartPage;
use App\Models\Cart;
use App\Models\EmailLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WHMCS's «New Order Notification»: a customer's order at checkout is mailed to staff (the System Email Address), with
 * its items, invoice, the customer's notes and links to it. Not an order staff create.
 */
class StaffOrderNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Stored: a Livewire request loads the settings again from the database.
        Setting::updateOrCreate(['key' => 'system_email_address'], ['value' => 'staff@example.test']);
        Setting::updateOrCreate(['key' => 'mail_disable'], ['value' => false, 'type' => 'boolean']);
    }

    private function product(float $price = 10.00, string $name = 'WordPress Hosting'): Product
    {
        $product = Product::factory()->create(['name' => $name]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function checkout(Product $product, string $notes = ''): Order
    {
        $cart = Cart::create(['currency_code' => 'USD']);
        $cart->items()->create(['product_id' => $product->id, 'plan_id' => $product->plans()->first()->id, 'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);

        Livewire::withCookie('cart', $cart->ulid)->actingAs(User::factory()->create())->test(CartPage::class)
            ->set('notes', $notes)
            ->call('checkout')
            ->assertHasNoErrors();

        return Order::query()->sole();
    }

    private function notices()
    {
        return EmailLog::query()->where('subject', 'like', 'New Order #%')->get();
    }

    public function test_a_customers_order_is_mailed_to_staff_with_its_notes(): void
    {
        $order = $this->checkout($this->product(), "Please set up before Monday.\nThanks");

        $mail = $this->notices()->sole();
        $this->assertSame('staff@example.test', $mail->to);
        $this->assertStringStartsWith("New Order #{$order->id}", $mail->subject);
        $this->assertStringContainsString(e(OrderResource::getUrl('edit', ['record' => $order->id], panel: 'admin')), $mail->body);
        $this->assertStringContainsString('WordPress Hosting', $mail->body);
        $this->assertStringContainsString('Monthly', $mail->body);
        $this->assertStringContainsString('Please set up before Monday.<br />', $mail->body, 'the notes, line breaks kept');
        $this->assertNotNull($order->invoice_id);
        $this->assertStringContainsString(e(InvoiceResource::getUrl('view', ['record' => $order->invoice_id], panel: 'admin')), $mail->body, 'a link to its invoice');
    }

    public function test_an_order_with_no_notes_is_mailed_too(): void
    {
        $this->checkout($this->product());

        $this->assertStringContainsString('<strong>Notes</strong></p><p>—</p>', $this->notices()->sole()->body);
    }

    public function test_the_setting_off_sends_none(): void
    {
        Setting::updateOrCreate(['key' => 'mail_staff_new_order'], ['value' => false, 'type' => 'boolean']);

        $this->checkout($this->product());

        $this->assertCount(0, $this->notices());
    }

    public function test_what_the_customer_typed_is_escaped(): void
    {
        $this->checkout($this->product(name: 'Hosting <b>x</b>'), '<script>alert(1)</script>');

        $body = $this->notices()->sole()->body;
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
        $this->assertStringNotContainsString('<b>x</b>', $body);
    }

    public function test_an_order_staff_create_is_not_mailed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.orders.viewAny', 'admin.orders.create'])->create();
        $product = $this->product();

        $this->livewireAs($staff)->test(CreateOrder::class)
            ->fillForm(['client_id' => User::factory()->create()->client()->id, 'currency_code' => 'USD', 'notes' => 'By phone', 'lines' => [
                ['product_id' => $product->id, 'plan_id' => $product->plans()->first()->id, 'quantity' => 1, 'price' => null],
            ]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Order::query()->count());
        $this->assertCount(0, $this->notices());
    }

    public function test_a_failed_checkout_sends_none(): void
    {
        $cart = Cart::create(['currency_code' => 'USD']); // empty

        Livewire::withCookie('cart', $cart->ulid)->actingAs(User::factory()->create())->test(CartPage::class)->call('checkout');

        $this->assertSame(0, Order::query()->count());
        $this->assertCount(0, $this->notices());
    }
}
