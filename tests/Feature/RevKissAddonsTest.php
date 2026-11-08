<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Exceptions\DisplayException;
use App\Models\Credit;
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

    /** A yearly hosting paid 2026-01-01 → 2027-01-01 with a yearly «Extra IPv4» addon paid alike (today 2026-07-02). */
    private function parentWithAddon(): array
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $hosting = Product::factory()->create(['name' => 'Hosting']);
        $yearly = $this->plan($hosting, 'year', 120.00);
        $monthlyHosting = $this->plan($hosting, 'month', 12.00);
        $ip = Product::factory()->create(['name' => 'Extra IPv4', 'is_addon' => true]);
        $ipYearly = $this->plan($ip, 'year', 30.00);
        $ipMonthly = $this->plan($ip, 'month', 3.00);
        $parent = $this->service($hosting, $yearly, '2027-01-01', 120.00);
        $addon = $this->service($ip, $ipYearly, '2027-01-01', 30.00, ['parent_service_id' => $parent->id]);
        foreach ([[$parent, 120.00], [$addon, 30.00]] as [$service, $price]) {
            $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
            $this->line($invoice, $service, $price, '2026-01-01', '2027-01-01');
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        return [$parent, $addon, $monthlyHosting, $ipMonthly];
    }

    /**
     * REV-KISS-3 (medium). A cycle change «now» of a parent (staff, ChangeCycle::apply NOW) starts its new period today:
     * the parent is due on a new date and day, its addon kept the old one. Owner 2026-11-08: its live addons follow it to
     * its new billing day, on the same invoice, each credited and billed as the parent's own change.
     */
    public function test_rev_kiss_3_a_cycle_change_now_of_the_parent_does_not_leave_its_addon_on_another_date(): void
    {
        [$parent, $addon, $monthlyHosting] = $this->parentWithAddon();

        $invoice = ChangeCycle::apply($parent->fresh(), $monthlyHosting, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        // Its new monthly period, 2 Jul - 2 Aug, is on an invoice due today: the parent's billing day is now the 2nd.
        $lines = $invoice->items()->orderBy('id')->get();
        $this->assertSame([$parent->id, $addon->id], $lines->pluck('reference_id')->map(fn ($id) => (int) $id)->all(), 'one invoice, both');
        $this->assertSame(['2026-07-02', '2026-08-02', '12.00'], [$lines[0]->period_start->toDateString(), $lines[0]->period_end->toDateString(), number_format((float) $lines[0]->price, 2, '.', '')]);
        // The yearly addon to its parent's new day: 30.00 × 31 / 365, as «Add addon» prorates it.
        $this->assertSame(['2026-07-02', '2026-08-02', '2.55'], [$lines[1]->period_start->toDateString(), $lines[1]->period_end->toDateString(), number_format((float) $lines[1]->price, 2, '.', '')]);
        // The unused days of both: 120.00 × 183 / 365 + 30.00 × 183 / 365.
        $this->assertSame('75.20', number_format((float) Credit::query()->sole()->amount, 2, '.', ''));
        $addon->refresh();
        $this->assertSame([2, '2026-07-02'], [(int) $addon->billing_day, $addon->expires_at->toDateString()], 'on its parent\'s new day, its old period ended today');
        $this->assertSame('2026-07-02', InvoiceItem::query()->where('reference_id', $addon->id)->whereDate('period_start', '2026-01-01')->sole()->period_end->toDateString());
        $this->assertSame((int) $parent->fresh()->billing_day, (int) $addon->billing_day);
    }

    /** Paid (here by the credit), both renew to the same date, and their next renewal is one invoice again. */
    public function test_rev_kiss_3_paid_the_parent_and_its_addon_share_the_new_date(): void
    {
        config(['settings.credits_auto_use' => true]);
        [$parent, $addon, $monthlyHosting] = $this->parentWithAddon();

        $invoice = ChangeCycle::apply($parent->fresh(), $monthlyHosting, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('2026-08-02', $parent->fresh()->expires_at->toDateString());
        $this->assertSame('2026-08-02', $addon->fresh()->expires_at->toDateString(), 'its parent\'s date');
        $this->assertSame(2, (int) $addon->fresh()->billing_day, 'its payment keeps its parent\'s day');
    }

    /** One addon that could not be changed itself (a payment on its open invoice) refuses all of it, nothing moved. */
    public function test_rev_kiss_3_an_addon_that_cannot_follow_refuses_the_whole_change(): void
    {
        [$parent, $addon, $monthlyHosting] = $this->parentWithAddon();
        $open = Invoice::factory()->create(['client_id' => $addon->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $this->line($open, $addon, 5.00, '2026-06-01', '2026-07-01');

        try {
            ChangeCycle::apply($parent->fresh(), $monthlyHosting, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
            $this->fail('refused');
        } catch (DisplayException $e) {
            $this->assertStringContainsString("Addon #{$addon->id} follows this service", $e->getMessage());
        }
        $this->assertSame('2027-01-01', $parent->fresh()->expires_at->toDateString());
        $this->assertSame('2027-01-01', $addon->fresh()->expires_at->toDateString());
        $this->assertSame(0, Credit::query()->count());
    }

    /** A free addon has no money to move: only its date follows its parent's new period. */
    public function test_rev_kiss_3_a_free_addon_only_moves_its_date(): void
    {
        [$parent, $addon, $monthlyHosting] = $this->parentWithAddon();
        $free = Plan::factory()->create(['priceable_id' => $addon->product_id, 'priceable_type' => Product::class, 'name' => 'Free', 'type' => 'free',
            'billing_unit' => null, 'billing_period' => null]);
        Price::factory()->create(['plan_id' => $free->id, 'price' => 0, 'setup_fee' => 0, 'currency_code' => 'USD']);
        DB::table('services')->where('id', $addon->id)->update(['plan_id' => $free->id, 'price' => 0]);
        DB::table('invoice_items')->where('reference_id', $addon->id)->delete();

        $invoice = ChangeCycle::apply($parent->fresh(), $monthlyHosting, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame([$parent->id], $invoice->items()->pluck('reference_id')->map(fn ($id) => (int) $id)->all(), 'nothing billed for it');
        $this->assertSame(['2026-08-02', 2], [$addon->fresh()->expires_at->toDateString(), (int) $addon->fresh()->billing_day]);
    }

    /**
     * REV-KISS-4 (medium). The same on the addon itself: its cycle changed «now» (ChangeCycle::apply on the addon). Owner
     * 2026-11-08: not refused; its new period ends on its parent's billing day (AddAddon::alignedEnd), pro rata.
     */
    public function test_rev_kiss_4_a_cycle_change_now_of_an_addon_keeps_it_on_its_parents_date(): void
    {
        [$parent, $addon, , $ipMonthly] = $this->parentWithAddon();

        $invoice = ChangeCycle::apply($addon->fresh(), $ipMonthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $period = $invoice->items()->sole();
        $this->assertSame(AddAddon::alignedEnd($parent->fresh(), $ipMonthly, Carbon::today())?->toDateString(), $period->period_end->toDateString());
        // 2 Jul - 1 Aug of a 1 Jul - 1 Aug month: 3.00 × 30 / 31.
        $this->assertSame(['2026-07-02', '2026-08-01', '2.90'], [$period->period_start->toDateString(), $period->period_end->toDateString(), number_format((float) $period->price, 2, '.', '')]);
        $this->assertSame('15.04', number_format((float) Credit::query()->sole()->amount, 2, '.', ''), '30.00 × 183 / 365');
        $this->assertSame(1, (int) $addon->fresh()->billing_day, 'its parent\'s day, not today\'s');
        $this->assertSame('2027-01-01', $parent->fresh()->expires_at->toDateString(), 'the parent is not touched');
    }

    /** Paid, the addon's next renewal is on its parent's day too (its payment keeps its billing day). */
    public function test_rev_kiss_4_paid_the_addon_renews_on_its_parents_day(): void
    {
        config(['settings.credits_auto_use' => true]);
        [$parent, $addon, , $ipMonthly] = $this->parentWithAddon();

        ChangeCycle::apply($addon->fresh(), $ipMonthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $addon->refresh();
        $this->assertSame(['2026-08-01', 1], [$addon->expires_at->toDateString(), (int) $addon->billing_day]);
        $this->assertSame('2026-09-01', $addon->periodEnd(Carbon::parse('2026-08-01'))?->toDateString());
    }

    /** An addon of a parent with no due date ahead cannot end on its day: refused. */
    public function test_rev_kiss_4_refused_when_its_parent_has_no_due_date_ahead(): void
    {
        [$parent, $addon, , $ipMonthly] = $this->parentWithAddon();
        DB::table('services')->where('id', $parent->id)->update(['expires_at' => '2026-07-01']);

        $this->assertSame(__('services.addons.add.no_due_date'), ChangeCycle::refusal($addon->fresh(), $ipMonthly, ChangeCycle::NOW));
    }

    /**
     * Unsuspend (owner 2026-11-08). Staff unsuspend a parent past Suspend Days: its addon, suspended with it, stays
     * suspended «Overdue on Payment» only with an open invoice of its own; its period on its parent's invoice, or none
     * billed, comes back with its parent.
     */
    public function test_unsuspend_brings_back_an_addon_with_no_open_invoice_of_its_own(): void
    {
        Carbon::setTestNow('2026-12-20 09:00:00');
        $hosting = Product::factory()->create(['name' => 'Hosting']);
        $monthly = $this->plan($hosting, 'month', 10.00);
        $ip = Product::factory()->create(['name' => 'Extra IPv4', 'is_addon' => true]);
        $ipMonthly = $this->plan($ip, 'month', 3.00);
        $suspended = ['status' => Service::STATUS_SUSPENDED];
        $parent = $this->service($hosting, $monthly, '2026-12-01', 10.00, $suspended + ['suspend_reason' => 'Overdue on Payment']);
        $shared = $this->service($ip, $ipMonthly, '2026-12-01', 3.00, $suspended + ['parent_service_id' => $parent->id, 'suspend_reason' => Service::SUSPEND_REASON_PARENT]);
        $none = $this->service($ip, $ipMonthly, '2026-12-01', 3.00, $suspended + ['parent_service_id' => $parent->id, 'suspend_reason' => Service::SUSPEND_REASON_PARENT]);
        $own = $this->service($ip, $ipMonthly, '2026-12-01', 3.00, $suspended + ['parent_service_id' => $parent->id, 'suspend_reason' => Service::SUSPEND_REASON_PARENT]);
        $renewal = Invoice::factory()->create(['client_id' => $parent->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $this->line($renewal, $parent, 10.00, '2026-12-01', '2027-01-01');
        $this->line($renewal, $shared, 3.00, '2026-12-01', '2027-01-01');
        $apart = Invoice::factory()->create(['client_id' => $parent->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $this->line($apart, $own, 3.00, '2026-12-01', '2027-01-01');

        $parent->fresh()->update(['status' => Service::STATUS_ACTIVE]);

        $this->assertSame(Service::STATUS_ACTIVE, $shared->fresh()->status, 'its period is on its parent\'s invoice: back with it');
        $this->assertSame(Service::STATUS_ACTIVE, $none->fresh()->status, 'nothing billed while suspended with it: back with it');
        $this->assertSame([Service::STATUS_SUSPENDED, 'Overdue on Payment'], [$own->fresh()->status, $own->fresh()->suspend_reason], 'an open invoice of its own');
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
            $made = (new AddAddon)->handle($parent->fresh(), $ip->fresh(), $ipMonthly, 1, null, false, AddAddon::NO_INVOICE); // no date to give any more (was 2026-12-20)
        } catch (DisplayException) {
            return; // refused: fine
        }

        $this->assertSame('2026-12-01', $made['service']->fresh()->expires_at?->toDateString(), 'a recurring addon due on a day of its own');
    }
}
