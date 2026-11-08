<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use App\Services\Service\AddAddon;
use App\Services\ServiceUpgrade\ChangeCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Independent review (code + adversarial) of «an addon always shares its parent's due date» (KISS, owner 2026-11-08).
 * Each test fails on the branch as reviewed: a way an addon still ends up due on another day than its parent (then billed
 * on its own invoices again, the case the rule removed), or a legitimate addon the importer refuses.
 * Fake data only.
 */
class RevKissAddonsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-01-09 09:00:00');
        Queue::fake();
        Mail::fake();
        config(['settings.cronjob_order_suspend' => 8, 'settings.cronjob_order_terminate' => 60, 'settings.cronjob_terminate_min_suspended_days' => 1,
            'settings.cronjob_invoice' => 7, 'settings.cronjob_suspend_requires_invoice' => false,
            'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_terminate_enabled' => true,
            'settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(Product $product, string $unit, float $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => ucfirst($unit) . 'ly',
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    private function service(Product $product, Plan $plan, string $expires, float $price, array $attributes = []): Service
    {
        return Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $plan->id, 'product_id' => $product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1, ...$attributes,
        ]);
    }

    private function line(Invoice $invoice, Service $service, float $price, string $start, string $end): InvoiceItem
    {
        return $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => $price, 'period_start' => $start, 'period_end' => $end]);
    }

    private function cronUntil(string $last): void
    {
        while (Carbon::today()->lessThanOrEqualTo(Carbon::parse($last))) {
            $this->artisan('app:cron-job')->assertExitCode(0);
            Carbon::setTestNow(Carbon::now()->addDay());
        }
    }

    /**
     * REV-KISS-2 (medium). The importer refuses a monthly WHMCS addon of an annual service whose next due date is on the
     * service's billing day (2026-12-18 under 2027-05-18): the very date MyCS gives such an addon itself (alignedEnd), so
     * not «due on another day». The runbook's fix, «nextduedate = the parent's», would give a monthly addon five months free
     * (the branch's own test aligns #162, Monthly, to 2027-05-18). Expected: only an addon off its service's billing day
     * (k of its own cycles back from the service's date) is refused.
     */
    public function test_rev_kiss_2_the_importer_accepts_a_monthly_addon_on_its_annual_services_billing_day(): void
    {
        $whmcs = new \PDO('sqlite::memory:');
        $whmcs->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, billingcycle TEXT, nextduedate TEXT)');
        $whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT, status TEXT, billingcycle TEXT, nextduedate TEXT)');
        $whmcs->exec("INSERT INTO tblhosting VALUES (2, 'Annually', '2027-05-18')");
        $whmcs->exec("INSERT INTO tblhostingaddons VALUES (162, 2, 'Active', 'Monthly', '2026-12-18'), (165, 2, 'Active', 'Monthly', '2026-12-03')");
        $command = app(ImportFromWhmcs::class);
        (new \ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);

        $this->assertSame([165], (new \ReflectionMethod($command, 'addonsApart'))->invoke($command),
            '#162 is on its service\'s billing day (a month of its own cycle), only #165 is off it');
    }

    /**
     * REV-KISS-3 (medium). A cycle change «now» of a parent (staff, ChangeCycle::apply NOW) starts its new period today:
     * the parent is due on a new date and day, its addon keeps the old one. An addon due on another day than its parent is
     * made after all, with no refusal and nothing told to staff, and is then billed apart and suspended with its parent
     * unbilled. Expected: refused while the service has live addons, or the addons follow it.
     */
    public function test_rev_kiss_3_a_cycle_change_now_of_the_parent_does_not_leave_its_addon_on_another_date(): void
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $hosting = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($hosting, 'year', 120.00);
        $monthlyHosting = $this->plan($hosting, 'month', 12.00);
        $ip = Product::factory()->create(['name' => 'Extra IPv4', 'is_addon' => true]);
        $ipYearly = $this->plan($ip, 'year', 30.00);
        $parent = $this->service($hosting, $yearly, '2027-01-01', 120.00);
        $addon = $this->service($ip, $ipYearly, '2027-01-01', 30.00, ['parent_service_id' => $parent->id]);
        foreach ([[$parent, 120.00], [$addon, 30.00]] as [$service, $price]) {
            $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
            $this->line($invoice, $service, $price, '2026-01-01', '2027-01-01');
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        try {
            ChangeCycle::apply($parent->fresh(), $monthlyHosting, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
        } catch (DisplayException) {
            $this->assertSame('2027-01-01', $addon->fresh()->expires_at->toDateString());

            return; // refused: fine
        }

        // Its new monthly period, 2 Jul - 2 Aug, is on an invoice due today: the parent's billing day is now the 2nd.
        $parent->refresh();
        $period = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $parent->id)->latest('id')->first();
        $this->assertSame('2026-08-02', $period->period_end->toDateString(), 'precondition: its new period');
        $this->assertSame($period->period_end->day, $addon->fresh()->expires_at->day,
            "the parent now renews on day {$period->period_end->day} of the month, its addon still on {$addon->fresh()->expires_at->toDateString()}");
    }

    /**
     * REV-KISS-4 (medium). The same on the addon itself: its cycle changed «now» (ChangeCycle::apply on the addon), its
     * new period starts today, so it is due on today's day, not its parent's: an addon «from its own date» by another door.
     * Expected: refused for an addon, or its period ends on its parent's billing day.
     */
    public function test_rev_kiss_4_a_cycle_change_now_of_an_addon_keeps_it_on_its_parents_date(): void
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $hosting = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($hosting, 'year', 120.00);
        $ip = Product::factory()->create(['name' => 'Extra IPv4', 'is_addon' => true]);
        $ipYearly = $this->plan($ip, 'year', 30.00);
        $ipMonthly = $this->plan($ip, 'month', 3.00);
        $parent = $this->service($hosting, $yearly, '2027-01-01', 120.00);
        $addon = $this->service($ip, $ipYearly, '2027-01-01', 30.00, ['parent_service_id' => $parent->id]);
        $invoice = Invoice::factory()->create(['client_id' => $addon->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $this->line($invoice, $addon, 30.00, '2026-01-01', '2027-01-01');
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);

        try {
            ChangeCycle::apply($addon->fresh(), $ipMonthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
        } catch (DisplayException) {
            return; // refused for an addon: fine
        }

        $period = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $addon->id)->latest('id')->first();
        $this->assertSame(AddAddon::alignedEnd($parent->fresh(), $ipMonthly, Carbon::today())?->toDateString(), $period->period_end->toDateString(),
            'the addon\'s new period runs from today to the 2nd, not to its parent\'s billing day (the 1st)');
    }

    /**
     * REV-KISS-5 (low; the spec kept «no invoice» as today, but it is the one way left in «Add addon» to the case the rule
     * removes). «No invoice» for a recurring addon takes any future date: 2026-12-20 under a parent due 2027-01-01 is an
     * addon due on its own date, which then renews on its own invoice and, suspended with its parent, is not billed
     * Expected: refused, or due on its parent's (aligned) date.
     */
    public function test_rev_kiss_5_no_invoice_does_not_set_a_recurring_addon_on_its_own_date(): void
    {
        Carbon::setTestNow('2026-11-20 09:00:00');
        $hosting = Product::factory()->create(['name' => 'Hosting']);
        $monthly = $this->plan($hosting, 'month', 10.00);
        $ip = Product::factory()->create(['name' => 'Extra IPv4', 'is_addon' => true, 'server_id' => null, 'stock' => null]);
        $ipMonthly = $this->plan($ip, 'month', 3.00);
        $parent = $this->service($hosting, $monthly, '2026-12-01', 10.00);

        try {
            $made = (new AddAddon)->handle($parent->fresh(), $ip->fresh(), $ipMonthly, 1, null, false, AddAddon::NO_INVOICE, Carbon::parse('2026-12-20'));
        } catch (DisplayException) {
            return; // refused: fine
        }

        $this->assertSame('2026-12-01', $made['service']->fresh()->expires_at?->toDateString(), 'a recurring addon due on a day of its own');
    }
}
