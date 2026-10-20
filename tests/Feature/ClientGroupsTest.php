<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientGroupResource;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\OrderPricing;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Customer groups with a discount (ROADMAP 5, owner 2026-10-20; nixpal: a 10% group billed every month): the percentage
 * comes off its members' services after a coupon, in their price (decided 2026-10-20, not a line of its own), never off
 * a domain; a move or a new percentage reprices the computed prices, never staff's.
 */
class ClientGroupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(float $price = 20.00, float $setupFee = 0): Product
    {
        $product = Product::factory()->create(['name' => 'Agent']);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => $setupFee, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function line(Product $product): OrderLine
    {
        return new OrderLine($product, $product->plans()->with('prices')->firstOrFail(), 1);
    }

    private function client(?ClientGroup $group = null): Client
    {
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['country' => 'GR']);
        if ($group !== null) {
            $client->update(['client_group_id' => $group->id]);
        }

        return $client->fresh();
    }

    private function group(string $percent = '10'): ClientGroup
    {
        return ClientGroup::query()->create(['name' => 'Partners', 'discount_percent' => $percent]);
    }

    private function money($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    public function test_an_order_is_discounted_and_renews_at_the_discounted_price(): void
    {
        $placed = (new PlaceOrderService)->place($this->client($this->group()), 'USD', [$this->line($this->product(20.00, 5.00))]);

        $this->assertSame('22.50', $this->money($placed->invoice->items()->sole()->price), '20 − 10% + setup 5 − 10%');
        $this->assertSame('18.00', $this->money($placed->order->services()->sole()->price));
        $this->assertSame('18.00', $placed->order->services()->sole()->calculatePrice());
    }

    public function test_after_a_first_cycle_coupon_and_before_tax(): void
    {
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
        $coupon = Coupon::create(['code' => 'HALF', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => null]);

        $placed = (new PlaceOrderService)->place($this->client($this->group()), 'USD', [$this->line($this->product(20.00))], $coupon);
        $line = $placed->invoice->items()->sole();

        // 20 − 50% = 10, − 10% = 9.00 net; 24% on that = 2.16.
        $this->assertSame(['9.00', '2.16'], [$this->money($line->price), $this->money($line->tax_amount)]);
        $this->assertSame('18.00', $this->money($placed->order->services()->sole()->price), 'the coupon ends, the group stays');
    }

    public function test_a_line_without_a_product_is_never_discounted(): void
    {
        $client = $this->client($this->group('50'));

        // A domain, an amount: no product, no group discount.
        $this->assertSame('15.00', OrderPricing::line(['price' => '15.00', 'setup_fee' => '0.00'], null, $client)->netPrice);
    }

    public function test_a_move_or_a_new_percentage_reprices_computed_prices_never_staffs(): void
    {
        $client = $this->client();
        $product = $this->product(20.00);
        $computed = (new PlaceOrderService)->place($client, 'USD', [$this->line($product)])->order->services()->sole();
        $byHand = (new PlaceOrderService)->place($client, 'USD', [$this->line($product)])->order->services()->sole();
        $byHand->update(['price' => 12.34]);
        $ended = (new PlaceOrderService)->place($client, 'USD', [$this->line($product)])->order->services()->sole();
        $ended->update(['status' => Service::STATUS_TERMINATED]);

        $group = $this->group('10');
        $client->update(['client_group_id' => $group->id]);
        $this->assertSame(['18.00', '12.34', '20.00'], [$this->money($computed->fresh()->price), $this->money($byHand->fresh()->price), $this->money($ended->fresh()->price)]);

        $group->update(['discount_percent' => 25]);
        $this->assertSame(['15.00', '12.34'], [$this->money($computed->fresh()->price), $this->money($byHand->fresh()->price)]);

        $client->fresh()->update(['client_group_id' => null]);
        $this->assertSame('20.00', $this->money($computed->fresh()->price), 'leaving the group: the full price again');

        $client->fresh()->update(['client_group_id' => $group->id]);
        $group->delete();
        $this->assertSame([null, '20.00'], [$client->fresh()->client_group_id, $this->money($computed->fresh()->price)], 'a deleted group: as a move');
    }

    public function test_the_renewal_line_says_the_discount_it_holds(): void
    {
        Setting::query()->updateOrCreate(['key' => 'default_currency', 'settingable_type' => null], ['value' => 'USD', 'type' => 'string']);
        config(['settings.default_currency' => 'USD', 'settings.cronjob_invoice' => 7]);
        Cache::forget('settings.rows');
        $client = $this->client($this->group());
        $product = $this->product(20.00);
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plans()->first()->id, 'product_id' => $product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 18.00, 'quantity' => 1, 'expires_at' => '2026-10-25']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $line = Invoice::query()->whereHas('items', fn ($q) => $q->where('reference_id', $service->id))->sole()->items()->sole();
        $this->assertSame('18.00', $this->money($line->price));
        $this->assertStringEndsWith('(−10% client discount)', $line->description);
    }

    public function test_only_staff_allowed_manage_groups(): void
    {
        $this->actingAs(Staff::factory()->withPermissions(['admin.clients.viewAny'])->create())->get(ClientGroupResource::getUrl())->assertForbidden();
        $this->actingAs(Staff::factory()->withPermissions(['admin.client_groups.viewAny'])->create())->get(ClientGroupResource::getUrl())->assertOk();
    }
}
