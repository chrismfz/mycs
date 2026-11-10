<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Cancel;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\RenewalPrice;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sweep 7, client portal: adversarial verification of S7-CLIENT-PORTAL-1 and -2 (a «Requires» promotion kept without
 * the required product being bought). Both tests fail on main 44532e53. Fake data only.
 */
class Sweep7ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function product(float $price, string $name): Product
    {
        $product = Product::factory()->create(['name' => $name]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'year1',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function line(Product $product): OrderLine
    {
        return new OrderLine($product->fresh(), $product->plans()->firstOrFail()->load('prices'), 1, []);
    }

    /** 50% off B, «requires A». */
    private function coupon(Product $discounted, Product $required, ?int $recurring): Coupon
    {
        $coupon = Coupon::create(['code' => 'REQ' . uniqid(), 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => $recurring]);
        $coupon->products()->sync([$discounted->id]);
        $coupon->requiredProducts()->sync([$required->id]);

        return $coupon->fresh();
    }

    private function money($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * S7-CLIENT-PORTAL-1: A (100.00) + B (200.00) with «50% off B, requires A». The customer cancels the pending,
     * unpaid A from its page: only A's line leaves the order's invoice, B stays billed at 100.00 and is paid at that.
     */
    public function test_cp1_cancelling_the_pending_required_service_keeps_the_discount_on_the_orders_invoice(): void
    {
        $a = $this->product(100.00, 'Controller');
        $b = $this->product(200.00, 'Agent');
        $coupon = $this->coupon($b, $a, 1);
        $user = User::factory()->create();
        $placed = (new PlaceOrderService)->place($user->client(), 'USD', [$this->line($a), $this->line($b)], $coupon);
        $invoice = $placed->invoice;
        $serviceA = $placed->order->services()->where('product_id', $a->id)->sole();
        $serviceB = $placed->order->services()->where('product_id', $b->id)->sole();
        $this->assertSame('200.00', $this->money($invoice->fresh()->total), 'A 100.00 + B at half price');
        $this->assertSame(Service::STATUS_PENDING, $serviceA->status);

        $this->livewireAs($user)->test(Cancel::class, ['service' => $serviceA])
            ->set('type', 'immediate')->set('reason', 'changed my mind')->call('cancelService');

        $serviceA->refresh();
        $invoice->refresh();
        if ($serviceA->status === Service::STATUS_PENDING && $invoice->status === Invoice::STATUS_UNPAID
            && $this->money($invoice->total) === '200.00') {
            $this->assertTrue(true, 'refused: the order stands as placed');

            return;
        }
        $this->assertSame(Service::STATUS_CANCELLED, $serviceA->status);
        $open = in_array($invoice->status, Invoice::OPEN_STATUSES, true);
        $this->assertFalse($open && $this->money($invoice->total) === '100.00',
            'the required A is cancelled unpaid, and the order\'s invoice still sells B at half price (100.00 of 200.00)');
    }

    /**
     * S7-CLIENT-PORTAL-2: B runs with «50% off every cycle, requires A». A is cancelled: B renews at 200.00. An order
     * of A that is never paid (pending service, unpaid invoice) brings the renewal back to 100.00.
     */
    public function test_cp2_an_unpaid_order_of_the_required_product_keeps_the_renewal_discount(): void
    {
        $a = $this->product(100.00, 'Controller');
        $b = $this->product(200.00, 'Agent');
        $coupon = $this->coupon($b, $a, 0);
        $user = User::factory()->create();
        $client = $user->client();
        $placed = (new PlaceOrderService)->place($client, 'USD', [$this->line($a), $this->line($b)], $coupon);
        ExtensionHelper::addPayment($placed->invoice->id, null, 200.00, null, 'pay-1', currency: 'USD');
        $serviceA = $placed->order->services()->where('product_id', $a->id)->sole();
        $serviceB = $placed->order->services()->where('product_id', $b->id)->sole();
        $this->assertSame(Service::STATUS_ACTIVE, $serviceB->fresh()->status);
        $start = CarbonImmutable::parse($serviceB->fresh()->expires_at);
        $this->assertSame('100.00', $this->money(RenewalPrice::for($serviceB->fresh(), $start, false)->price), 'A held: half price');

        $this->livewireAs($user)->test(Cancel::class, ['service' => $serviceA->fresh()])
            ->set('type', 'immediate')->set('reason', 'not needed')->call('cancelService');
        $this->assertSame(Service::STATUS_CANCELLED, $serviceA->fresh()->status);
        $this->assertSame('200.00', $this->money(RenewalPrice::for($serviceB->fresh(), $start, false)->price), 'A gone: full price');

        // A again in the cart, checked out, never paid.
        $again = (new PlaceOrderService)->place($client, 'USD', [$this->line($a)], null);
        $this->assertSame(Invoice::STATUS_UNPAID, $again->invoice->fresh()->status);
        $this->assertSame(Service::STATUS_PENDING, $again->order->services()->sole()->status);

        $this->assertSame('200.00', $this->money(RenewalPrice::for($serviceB->fresh(), $start, false)->price),
            'an order of A that was never paid does not earn the discount on B\'s renewal');
    }
}
