<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 3, lens l-rounding-tax (adversarial verifier). L-ROUNDING-TAX-1: a plan change on a pending pro rata service
 * reprices its unpaid first-order line as a plain renewal: the setup fee and the pro rata stub are lost.
 */
class Sweep3LRoundingTaxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    public function test_a_plan_change_on_a_pending_pro_rata_order_keeps_its_first_line(): void
    {
        $user = User::factory()->create();
        /** @var Product $product */
        $product = $this->createProduct(['prorata_day' => 1, 'prorata_next_after_day' => 20])->product;
        $plan = $product->plans()->firstOrFail();
        Price::query()->where('plan_id', $plan->id)->update(['price' => 30.00, 'setup_fee' => 5.00]);
        // Another monthly plan of the same product, at the same price: only the plan row changes.
        $other = Plan::factory()->create([
            'priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Other Plan',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring',
        ]);
        Price::factory()->create(['plan_id' => $other->id, 'price' => 30.00, 'setup_fee' => 5.00, 'currency_code' => 'USD']);

        Carbon::setTestNow('2026-01-10 10:00:00');
        $placed = (new PlaceOrderService)->place($user->client(), 'USD', [new OrderLine($product, $plan)]);
        $line = $placed->invoice->fresh('items')->items->sole();
        // 22 of 31 days of 30.00 = 21.29, and the setup fee 5.00.
        $this->assertSame(['26.29', '2026-02-01'], [$this->money($line->price), $line->period_end->toDateString()]);

        $service = Service::query()->sole();
        $this->assertSame(Service::STATUS_PENDING, $service->status);
        $service->update(['plan_id' => $other->id]);

        $line->refresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $line->invoice->status);
        $this->assertSame(
            ['price' => '26.29', 'setup_fee' => '5.00', 'period_end' => '2026-02-01'],
            ['price' => $this->money($line->price), 'setup_fee' => $line->setup_fee === null ? null : $this->money($line->setup_fee),
                'period_end' => $line->period_end->toDateString()],
            'the first order line (pro rata stub + setup fee, ending on the pro rata day) is repriced as a full renewal',
        );
    }
}
