<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sweep 10, client portal: adversarial verification of S10-CLIENT-PORTAL-1 (a «Requires» promotion kept without the
 * required product being bought, through «Do not charge automatically» instead of the cancellation that
 * S7-CLIENT-PORTAL-1 closed). Fails on main f9f145bb. Fake data only.
 */
class Sweep10ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
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

    private function money($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * S10-CLIENT-PORTAL-1: A (100.00) + B (60.00) with «50% off B, requires A»: one unpaid invoice of 130.00, both
     * pending. The customer's login calls toggleAutoCharge on the pending B (the page does not ask offersChoice, as the
     * gateway select does): B's 30.00 line leaves for an invoice of its own, which is paid alone, and B starts at half
     * price while A was never bought.
     */
    public function test_cp1_do_not_charge_automatically_on_a_pending_service_takes_its_discounted_line_off_the_unpaid_order(): void
    {
        $a = $this->product(100.00, 'Controller');
        $b = $this->product(60.00, 'Agent');
        $coupon = Coupon::create(['code' => 'REQ' . uniqid(), 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 1]);
        $coupon->products()->sync([$b->id]);
        $coupon->requiredProducts()->sync([$a->id]);
        $user = User::factory()->create();
        $placed = (new PlaceOrderService)->place($user->client(), 'USD', [$this->line($a), $this->line($b)], $coupon->fresh());
        $invoice = $placed->invoice;
        $serviceA = $placed->order->services()->where('product_id', $a->id)->sole();
        $serviceB = $placed->order->services()->where('product_id', $b->id)->sole();
        $this->assertSame('130.00', $this->money($invoice->fresh()->total), 'A 100.00 + B at half price');
        $this->assertSame([Service::STATUS_PENDING, Service::STATUS_PENDING], [$serviceA->status, $serviceB->status]);
        $this->assertTrue($serviceB->orderUnpaid());

        $this->livewireAs($user)->test(ServiceShow::class, ['service' => $serviceB])->call('toggleAutoCharge');

        $linesOfB = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $serviceB->id)->pluck('invoice_id')->unique()->values();
        $this->assertSame([$invoice->id], $linesOfB->all(),
            'the discounted B left the unpaid order\'s invoice for one of its own: it can be paid without the required A');
        $this->assertSame([Invoice::STATUS_UNPAID, '130.00', 2], [$invoice->fresh()->status, $this->money($invoice->fresh()->total), $invoice->items()->count()],
            'the order stands as placed');
    }

    /** The same, to the end: the invoice of B alone is paid, B runs at half price, A is still pending on an unpaid invoice. */
    public function test_cp1_the_discounted_service_is_then_paid_alone_and_starts_without_the_required_one(): void
    {
        $a = $this->product(100.00, 'Controller');
        $b = $this->product(60.00, 'Agent');
        $coupon = Coupon::create(['code' => 'REQ' . uniqid(), 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 1]);
        $coupon->products()->sync([$b->id]);
        $coupon->requiredProducts()->sync([$a->id]);
        $user = User::factory()->create();
        $placed = (new PlaceOrderService)->place($user->client(), 'USD', [$this->line($a), $this->line($b)], $coupon->fresh());
        $serviceA = $placed->order->services()->where('product_id', $a->id)->sole();
        $serviceB = $placed->order->services()->where('product_id', $b->id)->sole();

        $this->livewireAs($user)->test(ServiceShow::class, ['service' => $serviceB])->call('toggleAutoCharge');

        $own = Invoice::query()->whereKey(InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $serviceB->id)->value('invoice_id'))->sole();
        if ($own->id !== $placed->invoice->id) {
            ExtensionHelper::addPayment($own->id, null, (float) $own->total, null, 'pay-b', currency: 'USD');
        }
        $serviceA->refresh();
        $serviceB->refresh();
        $this->assertFalse($serviceB->status === Service::STATUS_ACTIVE && $serviceA->status === Service::STATUS_PENDING
            && $placed->invoice->fresh()->status === Invoice::STATUS_UNPAID,
            'B runs, paid ' . $this->money($own->total) . ' of 60.00, while the required A is pending on an unpaid invoice');
    }
}
