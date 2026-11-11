<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial sweep of the upgrades area (2026-11-07). Each test fails on main for the reason named in its docblock.
 * No tax anywhere: the amounts are the net ones.
 */
class SweepUpgradesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $this->storeSettings(['credits_enabled' => true, 'credits_on_downgrade' => true, 'credits_auto_use' => false,
            'tax_enabled' => false, 'default_currency' => 'USD']);
        config(['settings.credits_enabled' => true, 'settings.credits_on_downgrade' => true, 'settings.credits_auto_use' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, ?int $stock = null): Product
    {
        return Product::factory()->create(['name' => $name, 'server_id' => null, 'stock' => $stock]);
    }

    private function plan(Product $product, string $unit, string $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => ucfirst($unit) . 'ly',
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    private function service(Product $product, Plan $plan, string $price, string $expires, array $attributes = []): Service
    {
        return Service::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id,
            'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => $price, 'quantity' => 1, 'currency_code' => 'USD',
            'status' => Service::STATUS_ACTIVE, 'expires_at' => $expires]);
    }

    private function paidLine(Service $service, string $price, ?string $start, ?string $end, string $type = Service::class, ?int $ref = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Line', 'price' => $price, 'quantity' => 1, 'reference_id' => $ref ?? $service->id,
            'reference_type' => $type, 'period_start' => $start, 'period_end' => $end]);
        // As the importer writes it (the observer lets only a recorded payment mark one paid).
        $this->statusFixture((int) $invoice->id, Invoice::STATUS_PAID);

        return $invoice;
    }

    /**
     * UPGRADES-1 (a): a yearly 120.00 paid for 2026, downgraded on 07-02 to a 60.00 product: 30.08 comes back as credit (the
     * customer keeps the 183 days at 60.16 − 30.08). Changing the cycle «now» the same day then credits the unused days
     * again at what the line was paid (60.16), as if the downgrade had given nothing: 90.24 credited for 60.16 left.
     */
    public function test_upgrades_1a_a_cycle_change_after_a_downgrade_does_not_credit_the_same_days_twice(): void
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $a = $this->product('A');
        $aYear = $this->plan($a, 'year', '120.00');
        $b = $this->product('B');
        $this->plan($b, 'year', '60.00');
        $bMonth = $this->plan($b, 'month', '5.00');
        $service = $this->service($a, $aYear, '120.00', '2027-01-01');
        $this->paidLine($service, '120.00', '2026-01-01', '2027-01-01');

        $down = PlaceUpgrade::handle($service->fresh(), $b->fresh(), [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::APPLIED, $down->outcome);
        $this->assertSame(3008, $down->creditCents, 'the downgrade gave back 30.08');

        $service = $service->fresh();
        $this->assertNull(ChangeCycle::refusal($service, $bMonth, ChangeCycle::NOW));
        $credit = ChangeCycle::quote($service, $bMonth, ChangeCycle::NOW)['credit'];

        // 60.16 was left of what was paid; the downgrade gave 30.08 of it already.
        $this->assertLessThanOrEqual(30.08, (float) $credit, "the cycle change credits {$credit} on top of the downgrade's 30.08");
    }

    /**
     * UPGRADES-1 (b): the other way round. 120.00 paid for 2026; on 07-02 an upgrade to 240.00 is paid (60.16 for the 183
     * days left). A cycle change «now» the same day credits only the period line's 60.16: the 60.16 just paid for the
     * same days is lost (DowngradeCredit::parts counts it; ChangeCycle::unused does not).
     */
    public function test_upgrades_1b_a_cycle_change_after_a_paid_upgrade_credits_what_the_upgrade_paid_too(): void
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $a = $this->product('A');
        $aYear = $this->plan($a, 'year', '120.00');
        $c = $this->product('C');
        $this->plan($c, 'year', '240.00');
        $cMonth = $this->plan($c, 'month', '20.00');
        $service = $this->service($a, $aYear, '120.00', '2027-01-01');
        $this->paidLine($service, '120.00', '2026-01-01', '2027-01-01');

        $up = PlaceUpgrade::handle($service->fresh(), $c->fresh(), [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::INVOICED, $up->outcome);
        $due = number_format((float) $up->invoice->fresh()->total, 2, '.', '');
        $this->assertSame('60.16', $due);
        (new RecordPaymentService)->handle($up->invoice->fresh(), null, $due, transactionId: 'sweep-up-1b');
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $up->upgrade->fresh()->status);

        $service = $service->fresh();
        $this->assertSame((int) $c->id, (int) $service->product_id);
        $this->assertNull(ChangeCycle::refusal($service, $cMonth, ChangeCycle::NOW));
        $credit = ChangeCycle::quote($service, $cMonth, ChangeCycle::NOW)['credit'];

        $this->assertGreaterThanOrEqual(120.32, (float) $credit, "120.32 was paid for the 183 days left; the change credits {$credit}");
    }

    /**
     * UPGRADES-2: an upgrade paid 50.00 on 2025-12-01, in the 2025 period. The 2026 period was paid 120.00 (its line kept the
     * old price). A downgrade on 2026-07-02 to the 120.00 product: what is left of the 2026 money (60.16) is exactly what
     * the days left cost on the new product, so nothing is owed. DowngradeCredit spreads December's upgrade to the
     * expiry 2027-01-01 (no live line holds 2025-12-01) and gives 23.11 of it back.
     */
    public function test_upgrades_2_an_upgrade_of_an_earlier_period_gives_no_credit_on_a_downgrade(): void
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $a = $this->product('A');
        $this->plan($a, 'year', '120.00');
        $c = $this->product('C');
        $cYear = $this->plan($c, 'year', '240.00');
        $service = $this->service($c, $cYear, '240.00', '2027-01-01');
        $this->paidLine($service, '120.00', '2025-01-01', '2026-01-01');
        $this->paidLine($service, '120.00', '2026-01-01', '2027-01-01');
        $old = ServiceUpgrade::query()->create(['service_id' => $service->id, 'product_id' => $c->id, 'plan_id' => $cYear->id,
            'status' => ServiceUpgrade::STATUS_COMPLETED]);
        DB::table('service_upgrades')->where('id', $old->id)->update(['created_at' => '2025-12-01 10:00:00']);
        $this->paidLine($service, '50.00', null, null, ServiceUpgrade::class, $old->id);

        $down = PlaceUpgrade::handle($service->fresh(), $a->fresh(), [], ServiceUpgrade::SOURCE_STAFF);

        $this->assertSame(PlaceUpgrade::APPLIED, $down->outcome);
        $this->assertSame(0, $down->creditCents, 'December 2025\'s upgrade money came back as credit in July 2026');
    }

    /**
     * UPGRADES-3: an upgrade paid after a renewal paid ahead bills the rest on an invoice of its own (billShortfall). The
     * service is then terminated: its open invoices are cancelled, but that one names the (completed) upgrade, not the
     * service, and stays open, reminded for a service that ended.
     */
    public function test_upgrades_3_the_shortfall_invoice_is_cancelled_when_the_service_ends(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $small = $this->product('Small');
        $smallMonth = $this->plan($small, 'month', '10.00');
        $large = $this->product('Large');
        $this->plan($large, 'month', '30.00');
        $service = $this->service($small, $smallMonth, '10.00', '2026-07-31');
        $this->paidLine($service, '10.00', '2026-06-30', '2026-07-31');

        $up = PlaceUpgrade::handle($service->fresh(), $large->fresh(), [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::INVOICED, $up->outcome);
        // A renewal paid before the upgrade: a month more at the old price.
        $this->paidLine($service, '10.00', '2026-07-31', '2026-08-31');
        DB::table('services')->where('id', $service->id)->update(['expires_at' => '2026-08-31']);
        (new RecordPaymentService)->handle($up->invoice->fresh(), null, number_format((float) $up->invoice->fresh()->total, 2, '.', ''),
            transactionId: 'sweep-up-3');
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $up->upgrade->fresh()->status);
        $shortfall = Invoice::query()->whereKeyNot($up->invoice_id ?? $up->invoice->id)->where('status', Invoice::STATUS_UNPAID)
            ->whereHas('items', fn ($q) => $q->where('reference_type', ServiceUpgrade::class))->sole();

        $service->fresh()->markTerminated();

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $shortfall->fresh()->status, 'the shortfall invoice outlives the terminated service');
    }

    /**
     * UPGRADES-4: expiry 2026-11-10, upgrade placed on 11-07: its invoice was due 11-10. The cron on 11-10 (03:00) gives the
     * upgrade up (expires_at, a date at 00:00, is already past): the customer paying on the due date shown finds it cancelled.
     * Fixed by the due date, the day before the expiry (corrected by the fixer: the test asserted the due date stays on the
     * expiry day, a fix in the cron; on that day the upgrade prices no days left, so the cron is right to give it up).
     */
    public function test_upgrades_4_an_upgrade_is_still_payable_on_its_invoice_due_date(): void
    {
        Carbon::setTestNow('2026-11-07 10:00:00');
        $small = $this->product('Small');
        $smallMonth = $this->plan($small, 'month', '10.00');
        $large = $this->product('Large');
        $this->plan($large, 'month', '30.00');
        $service = $this->service($small, $smallMonth, '10.00', '2026-11-10');
        $this->paidLine($service, '10.00', '2026-10-10', '2026-11-10');

        $up = PlaceUpgrade::handle($service->fresh(), $large->fresh(), [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::INVOICED, $up->outcome);
        $due = CarbonImmutable::instance($up->invoice->fresh()->due_at)->toDateString();
        $this->assertSame('2026-11-09', $due, 'due the last day the upgrade still prices');

        Carbon::setTestNow($due . ' 03:00:00');
        // The step of the cron that gives up an upgrade whose period ended (CronJob::refreshUpgrade).
        $cron = app(CronJob::class);
        $method = new \ReflectionMethod($cron, 'refreshUpgrade');
        DB::transaction(fn () => $method->invoke($cron, (int) $up->upgrade->id));

        $this->assertSame(ServiceUpgrade::STATUS_PENDING, $up->upgrade->fresh()->status, 'cancelled on the due date its invoice shows');
    }

    /**
     * UPGRADES-5: a staff-locked price, moved to another product: the price becomes the catalog's (a staff price is not
     * carried over), but price_locked stays on, so the catalog's later changes (group discount) skip it.
     */
    public function test_upgrades_5_a_product_change_to_the_catalog_price_clears_the_price_lock(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $this->storeSettings(['credits_on_downgrade' => false]);
        config(['settings.credits_on_downgrade' => false]);
        $big = $this->product('Big');
        $bigMonth = $this->plan($big, 'month', '30.00');
        $small = $this->product('Small');
        $this->plan($small, 'month', '10.00');
        $service = $this->service($big, $bigMonth, '25.00', '2026-07-31', ['price_locked' => true]);
        $this->paidLine($service, '25.00', '2026-06-30', '2026-07-31');

        $down = PlaceUpgrade::handle($service->fresh(), $small->fresh(), [], ServiceUpgrade::SOURCE_STAFF);

        $this->assertSame(PlaceUpgrade::APPLIED, $down->outcome);
        $service = $service->fresh();
        $this->assertSame('10.00', number_format((float) $service->price, 2, '.', ''), 'the catalog price now');
        $this->assertFalse((bool) $service->price_locked, 'a catalog price still marked as staff\'s');
    }

    /**
     * UPGRADES-6: the target has one left in stock. Two services each place an upgrade to it (nothing re-checks stock when
     * placing) and both are paid: both are applied and the stock goes to -1.
     */
    public function test_upgrades_6_upgrades_never_take_more_than_the_stock(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');
        $small = $this->product('Small');
        $smallMonth = $this->plan($small, 'month', '10.00');
        $large = $this->product('Large', 1);
        $this->plan($large, 'month', '30.00');
        $results = [];
        foreach (['one', 'two'] as $name) {
            $service = $this->service($small, $smallMonth, '10.00', '2026-07-31');
            $this->paidLine($service, '10.00', '2026-06-30', '2026-07-31');
            $results[$name] = PlaceUpgrade::handle($service->fresh(), $large->fresh(), [], ServiceUpgrade::SOURCE_STAFF);
        }
        foreach ($results as $name => $result) {
            if ($result->outcome === PlaceUpgrade::INVOICED) {
                (new RecordPaymentService)->handle($result->invoice->fresh(), null,
                    number_format((float) $result->invoice->fresh()->total, 2, '.', ''), transactionId: "sweep-up-6-{$name}");
            }
        }

        $this->assertGreaterThanOrEqual(0, (int) $large->fresh()->stock, 'stock below zero');
    }
}
