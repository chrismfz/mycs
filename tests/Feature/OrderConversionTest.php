<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Support\OrderConversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * WG-13: a placed order's GA4/Google Ads `purchase`, as myip's WHMCS hook sent it on «order complete»: the «Order complete
 * code» setting on the first page after the order, once, its placeholders from the invoice (value net, tax apart). Never for
 * a free order, nor for another account's order, nor for staff acting as the customer. Fake data only.
 */
class OrderConversionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
        $this->storeSettings(['theme_order_complete_code' => '<script>window.purchases = (window.purchases || []).concat([{purchase}]); window.v = {value}; window.c = {currency};</script>']);
    }

    private function order(float $price, string $name = 'Hosting Basic'): Order
    {
        $product = $this->createProduct()->product;
        $product->forceFill(['name' => $name])->save();
        $plan = $product->plans()->first();
        $plan->prices()->update(['price' => $price]);

        return (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $plan->fresh())])->order;
    }

    /** The dashboard as the customer, with this session. */
    private function page(array $session = []): string
    {
        return $this->actingAs($this->user)->withSession($this->loginUser($this->user, $session))->get(route('dashboard'))->assertOk()->getContent();
    }

    public function test_the_code_runs_once_on_the_page_after_the_order_with_its_purchase(): void
    {
        $order = $this->order(10.00);

        $page = $this->page([OrderConversion::SESSION_KEY => $order->id]);

        $payload = OrderConversion::payload($order);
        $this->assertNotNull($payload);
        $this->assertSame([(string) $order->id, 10.0, 0.0, 'USD'], [$payload['transaction_id'], $payload['value'], $payload['tax'], $payload['currency']]);
        $this->assertSame('P' . $order->services()->first()->product_id, $payload['items'][0]['item_id']);
        $this->assertStringContainsString('window.purchases = (window.purchases || []).concat([{"transaction_id":"' . $order->id . '","value":10.0', $page);
        $this->assertStringContainsString('window.v = 10.0; window.c = "USD";', $page);

        $this->assertStringNotContainsString('window.purchases', $this->page(), 'once per order');
    }

    public function test_never_for_a_free_order_nor_another_accounts(): void
    {
        $free = $this->order(0.00);
        $this->assertStringNotContainsString('window.purchases', $this->page([OrderConversion::SESSION_KEY => $free->id]), 'nothing to pay: not a sale');

        $other = User::factory()->create();
        $theirs = (new PlaceOrderService)->place($other->client(), 'USD', [new OrderLine($product = $this->createProduct()->product, $product->plans()->first())])->order;
        $this->assertStringNotContainsString('window.purchases', $this->page([OrderConversion::SESSION_KEY => $theirs->id]), 'another account\'s order: never');
    }

    public function test_a_name_never_breaks_out_of_the_script(): void
    {
        $order = $this->order(10.00, '</script><script>alert(1)</script>');

        $page = $this->page([OrderConversion::SESSION_KEY => $order->id]);

        $this->assertStringNotContainsString('<script>alert(1)', $page);
        $this->assertStringContainsString(trim((string) json_encode('</script>', JSON_HEX_TAG), '"'), $page, 'its < and > escaped');
    }
}
