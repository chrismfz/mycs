<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * WG-14 (owner 2026-11-05): a monthly plan's renewal invoiced its own days before (cronjob_invoice_monthly, myip 10 of
 * WHMCS's «Monthly»), every other cycle Invoice Generation's (myip 14): 14 days before a month's renewal is half the month,
 * and a customer who pays on the suspension day got the next invoice the following day. Fake data only.
 */
class InvoiceDaysMonthlyTest extends TestCase
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

    private function service(string $unit, string $expires = '2026-12-15'): Service
    {
        $product = $this->createProduct();
        $plan = $product->plan;
        if ($unit === 'year') {
            $plan = Plan::factory()->create(['priceable_id' => $product->product->id, 'priceable_type' => $product->product::class, 'name' => 'Annually',
                'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
            Price::factory()->create(['plan_id' => $plan->id, 'price' => 100.00, 'setup_fee' => 0, 'currency_code' => 'USD']);
        }

        return Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => $unit === 'year' ? 100.00 : 10.00, 'quantity' => 1]);
    }

    private function billed(Service $service): bool
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->exists();
    }

    public function test_a_monthly_plan_is_invoiced_its_own_days_before_every_other_cycle_the_general(): void
    {
        $monthly = $this->service('month');
        $annual = $this->service('year');
        $this->assertSame([10, 14], [RenewalBilling::serviceInvoiceDays($monthly->plan), RenewalBilling::serviceInvoiceDays($annual->plan)]);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertTrue($this->billed($annual), '14 days before: the annual one');
        $this->assertFalse($this->billed($monthly), 'not yet: a month\'s renewal 10 days before');

        Carbon::setTestNow('2026-12-05 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertTrue($this->billed($monthly), '10 days before its due date');
    }

    public function test_without_a_monthly_value_every_cycle_is_invoiced_as_invoice_generation_says(): void
    {
        $this->storeSettings(['cronjob_invoice_monthly' => '']);
        $monthly = $this->service('month');

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertTrue($this->billed($monthly), 'empty: as Invoice Generation, 14 days before');
    }

    /** An invoice cancelled ahead of the cron: a monthly line its own days have not reached stays billable (Δ3, ADV-4). */
    public function test_a_monthly_line_the_cron_has_not_reached_is_a_period_to_come(): void
    {
        $monthly = $this->service('month');
        $annual = $this->service('year');
        $invoice = fn (Service $service) => tap(Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD']),
            fn (Invoice $invoice) => $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
                'quantity' => 1, 'price' => $service->price, 'period_start' => '2026-12-15', 'period_end' => '2027-01-15']));

        $this->assertTrue($invoice($monthly)->billsOnlyPeriodsToCome(), 'a month\'s, 14 days ahead: its own 10 not reached');
        $this->assertFalse($invoice($annual)->billsOnlyPeriodsToCome(), 'an annual one, 14 days ahead: reached');
    }
}
