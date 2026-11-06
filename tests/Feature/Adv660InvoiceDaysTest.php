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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #660 (WG-14: a monthly plan's own invoice days, cronjob_invoice_monthly). The cron, the Cancel
 * rule (InvoiceObserver::givePeriodsUp, onlyDue) and the Cancel toggle's default (Invoice::billsOnlyPeriodsToCome) must
 * agree on which days a renewal was billed with: a renewal the cron billed is the cron's own (Δ3a: cancelled, given up),
 * never «a period to come» billed again. Fake data only.
 */
class Adv660InvoiceDaysTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A monthly service (createProduct's plan) and, of the same product, an annual plan. @return array{Service, Plan, Plan} */
    private function service(string $expires, string $on = 'month'): array
    {
        $product = $this->createProduct();
        $monthly = $product->plan;
        $annual = Plan::factory()->create(['priceable_id' => $product->product->id, 'priceable_type' => $product->product::class, 'name' => 'Annually',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $annual->id, 'price' => 100.00, 'setup_fee' => 0, 'currency_code' => 'USD']);
        $plan = $on === 'month' ? $monthly : $annual;
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => $on === 'month' ? 10.00 : 100.00, 'quantity' => 1]);

        return [$service, $monthly, $annual];
    }

    /** @return Collection<int, InvoiceItem> */
    private function lines(Service $service)
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->orderBy('id')->get();
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /**
     * ADV-660-1a. A change of cycle «from the next renewal» (next_plan_id, ChangeCycle), annual → monthly, with Invoice
     * Generation 14 and the monthly 10. The cron takes the days of the plan the service is on now (annual, 14), and
     * RenewalBilling::billRenewal then swaps to the next plan and bills a monthly period 12 days ahead: a monthly renewal
     * billed before its own 10 days. The days must be those of the plan the period is billed with.
     */
    public function test_a_change_to_monthly_at_renewal_is_billed_by_the_monthly_days(): void
    {
        $this->storeSettings(['cronjob_invoice' => '14', 'cronjob_invoice_monthly' => '10']);
        [$service, $monthly] = $this->service('2026-12-13', on: 'year');
        $service->forceFill(['next_plan_id' => $monthly->id])->save();

        $this->cron();

        $this->assertCount(0, $this->lines($service), 'a monthly period 12 days ahead: its own days (10) not reached yet');
    }

    /**
     * ADV-660-1b. The other way, monthly → annual with the monthly value larger (30) than Invoice Generation (14): the cron
     * uses the monthly 30 and bills the annual period 20 days ahead, where an annual plan's renewal is invoiced 14 days
     * before.
     */
    public function test_a_change_to_annual_at_renewal_is_billed_by_the_general_days(): void
    {
        $this->storeSettings(['cronjob_invoice' => '14', 'cronjob_invoice_monthly' => '30']);
        [$service, , $annual] = $this->service('2026-12-21');
        $service->forceFill(['next_plan_id' => $annual->id])->save();

        $this->cron();

        $this->assertCount(0, $this->lines($service), 'an annual period 20 days ahead: Invoice Generation (14) not reached yet');
    }

    /**
     * ADV-660-1c (end to end, the money consequence). Same as 1a: the cron bills the monthly period 12 days ahead. That is
     * the cron's own renewal, so the Cancel toggle starts off (A4-1c) and a cancel with nobody choosing gives the period up
     * (Δ3a). Judged by the monthly days it is «a period to come»: kept billable, and the cron bills the same period again
     * two days later on a new invoice, after it was cancelled.
     */
    public function test_a_cron_renewal_billed_across_a_change_of_cycle_is_not_billed_again_once_cancelled(): void
    {
        $this->storeSettings(['cronjob_invoice' => '14', 'cronjob_invoice_monthly' => '10']);
        [$service, $monthly] = $this->service('2026-12-13', on: 'year');
        $service->forceFill(['next_plan_id' => $monthly->id])->save();
        // Author's note (fix of #660): with 1a fixed the cron bills it by the monthly 10 days, on 12-03, not on 12-01; the
        // scenario then: that renewal cancelled, and the cron runs again.
        $this->cron();
        $this->assertCount(0, $this->lines($service), '12-01: its monthly 10 days not reached (1a)');
        Carbon::setTestNow('2026-12-03 10:00:00');
        $this->cron();
        $line = $this->lines($service)->first();
        $this->assertNotNull($line, 'precondition: the cron billed it by the monthly days');
        $invoice = $line->invoice;

        $toCome = $invoice->billsOnlyPeriodsToCome();

        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'customer not renewing']);
        Carbon::setTestNow('2026-12-05 10:00:00');
        $this->cron();

        $this->assertCount(1, $this->lines($service), 'the cancelled cron renewal is given up (Δ3a), never billed again');
        $this->assertFalse($toCome, 'the cron billed it: not a period to come, the Cancel toggle starts off (A4-1c)');
    }

    /**
     * ADV-660-2. A monthly renewal the cron billed under Invoice Generation's 14 days (before the monthly value was set,
     * e.g. the days after #659 ran, or staff set it later), cancelled after the monthly 10 is set: judged by today's
     * setting it is «a period to come», kept billable, and billed again on a new invoice. What decides should be how the
     * line was billed (the cron's renewal), not the setting of the day it is cancelled.
     */
    public function test_a_monthly_cron_renewal_made_before_the_monthly_days_is_given_up_when_cancelled(): void
    {
        $this->storeSettings(['cronjob_invoice' => '14']);
        [$service] = $this->service('2026-12-13');
        $this->cron();
        $line = $this->lines($service)->first();
        $this->assertNotNull($line, 'precondition: billed 12 days ahead under the general 14');

        $this->storeSettings(['cronjob_invoice_monthly' => '10']);
        $line->invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'customer not renewing']);
        Carbon::setTestNow('2026-12-03 10:00:00');
        $this->cron();

        $this->assertCount(1, $this->lines($service), 'the cancelled cron renewal is given up (Δ3a), never billed again');
    }
}
