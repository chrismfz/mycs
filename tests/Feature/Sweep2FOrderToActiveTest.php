<?php

namespace Tests\Feature;

use App\Livewire\Services\Upgrade;
use App\Models\Cart;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial verification of flow «order to active» (sweep 2). Each test fails on 55e34ff9 for the reason given.
 */
class Sweep2FOrderToActiveTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Carbon::setTestNow('2026-01-15 10:00:00');
        config(['settings.cronjob_order_suspend' => 8, 'settings.cronjob_invoice' => 7, 'settings.cronjob_order_cancel' => 7,
            'settings.cronjob_order_terminate' => 60]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $price, string $setupFee = '0.00', array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes + ['name' => 'P' . $price, 'autosetup' => Product::AUTOSETUP_ORDER,
            'server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Plan',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => $setupFee, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    /**
     * F-ORDER-TO-ACTIVE-1: Service::orderUnpaid() looks for a first-invoice line with no period, but a pro rata order's
     * line has its period (to the product's day). Set up on order and unpaid, the service is upgradable; the upgrade
     * costs 0.00 (expires_at = the order date) and refreshOpenPeriods reprices the order's line as a full renewal: the
     * setup fee and the pro rata stub are gone.
     */
    public function test_f1_an_unpaid_pro_rata_order_set_up_on_order_is_not_upgraded_for_free(): void
    {
        $from = $this->product('10.00', '20.00', ['prorata_day' => 1, 'prorata_next_after_day' => null]);
        $to = $this->product('20.00', '0.00', ['prorata_day' => 1, 'prorata_next_after_day' => null]);
        DB::table('product_upgrades')->insert(['product_id' => $from->id, 'upgrade_id' => $to->id]);

        (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($from, $from->plans()->firstOrFail())]);
        $service = Service::query()->latest('id')->firstOrFail();
        $line = $service->invoices()->firstOrFail()->items()->firstOrFail();
        $this->assertNotNull($line->period_start, 'a pro rata order line has its period');
        $before = (float) $line->price;
        $this->assertGreaterThan(20.0, $before, 'stub + setup fee');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'set up on order, unpaid');
        $this->assertSame(Invoice::STATUS_UNPAID, $line->invoice->status);

        $upgradable = (bool) $service->fresh()->upgradable;

        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()])->set('upgrade', $to->id)->call('doUpgrade');

        $after = $line->fresh();
        $this->assertSame(number_format($before, 2), number_format((float) $after->price, 2),
            'the order line was repriced (setup fee and stub lost), now ends ' . $after->period_end?->toDateString()
            . '; upgrades: ' . ServiceUpgrade::query()->count());
        $this->assertFalse($upgradable, 'not upgradable while its order is unpaid (as A5, pro rata)');
        $this->assertSame(0, ServiceUpgrade::query()->count(), 'no upgrade of an unpaid order');
    }

    /**
     * F-ORDER-TO-ACTIVE-2: a «fixed» coupon's value is in the default currency (the form says so), yet it is taken off a
     * cart in any currency as is. An «override» is refused outside the default currency (C3); a fixed one is not.
     */
    public function test_f2_a_fixed_coupon_in_the_default_currency_is_not_taken_off_another_currency_as_is(): void
    {
        config(['settings.default_currency' => 'EUR']);
        $product = $this->product('20.00', '0.00', ['autosetup' => Product::AUTOSETUP_PAYMENT]);
        $coupon = Coupon::create(['code' => 'TENOFF', 'type' => 'fixed', 'applies_to' => 'all', 'value' => '10.00']);

        $placed = (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->firstOrFail())], $coupon);
        $line = $placed->invoice->items()->firstOrFail();

        // 10 EUR taken off a USD cart as 10 USD: no conversion (there are no rates) and no refusal.
        $this->assertNotSame('10.00', number_format((float) $line->price, 2), 'a coupon valued in EUR discounted 10.00 USD');
    }

    /**
     * F-ORDER-TO-ACTIVE-3: an offer puts each select option at its first child, hidden or not. The cart shows the hidden
     * value; checkout neither prices it nor gives it to the service (orderableChildren), so the service has no value for
     * the option at all.
     */
    public function test_f3_an_offer_never_puts_a_hidden_option_value_in_the_cart(): void
    {
        config(['settings.default_currency' => 'USD']);
        $product = $this->createProduct()->product;
        $option = ConfigOption::create(['name' => 'Location', 'type' => 'select', 'sort' => 0]);
        $option->products()->attach($product->id);
        $retired = ConfigOption::create(['name' => 'Old DC', 'parent_id' => $option->id, 'sort' => 0, 'hidden' => true]);
        $current = ConfigOption::create(['name' => 'Athens', 'parent_id' => $option->id, 'sort' => 1]);
        $offer = Offer::query()->create(['name' => 'Kit', 'slug' => 'kit', 'active' => true]);
        $offer->items()->create(['product_id' => $product->id, 'quantity' => 1, 'sort' => 0]);

        $this->get('/offer/kit')->assertRedirect(route('cart'));
        $item = Cart::query()->latest('id')->with('items')->firstOrFail()->items->firstOrFail();
        $chosen = collect($item->config_options)->map(fn ($o) => (array) $o)->firstWhere('option_id', $option->id);

        // At checkout (the lines as Livewire\Cart builds them) the service gets no value for the option.
        (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product->fresh(), $product->plans()->firstOrFail(), 1, $item->config_options ?? [])]);
        $service = Service::query()->latest('id')->firstOrFail();

        $this->assertNotSame($retired->id, (int) $chosen['value'], 'the cart shows the hidden value «' . $chosen['value_name'] . '»; service configs for it: ' . $service->configs()->where('config_option_id', $option->id)->count());
        $this->assertSame($current->id, (int) $service->configs()->where('config_option_id', $option->id)->value('config_value_id'),
            'the service has no value for the option the cart showed');
    }
}
