<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Reports\ClientIncome;
use App\Services\Reports\GrowthChurn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Second independent review of #910 (Growth and churn, services.ended_at). Each test fails on e867b80d.
 */
class GrowthChurnReview2Test extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-17 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR']);
        $this->client = User::factory()->create()->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name = 'Hosting'): Product
    {
        $category = Category::query()->firstOrCreate(['slug' => 'hosting'], ['name' => 'Hosting']);

        return Product::factory()->create(['name' => $name, 'category_id' => $category->id]);
    }

    private function plan(Product $product, string $unit = 'month', int $period = 1, string $type = 'recurring'): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'p',
            'billing_unit' => $unit, 'billing_period' => $period, 'type' => $type]);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 1, 'setup_fee' => 0, 'currency_code' => 'EUR']);

        return $plan;
    }

    private function service(Product $product, string $price, string $started, ?Plan $plan = null, array $extra = []): Service
    {
        return Service::factory()->create($extra + ['client_id' => $this->client->id, 'product_id' => $product->id,
            'plan_id' => ($plan ?? $this->plan($product))->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR',
            'price' => $price, 'quantity' => 1, 'expires_at' => '2026-12-01', 'activated_at' => $started]);
    }

    /**
     * A1: a service imported from WHMCS as Terminated with no termination_date (ended_at empty: «ended, not known when»).
     * Staff later change it to Cancelled (or Fraud→Cancelled, a tidy-up). markEnd sees the status dirty and ended_at null
     * and stamps now(): a service that ended in 2024 becomes a loss of this month, and app:import-whmcs-end-dates (which
     * fills only empty ones) can no longer correct it. The migration's backfill does the same from such an audit.
     */
    public function test_an_ended_service_of_unknown_end_moved_to_another_ended_status_stays_unknown(): void
    {
        $service = $this->service($this->product(), '10.00', '2023-01-01 09:00:00');
        DB::table('services')->where('id', $service->id)->update(['status' => Service::STATUS_TERMINATED, 'ended_at' => null]);

        Carbon::setTestNow('2026-10-05 12:00:00');
        $service->refresh()->update(['status' => Service::STATUS_CANCELLED]);
        Carbon::setTestNow('2026-11-17 10:00:00');

        $this->assertNull($service->refresh()->ended_at, 'it ended long ago, not on the day staff changed its status');
        $this->assertSame(0, GrowthChurn::year('EUR', 2026)['months'][9]['cancelled_count'], 'no loss in October 2026');
    }

    /**
     * A2: an addon terminated on its own (unpaid) in 2025, while its parent ran on. In 2026 the parent's customer asks
     * to cancel at the end of the period. The addon has no request, so lines() takes its parent's: its 2025 loss turns
     * from «terminated» into «cancelled», with the parent's 2026 reason. The 2025 report changes after the fact.
     */
    public function test_an_addon_that_ended_alone_does_not_take_a_later_request_of_its_parent(): void
    {
        $parent = $this->service($this->product(), '20.00', '2024-01-01 09:00:00');
        $addon = $this->service($this->product('Extra IP'), '3.00', '2024-01-01 09:00:00', null, ['parent_service_id' => $parent->id]);
        Carbon::setTestNow('2025-04-10 12:00:00');
        $addon->refresh()->update(['status' => Service::STATUS_TERMINATED]);
        Carbon::setTestNow('2026-11-17 10:00:00');
        $this->assertSame(Service::STATUS_ACTIVE, $parent->refresh()->status);
        ServiceCancellation::withoutEvents(fn () => ServiceCancellation::create(['service_id' => $parent->id, 'reason' => 'Moving elsewhere', 'type' => 'end_of_period']));

        $april = GrowthChurn::year('EUR', 2025)['months'][3];
        $this->assertSame(1, $april['terminated_count'], 'it was terminated, unpaid, in April 2025');
        $this->assertSame(0, $april['cancelled_count']);
    }

    /**
     * A3: an imported WHMCS service with an invalid regdate gets activated_at = the import time (ImportFromWhmcs: «?? now()»),
     * while its termination_date says it ended in 2024. lines() clamps the end to the start, so a service gone for two years
     * shows as a new service AND a termination in the cut-over month (counts inflated on both sides).
     */
    public function test_a_service_that_ended_before_it_was_activated_is_not_a_new_service_of_the_import_month(): void
    {
        $service = $this->service($this->product(), '10.00', '2026-11-13 02:00:00');
        DB::table('services')->where('id', $service->id)->update(['status' => Service::STATUS_TERMINATED, 'ended_at' => '2024-05-01 00:00:00']);

        $november = GrowthChurn::year('EUR', 2026)['months'][10];
        $this->assertSame(0, $november['new_count'], 'it never started in November 2026');
        $this->assertSame(0, $november['terminated_count'], 'it ended in 2024');
    }

    /**
     * A4: a service on a monthly plan (10/month) upgraded to the yearly plan of another product (120/year). The old monthly
     * plan, now used by nothing (Plan::inUse looks only at services.plan_id and upgrades.plan_id), is deleted:
     * from_plan_id is nulled (nullOnDelete) and lines() prices the 10 of before at the NEW plan's cycle, 10/12 = 0.83/month.
     */
    public function test_the_state_before_an_upgrade_keeps_its_cycle_when_its_old_plan_is_deleted(): void
    {
        $small = $this->product('Small');
        $monthly = $this->plan($small);
        $big = $this->product('Big');
        $yearly = $this->plan($big, 'year');
        $service = $this->service($big, '120.00', '2026-02-01 09:00:00', $yearly);
        DB::table('service_upgrades')->insert(['service_id' => $service->id, 'product_id' => $big->id, 'plan_id' => $yearly->id,
            'status' => ServiceUpgrade::STATUS_COMPLETED, 'from_product_id' => $small->id, 'from_plan_id' => $monthly->id,
            'from_price' => '10.00', 'applied_at' => '2026-06-01 12:00:00', 'created_at' => '2026-06-01 12:00:00', 'updated_at' => '2026-06-01 12:00:00']);

        $this->assertFalse($monthly->inUse(), 'staff may delete it');
        $monthly->delete();

        $report = GrowthChurn::year('EUR', 2026);
        $this->assertSame(1000, $report['months'][1]['new'], 'it started at 10 a month');
    }

    /**
     * A5: a free service cancelled with a request: the losses leave it out (worth 0), but «Why they left» counts it, so
     * the reasons' counts do not add up to the services lost shown above them.
     */
    public function test_the_reasons_count_the_same_services_as_the_losses(): void
    {
        $product = $this->product();
        $free = $this->service($product, '0.00', '2025-01-01 09:00:00', $this->plan($product, 'month', 1, 'free'));
        ServiceCancellation::withoutEvents(fn () => ServiceCancellation::create(['service_id' => $free->id, 'reason' => 'Not needed', 'type' => 'immediate']));
        Carbon::setTestNow('2026-03-10 12:00:00');
        $free->refresh()->update(['status' => Service::STATUS_CANCELLED]);
        Carbon::setTestNow('2026-11-17 10:00:00');

        $report = GrowthChurn::year('EUR', 2026);
        $lost = $report['totals']['cancelled_count'] + $report['totals']['terminated_count'];
        $this->assertSame($lost, array_sum(array_column($report['reasons'], 'count')));
    }

    /**
     * A6: «New clients» is said to be ClientIncome's «new» (their first payment), but firstPaid() takes any paid invoice:
     * a customer whose only paid invoice is a 0.00 one (a free order) is a new client here, never in Income by client.
     */
    public function test_new_clients_match_income_by_client(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-03-05']);
        $invoice->items()->create(['description' => 'Free plan', 'quantity' => 1, 'price' => 0, 'tax_rate' => '0']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => '2026-03-05 10:00:00']);

        $this->assertSame(0, ClientIncome::year('EUR', 2026)['new']['count']);
        $this->assertSame(ClientIncome::year('EUR', 2026)['new']['count'], GrowthChurn::year('EUR', 2026)['totals']['clients']);
    }
}
