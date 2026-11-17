<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Models\Category;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Staff;
use App\Models\User;
use App\Services\Reports\GrowthChurn;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Adversarial review of #910 (Growth and churn, services.ended_at). Each test fails on the PR as it is.
 */
class GrowthChurnReviewTest extends TestCase
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

    private function plan(Product $product): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'p',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 1, 'setup_fee' => 0, 'currency_code' => 'EUR']);

        return $plan;
    }

    private function service(Product $product, string $price, string $started, array $extra = []): Service
    {
        return Service::factory()->create($extra + ['client_id' => $this->client->id, 'product_id' => $product->id,
            'plan_id' => $this->plan($product)->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR',
            'price' => $price, 'quantity' => 1, 'expires_at' => '2026-12-01', 'activated_at' => $started]);
    }

    private function admin(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);
    }

    /**
     * R1: the Ended On DatePicker has no time. Staff open an ended service and save it for anything else (a note): its
     * ended_at, stamped with a time by the observer, is rewritten to that day's midnight. An upgrade applied that day
     * before the end then falls after the end and drops out of the service's history.
     */
    public function test_saving_the_form_of_an_ended_service_keeps_its_end_time(): void
    {
        $service = $this->service($this->product(), '10.00', '2025-01-01 09:00:00');
        Carbon::setTestNow('2026-05-03 18:30:00');
        $service->update(['status' => Service::STATUS_TERMINATED]);
        Carbon::setTestNow('2026-11-17 10:00:00');
        $this->assertSame('2026-05-03 18:30:00', $service->refresh()->ended_at->format('Y-m-d H:i:s'));

        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['notes' => 'looked at it'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('2026-05-03 18:30:00', $service->refresh()->ended_at->format('Y-m-d H:i:s'), 'an untouched end keeps its time');
    }

    /**
     * R2: a parent cancelled at the customer's immediate request ends its addons with it (AddonCascade::end). The addon
     * has no request of its own (copies are made only for end_of_period), so the report gives its loss as «cancelled by
     * staff, no cancellation request», not the customer's reason.
     */
    public function test_an_addon_ended_with_its_parent_by_the_customers_immediate_request_carries_its_reason(): void
    {
        $product = $this->product();
        $parent = $this->service($product, '20.00', '2025-01-01 09:00:00');
        $addon = $this->service($this->product('Extra IP'), '3.00', '2025-01-01 09:00:00', ['parent_service_id' => $parent->id]);
        ServiceCancellation::withoutEvents(fn () => ServiceCancellation::create(['service_id' => $parent->id, 'reason' => 'Moving elsewhere', 'type' => 'immediate']));
        Carbon::setTestNow('2026-09-10 12:00:00');
        // As CancellationCreatedListener does for an immediate request.
        $parent->refresh()->update(['status' => Service::STATUS_CANCELLED]);
        Carbon::setTestNow('2026-11-17 10:00:00');
        $this->assertSame(Service::STATUS_CANCELLED, $addon->refresh()->status, 'the addon ended with it');

        $reasons = collect(GrowthChurn::year('EUR', 2026)['reasons'])->keyBy('key');
        $this->assertArrayNotHasKey(GrowthChurn::CANCELLED_BY_STAFF, $reasons->all(), 'nobody on staff cancelled anything');
        $this->assertSame(2, $reasons['text:moving elsewhere']['count'] ?? 0, 'the parent and its addon left for the customer\'s reason');
    }

    /**
     * R3: in the running year the last-year column of the running month is the whole month a year before, while the
     * footer's last-year total is to the same day: the column does not add up to its own total.
     */
    public function test_the_last_year_column_adds_up_to_its_total_in_the_running_year(): void
    {
        $product = $this->product();
        // Started 2025-11-25: after «today» a year before (2025-11-17), within November 2025.
        $this->service($product, '10.00', '2025-11-25 09:00:00');

        $report = GrowthChurn::year('EUR', 2026);
        $this->assertTrue($report['current']);
        $column = array_sum(array_map(fn (array $row) => $row['last_year']['net'], $report['months']));
        $this->assertSame($report['last_totals']['net'], $column, 'Net last year: the months shown and their total read the same days');
    }

    /**
     * R4: the migration's backfill takes the LAST audit that set the status the service has now. One terminated in
     * January and changed to Cancelled by staff in March ended in January (the observer keeps the first end, «kept from
     * one ended status to the other»); the backfill says March.
     */
    public function test_the_backfill_keeps_the_first_end_as_the_observer_does(): void
    {
        $service = $this->service($this->product(), '10.00', '2025-01-01 09:00:00');
        DB::table('services')->where('id', $service->id)->update(['status' => Service::STATUS_CANCELLED]);
        // As the auditing package writes them: the old status beside the new (review 2 of #910, A1: only a move from
        // running to ended is an end).
        foreach ([['active', 'terminated', '2026-01-10 03:00:00'], ['terminated', 'cancelled', '2026-03-05 11:00:00']] as [$was, $status, $at]) {
            DB::table('audits')->insert(['event' => 'updated', 'auditable_type' => Service::class, 'auditable_id' => $service->id,
                'old_values' => json_encode(['status' => $was]), 'new_values' => json_encode(['status' => $status]), 'created_at' => $at, 'updated_at' => $at]);
        }
        Schema::table('services', fn ($table) => $table->dropColumn('ended_at'));
        (require base_path('database/migrations/2027_03_09_000000_add_ended_at_to_services.php'))->up();

        $this->assertSame('2026-01-10 03:00:00', (string) DB::table('services')->where('id', $service->id)->value('ended_at'));
    }
}
