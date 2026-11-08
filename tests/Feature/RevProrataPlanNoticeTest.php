<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of «Staff notice on a plan change of a pending pro rata service» (REV3-L-ROUNDING-TAX-2): staff
 * change the plan of a pending service whose order line is a pro rata first period; the line keeps its price and staff
 * are told what the new plan's would be.
 */
class RevProrataPlanNoticeTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECT = '%unpaid pro rata order%';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Config::set('audit.console', true);
        $this->storeSettings(['credits_enabled' => true, 'credits_auto_use' => false, 'default_currency' => 'USD',
            'system_email_address' => 'staff@example.test', 'mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{Product, Plan} a pro rata product (day 1) at 30.00 + 5.00 setup a month */
    private function catalog(): array
    {
        /** @var Product $product */
        $product = $this->createProduct(['prorata_day' => 1, 'prorata_next_after_day' => 20])->product;
        $plan = $product->plans()->firstOrFail();
        Price::query()->where('plan_id', $plan->id)->update(['price' => 30.00, 'setup_fee' => 5.00]);

        return [$product, $plan];
    }

    private function plan(Product $product, string $name, float $price, float $setup, string $type = 'recurring'): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => $name,
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => $type]);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => $setup, 'currency_code' => 'USD']);

        return $plan;
    }

    /** @return array{Service, InvoiceItem} */
    private function order(Product $product, Plan $plan): array
    {
        Carbon::setTestNow('2026-01-10 10:00:00');
        $placed = (new PlaceOrderService)->place(User::factory()->create()->client(), 'USD', [new OrderLine($product, $plan)]);

        return [Service::query()->sole(), $placed->invoice->fresh('items')->items->sole()];
    }

    private function notices()
    {
        return EmailLog::query()->where('to', 'staff@example.test')->where('subject', 'like', self::SUBJECT)->orderBy('id')->get();
    }

    /**
     * Finding 1: a plan change to a one-time (or free) plan of the same product, confirmed by staff («ends billing»).
     * refreshOpenPeriods returns before the order line (its plan is one-time), so the line keeps the old recurring plan's
     * pro rata stub (26.29, period to 01/02) and staff are told nothing: the case the spec asks a notice for.
     */
    public function test_a_change_to_a_one_time_plan_tells_staff_of_the_kept_pro_rata_line(): void
    {
        [$product, $plan] = $this->catalog();
        $once = $this->plan($product, 'Once', 100.00, 0.00, 'one-time');
        [$service, $line] = $this->order($product, $plan);
        $this->assertSame('26.29', number_format((float) $line->price, 2, '.', ''));

        $service->refresh();
        $service->applyStaffChanges(['plan_id' => $once->id], $service->getAttributes(), endsBilling: true);

        $this->assertSame((int) $once->id, (int) $service->fresh()->plan_id, 'precondition: the plan changed');
        $this->assertSame('26.29', number_format((float) $line->fresh()->price, 2, '.', ''), 'the order line kept the old plan\'s stub');
        $this->assertCount(1, $this->notices(), 'staff are told the order line still has the old plan\'s pro rata price');
    }

    /**
     * Finding 2: the new plan differs in its setup fee only (30.00 a month, no setup fee). The order line keeps 26.29
     * (21.29 + the old plan's 5.00 setup fee); the new plan's order would be 21.29. The notice prices the new plan with
     * the setup fee «as billed», finds the same 26.29 and tells nothing.
     */
    public function test_a_plan_with_another_setup_fee_is_told_at_the_new_plans_setup_fee(): void
    {
        [$product, $plan] = $this->catalog();
        $noSetup = $this->plan($product, 'No setup', 30.00, 0.00);
        [$service, $line] = $this->order($product, $plan);

        $service->refresh();
        $service->applyStaffChanges(['plan_id' => $noSetup->id], $service->getAttributes());

        $this->assertSame('26.29', number_format((float) $line->fresh()->price, 2, '.', ''));
        $notices = $this->notices();
        $this->assertCount(1, $notices, 'the new plan\'s first invoice would be 21.29, not the 26.29 the line keeps');
        $this->assertStringContainsString('it would be 21.29 USD', (string) $notices->sole()->body);
    }

    /**
     * Finding 2b: both differ. Old 30.00 + 5.00 setup, new 40.00 + 15.00 setup: the new plan's order would be 28.39 +
     * 15.00 = 43.39; the notice says 33.39 (the old setup fee kept).
     */
    public function test_the_notice_prices_the_new_plan_with_its_own_setup_fee(): void
    {
        [$product, $plan] = $this->catalog();
        $dearSetup = $this->plan($product, 'Dear setup', 40.00, 15.00);
        [$service] = $this->order($product, $plan);

        $service->refresh();
        $service->applyStaffChanges(['plan_id' => $dearSetup->id], $service->getAttributes());

        $this->assertStringContainsString('it would be 43.39 USD', (string) $this->notices()->sole()->body);
    }
}
