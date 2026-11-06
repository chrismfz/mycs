<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Review of #660 (WG-14): a monthly plan's own invoice days. Fake data only.
 */
class Review660Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->user = User::factory()->create();
        $this->storeSettings(['cronjob_invoice' => '14', 'cronjob_invoice_monthly' => '10']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Service, 1: Plan, 2: Plan} a service, its monthly plan and an annual plan of its product */
    private function service(string $current, string $expires = '2026-12-15'): array
    {
        $product = $this->createProduct();
        $monthly = $product->plan;
        $annual = Plan::factory()->create(['priceable_id' => $product->product->id, 'priceable_type' => $product->product::class, 'name' => 'Annually',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $annual->id, 'price' => 100.00, 'setup_fee' => 0, 'currency_code' => 'USD']);
        $plan = $current === 'year' ? $annual : $monthly;
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => $current === 'year' ? 100.00 : 10.00, 'quantity' => 1]);

        return [$service, $monthly, $annual];
    }

    private function billed(Service $service): bool
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->exists();
    }

    /**
     * R660-1: a change of cycle «from the next renewal» (next_plan) to monthly. The period the cron bills is the monthly
     * plan's (billRenewal switches to it before billing), but the cron's billUntil is the current annual plan's 14 days:
     * the first monthly period is invoiced 14 days ahead, the «half the month» WG-14 is about. The invoice then reads as
     * a «period to come» by the very plan it bills (the observer and billsOnlyPeriodsToCome judge by the plan now).
     */
    public function test_r660_1_a_change_to_monthly_at_renewal_is_invoiced_by_the_monthly_days(): void
    {
        [$service, $monthly] = $this->service('year');
        $service->forceFill(['next_plan_id' => $monthly->id])->save();

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertFalse($this->billed($service), 'the period billed is a month\'s: its 10 days, not the annual plan\'s 14');
    }

    /** R660-1, the other way: monthly to annual at renewal, the year's invoice comes only the month's 10 days before. */
    public function test_r660_1b_a_change_to_annual_at_renewal_is_invoiced_by_the_annual_days(): void
    {
        [$service, , $annual] = $this->service('month');
        $service->forceFill(['next_plan_id' => $annual->id])->save();

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertTrue($this->billed($service), 'the period billed is a year\'s: Invoice Generation\'s 14 days');
        $this->assertSame($annual->id, (int) $service->refresh()->plan_id);
    }
}
