<?php

namespace Tests\Feature;

use App\Admin\Resources\OrderResource\Pages\CreateOrder;
use App\Admin\Resources\OrderResource\Pages\EditOrder;
use App\Console\Commands\ImportFromWhmcs;
use App\Enums\DomainStatus;
use App\Livewire\Cart as CartPage;
use App\Models\Cart;
use App\Models\Domain;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Filament\Facades\Filament;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * The order page as WHMCS's order view (owner 2026-10-19): date, client, amount, invoice, payment method and status, IP,
 * promotion code, then «Order Items» each with its billing cycle, amount, status and payment status. And the order's IP:
 * kept from the customer's checkout and imported from WHMCS, none for staff's own orders.
 */
class OrderPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function product(string $name = 'WordPress Hosting'): Product
    {
        $product = Product::factory()->create(['name' => $name, 'autosetup' => Product::AUTOSETUP_NEVER, 'server_id' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10.00, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function staff(array $permissions = ['*']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    public function test_the_customers_checkout_keeps_the_ip_and_the_staff_email_shows_it(): void
    {
        Setting::updateOrCreate(['key' => 'system_email_address'], ['value' => 'staff@example.test']);
        Setting::updateOrCreate(['key' => 'mail_disable'], ['value' => false, 'type' => 'boolean']);
        $product = $this->product();
        $cart = Cart::create(['currency_code' => 'USD']);
        $cart->items()->create(['product_id' => $product->id, 'plan_id' => $product->plans()->first()->id, 'config_options' => [], 'checkout_config' => [], 'quantity' => 1]);

        Livewire::withCookie('cart', $cart->ulid)->actingAs(User::factory()->create())->test(CartPage::class)->call('checkout')->assertHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame('127.0.0.1', $order->ip_address);
        $this->assertStringContainsString('127.0.0.1', EmailLog::query()->where('subject', 'like', 'New Order #%')->sole()->body);
    }

    public function test_an_order_staff_create_has_no_ip(): void
    {
        $product = $this->product();

        $this->livewireAs($this->staff())->test(CreateOrder::class)
            ->fillForm(['client_id' => User::factory()->create()->client()->id, 'currency_code' => 'USD', 'lines' => [
                ['product_id' => $product->id, 'plan_id' => $product->plans()->first()->id, 'quantity' => 1, 'price' => null],
            ]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(Order::query()->sole()->ip_address);
    }

    public function test_the_ip_is_not_set_from_a_form(): void
    {
        $order = Order::create(['client_id' => User::factory()->create()->client()->id, 'currency_code' => 'USD', 'ip_address' => '10.0.0.1']);

        $this->assertNull($order->fresh()->ip_address, 'not fillable: only the checkout sets it');
    }

    public function test_the_importer_brings_the_whmcs_ip(): void
    {
        $clientId = User::factory()->create()->client()->id;
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $whmcs->exec('CREATE TABLE tblorders (id INTEGER PRIMARY KEY, userid INT, date TEXT, notes TEXT, status TEXT, invoiceid INT, ipaddress TEXT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $whmcs->exec("INSERT INTO tblclients VALUES ({$clientId}, 1)");
        $whmcs->exec("INSERT INTO tblorders VALUES (9001, {$clientId}, '2020-01-02 10:00:00', '', 'Active', 0, '203.0.113.7')");
        $whmcs->exec("INSERT INTO tblorders VALUES (9002, {$clientId}, '2020-01-02 10:00:00', '', 'Active', 0, '2001:db8::1')");
        $whmcs->exec("INSERT INTO tblorders VALUES (9003, {$clientId}, '2020-01-02 10:00:00', '', 'Active', 0, '')");
        $whmcs->exec("INSERT INTO tblorders VALUES (9004, {$clientId}, '2020-01-02 10:00:00', '', 'Active', 0, 'not an address')");

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        (new ReflectionMethod($command, 'importOrders'))->invoke($command);

        $this->assertSame(['203.0.113.7', '2001:db8::1', null, null],
            Order::query()->whereIn('id', [9001, 9002, 9003, 9004])->orderBy('id')->pluck('ip_address')->all());
    }

    public function test_the_order_page_shows_the_order_and_its_items_as_whmcs(): void
    {
        $product = $this->product();
        $client = User::factory()->create()->client();
        $placed = (new PlaceOrderService)->place($client, 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')],
            notes: 'cpanel.dev', ip: '203.0.113.7');
        $order = $placed->order;
        $domain = Domain::factory()->create(['client_id' => $client->id, 'order_id' => $order->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'status' => DomainStatus::PendingTransfer, 'years' => 1, 'registered_at' => null, 'expires_at' => null]);
        $placed->invoice->items()->create(['description' => 'Transfer', 'price' => '8.00', 'quantity' => 1,
            'reference_type' => Domain::class, 'reference_id' => $domain->id, 'kind' => InvoiceItem::KIND_TRANSFER]);
        $invoice = $placed->invoice->fresh();
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->total, transactionId: 'bank-' . $invoice->id);

        $this->livewireAs($this->staff())->test(EditOrder::class, ['record' => $order->id])
            ->assertSee(['Order #', 'Invoice #', 'Payment Method', 'Payment Status', 'IP Address', 'Promotion Code'])
            ->assertSee('203.0.113.7')
            ->assertSee('#' . ($invoice->number ?? $invoice->id))
            ->assertSee('Order Items')
            ->assertSee(['Product/Service', 'WordPress Hosting', 'Monthly', 'Domain transfer', 'example.com', '1 year'])
            ->assertSee('Paid')
            ->assertFormSet(['notes' => 'cpanel.dev']); // «Notes / Additional Information», editable
    }

    public function test_an_unpaid_order_says_so(): void
    {
        $product = $this->product();
        $order = (new PlaceOrderService)->place(User::factory()->create()->client(), 'USD', [new OrderLine($product, $product->plans()->first(), price: '10.00')])->order;
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail($order->invoice_id)->status);

        $this->livewireAs($this->staff())->test(EditOrder::class, ['record' => $order->id])
            ->assertSee('Unpaid')
            ->assertDontSee('203.0.113.7');
    }
}
