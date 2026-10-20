<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Livewire\Cart as CartPage;
use App\Models\Cart;
use App\Models\Client;
use App\Models\Domain;
use App\Models\EmailLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Order\StaffOrderNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Independent review of b3386b8 (staff «New Order» email): what the email gets wrong. */
class StaffOrderNoticeReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::updateOrCreate(['key' => 'system_email_address'], ['value' => 'staff@example.test']);
        Setting::updateOrCreate(['key' => 'mail_disable'], ['value' => false, 'type' => 'boolean']);
    }

    private function notice(): EmailLog
    {
        return EmailLog::query()->where('subject', 'like', 'New Order #%')->sole();
    }

    /** R1: a line of three units is mailed as one: staff read one licence/account where three were bought. */
    public function test_a_lines_quantity_is_in_the_email(): void
    {
        $product = Product::factory()->create(['name' => 'Licence']);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $cart = Cart::create(['currency_code' => 'USD']);
        $cart->items()->create(['product_id' => $product->id, 'plan_id' => $plan->id, 'config_options' => [], 'checkout_config' => [], 'quantity' => 3]);

        Livewire::withCookie('cart', $cart->ulid)->actingAs(User::factory()->create())->test(CartPage::class)->call('checkout')->assertHasNoErrors();

        $this->assertSame(3, (int) Order::query()->sole()->services()->sole()->quantity, 'one service of three units');
        $this->assertMatchesRegularExpression('/(×|x)\s*3\b|\b3\s*(×|x)/u', $this->notice()->body, 'the quantity is said');
    }

    /**
     * R2: the operation is read from the domain's status when the mail is built, after the commit. A free transfer's
     * FulfilDomainOrderJob is dispatched after that commit and may have run (sync queue, a fast worker, a registrar
     * that completes at once) and set it active: the mail then says «Domain registration».
     */
    public function test_a_transfer_already_fulfilled_is_not_called_a_registration(): void
    {
        $client = Client::factory()->create();
        $order = Order::query()->create(['client_id' => $client->id, 'currency_code' => 'USD']);
        Domain::factory()->create(['client_id' => $client->id, 'order_id' => $order->id, 'sld' => 'moved-here', 'tld' => 'test', 'fqdn' => 'moved-here.test',
            'status' => DomainStatus::Active, 'epp_code' => 'AUTH-123', 'meta' => ['free_order' => true]]);

        StaffOrderNotice::send($order);

        $this->assertStringContainsString('Domain transfer: moved-here.test', $this->notice()->body);
    }
}
