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
use App\Models\Setting;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Import\WhmcsAddonImport;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyTrail;
use App\Services\Service\AddAddon;
use App\Services\ServiceUpgrade\ChangeCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Owner 2026-11-09 (KISS, docs/addons.md): an addon has its parent's due date and its parent's billing cycle, and follows
 * it everywhere. «Add addon» takes the parent's cycle, an addon never changes its own, a parent's change (now or from the
 * next renewal) carries its addons, and the importer refuses a live addon on another cycle. Fixes sweep 6's
 * S6-PLAN-CYCLE-ADDONS-4. Fake data only.
 */
class Sweep6AddonsFollowCycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'],
            'default_currency' => ['USD', 'string'], 'cronjob_invoice' => [7, 'integer']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $values): void
    {
        foreach ($values as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    private function plan(Product $product, string $unit, float $price, int $count = 1): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => $count . ' ' . $unit,
            'billing_unit' => $unit, 'billing_period' => $count, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    private function service(Product $product, Plan $plan, float $price, string $start, string $end, array $attributes = []): Service
    {
        $service = Service::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id,
            'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => $price, 'quantity' => 1,
            'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $end]);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => $price, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);

        return $service->fresh();
    }

    /**
     * A monthly server (20.00, or 200.00 a year, 380.00 for two) due 2026-12-01, and «Extra IPv4» (5.00 a month, 50.00 a
     * year; no two-year price), a monthly addon of it due the same day.
     *
     * @return array{parent: Service, addon: Service, extra: Product, monthly: Plan, yearly: Plan, twoYears: Plan, extraMonthly: Plan, extraYearly: Plan}
     */
    private function serverWithAddon(): array
    {
        $host = Product::factory()->create(['name' => 'Server']);
        $monthly = $this->plan($host, 'month', 20.00);
        $yearly = $this->plan($host, 'year', 200.00);
        $twoYears = $this->plan($host, 'year', 380.00, 2);
        $extra = Product::factory()->create(['name' => 'Extra IPv4', 'is_addon' => true, 'stock' => null]);
        $extraMonthly = $this->plan($extra, 'month', 5.00);
        $extraYearly = $this->plan($extra, 'year', 50.00);
        $parent = $this->service($host, $monthly, 20.00, '2026-11-01', '2026-12-01');
        $addon = $this->service($extra, $extraMonthly, 5.00, '2026-11-01', '2026-12-01',
            ['client_id' => $parent->client_id, 'parent_service_id' => $parent->id]);

        return compact('parent', 'addon', 'extra', 'monthly', 'yearly', 'twoYears', 'extraMonthly', 'extraYearly');
    }

    private function refused(callable $change): string
    {
        try {
            $change();
        } catch (DisplayException $e) {
            return $e->getMessage();
        }
        $this->fail('It was not refused.');
    }

    /** «Add addon» has no cycle to choose: the addon is on its product's plan of the parent's cycle, prorated to its date. */
    public function test_add_addon_takes_the_plan_of_its_parents_cycle(): void
    {
        ['parent' => $parent, 'extra' => $extra, 'extraMonthly' => $extraMonthly, 'yearly' => $yearly, 'extraYearly' => $extraYearly] = $this->serverWithAddon();
        $this->assertSame($extraMonthly->id, AddAddon::plan($extra->fresh(), $parent)?->id);

        $made = (new AddAddon)->handle($parent, $extra, 1, null, false, AddAddon::PRORATED);

        $this->assertSame($extraMonthly->id, (int) $made['service']->plan_id);
        $line = $made['invoice']->items()->sole();
        $this->assertSame(['2026-11-10', '2026-12-01', '3.50'], [$line->period_start->toDateString(), $line->period_end->toDateString(),
            number_format((float) $line->price, 2, '.', '')], '5.00 × 21 of November\'s 30 days, to its parent\'s date');

        // Of a yearly parent: its yearly plan, to the parent's date (never a month of its own inside the parent's year).
        $server = $this->service($parent->product, $yearly, 200.00, '2026-05-01', '2027-05-01');
        $made = (new AddAddon)->handle($server, $extra, 1, null, false, AddAddon::NO_INVOICE);
        $this->assertSame([$extraYearly->id, '2027-05-01', '50.00'], [(int) $made['service']->plan_id, $made['service']->expires_at->toDateString(),
            number_format((float) $made['service']->price, 2, '.', '')]);
    }

    /** The addon's product has no price for the parent's cycle: refused with what to do, nothing made. */
    public function test_add_addon_is_refused_when_the_addon_has_no_plan_of_its_parents_cycle(): void
    {
        ['parent' => $parent, 'extra' => $extra, 'twoYears' => $twoYears] = $this->serverWithAddon();
        $server = $this->service($parent->product, $twoYears, 380.00, '2026-05-01', '2028-05-01');
        $before = Service::query()->count();

        $this->assertNull(AddAddon::plan($extra->fresh(), $server));
        $why = $this->refused(fn () => (new AddAddon)->handle($server, $extra, 1, null, false, AddAddon::PRORATED));

        $this->assertSame(__('services.addons.add.no_cycle_plan', ['addon' => 'Extra IPv4', 'cycle' => $twoYears->name]), $why);
        $this->assertSame([$before, 0], [Service::query()->count(), Invoice::query()->where('client_id', $server->client_id)->where('status', Invoice::STATUS_UNPAID)->count()]);
        // Listed all the same, so staff see why (and add the price in the catalogue).
        $this->assertTrue(AddAddon::products($server)->contains('id', $extra->id));
    }

    /** A free addon product (no recurring plan) has no cycle: added as before, whatever its parent's. */
    public function test_a_free_addon_is_added_whatever_the_cycle(): void
    {
        ['parent' => $parent] = $this->serverWithAddon();
        $gift = Product::factory()->create(['name' => 'Gift', 'is_addon' => true, 'stock' => null]);
        $free = Plan::factory()->create(['priceable_id' => $gift->id, 'priceable_type' => Product::class, 'name' => 'Free', 'type' => 'free',
            'billing_unit' => null, 'billing_period' => null]);

        $made = (new AddAddon)->handle($parent, $gift, 1, null, false, AddAddon::NO_INVOICE);

        $this->assertSame([$free->id, null, Service::STATUS_ACTIVE], [(int) $made['service']->plan_id, $made['service']->expires_at, $made['service']->status]);
    }

    /**
     * S6-PLAN-CYCLE-ADDONS-4. The finding's test set a monthly addon of a yearly parent to yearly from its next renewal and
     * expected its period to end on the parent's date. Rewritten to the rule that fixes it (owner 2026-11-09): an addon is
     * on its parent's cycle, so its own change is refused, now and from the next renewal, and nothing is set.
     */
    public function test_an_addons_cycle_change_from_the_next_renewal_keeps_its_parents_due_date(): void
    {
        ['addon' => $addon, 'extraYearly' => $extraYearly] = $this->serverWithAddon();

        foreach ([ChangeCycle::NEXT_RENEWAL, ChangeCycle::NOW] as $mode) {
            $this->assertSame(__('services.addons.cycle.follows_parent'), ChangeCycle::refusal($addon, $extraYearly, $mode));
            $this->assertSame(__('services.addons.cycle.follows_parent'),
                $this->refused(fn () => ChangeCycle::apply($addon, $extraYearly, $mode, MoneyTrail::SOURCE_SYSTEM)));
        }
        $this->assertTrue(ChangeCycle::plans($addon)->isEmpty(), 'not offered: the staff action and the customer\'s button show on plans()');
        $this->assertNotSame(__('services.addons.cycle.follows_parent', [], 'en'), __('services.addons.cycle.follows_parent', [], 'el'));

        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->artisan('app:cron-job');

        $addon->refresh();
        $line = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $addon->id)->whereDate('period_start', '2026-12-01')->sole();
        $parentLine = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $addon->parent_service_id)->whereDate('period_start', '2026-12-01')->sole();
        $this->assertSame([null, '2027-01-01', (int) $parentLine->invoice_id], [$addon->next_plan_id, $line->period_end->toDateString(), (int) $line->invoice_id],
            'its monthly renewal, with its parent\'s, to its parent\'s next due date');
    }

    /**
     * The parent's change «from the next renewal» sets the same on its live addons: at that renewal both switch, on one
     * invoice, to the same new due date.
     */
    public function test_a_parents_change_from_the_next_renewal_carries_its_addons(): void
    {
        ['parent' => $parent, 'addon' => $addon, 'yearly' => $yearly, 'extraYearly' => $extraYearly] = $this->serverWithAddon();

        $quote = ChangeCycle::quote($parent, $yearly, ChangeCycle::NEXT_RENEWAL);
        $this->assertSame('50.00', number_format((float) $quote['addons'][$addon->id]['line'], 2, '.', ''));
        $this->assertNull(ChangeCycle::apply($parent, $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM));

        $this->assertSame([$yearly->id, $extraYearly->id], [(int) $parent->fresh()->next_plan_id, (int) $addon->fresh()->next_plan_id]);
        $this->assertSame($extraYearly->id, RenewalBilling::billedPlan($addon->fresh())?->id);

        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->artisan('app:cron-job');

        $lines = InvoiceItem::query()->where('reference_type', Service::class)->whereIn('reference_id', [$parent->id, $addon->id])
            ->whereDate('period_start', '2026-12-01')->orderBy('reference_id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame(1, $lines->pluck('invoice_id')->unique()->count(), 'one invoice');
        $this->assertSame([['2027-12-01', '200.00'], ['2027-12-01', '50.00']],
            $lines->map(fn (InvoiceItem $line) => [$line->period_end->toDateString(), number_format((float) $line->price, 2, '.', '')])->all());
        $this->assertSame([$yearly->id, null, $extraYearly->id, null],
            [(int) $parent->fresh()->plan_id, $parent->fresh()->next_plan_id, (int) $addon->fresh()->plan_id, $addon->fresh()->next_plan_id]);

        $invoice = $lines->first()->invoice->fresh();
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->remaining, transactionId: 's6-follow-1');
        $this->assertSame(['2027-12-01', '2027-12-01'], [$parent->fresh()->expires_at->toDateString(), $addon->fresh()->expires_at->toDateString()]);
    }

    /** Their open renewal of the old cycle (one invoice, both lines) is cancelled whole, and billed again in the new one. */
    public function test_the_open_renewal_of_both_is_cancelled_for_the_new_cycle(): void
    {
        ['parent' => $parent, 'addon' => $addon, 'yearly' => $yearly] = $this->serverWithAddon();
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->artisan('app:cron-job');
        $old = InvoiceItem::query()->where('reference_id', $addon->id)->whereDate('period_start', '2026-12-01')->sole()->invoice;

        ChangeCycle::apply($parent->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
        $this->assertSame(Invoice::STATUS_CANCELLED, $old->fresh()->status);

        $this->artisan('app:cron-job');
        $lines = InvoiceItem::query()->whereIn('reference_id', [$parent->id, $addon->id])->whereDate('period_start', '2026-12-01')
            ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_UNPAID))->get();
        $this->assertSame(['2027-12-01', '2027-12-01'], $lines->map(fn (InvoiceItem $line) => $line->period_end->toDateString())->all());
        $this->assertSame(1, $lines->pluck('invoice_id')->unique()->count());
    }

    /** Taking the parent's pending change back takes the addons' back; another cycle changes theirs. */
    public function test_clearing_or_changing_the_parents_pending_change_does_the_same_to_its_addons(): void
    {
        ['parent' => $parent, 'addon' => $addon, 'monthly' => $monthly, 'yearly' => $yearly, 'extra' => $extra, 'extraYearly' => $extraYearly] = $this->serverWithAddon();
        $extraQuarterly = $this->plan($extra, 'month', 14.00, 3);
        $quarterly = $this->plan($parent->product, 'month', 57.00, 3);

        ChangeCycle::apply($parent, $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);
        $this->assertSame($extraYearly->id, (int) $addon->fresh()->next_plan_id);

        ChangeCycle::apply($parent->fresh(), $quarterly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);
        $this->assertSame([$quarterly->id, $extraQuarterly->id], [(int) $parent->fresh()->next_plan_id, (int) $addon->fresh()->next_plan_id]);

        ChangeCycle::apply($parent->fresh(), $monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);
        $this->assertSame([null, null], [$parent->fresh()->next_plan_id, $addon->fresh()->next_plan_id]);
    }

    /** A live addon with no plan of the new cycle refuses the parent's change, either way, naming it; nothing is set. */
    public function test_a_parents_change_is_refused_when_an_addon_has_no_plan_of_the_new_cycle(): void
    {
        ['parent' => $parent, 'addon' => $addon, 'twoYears' => $twoYears] = $this->serverWithAddon();
        $expected = __('services.addons.cycle.no_plan', ['id' => $addon->id, 'addon' => 'Extra IPv4', 'cycle' => $twoYears->name]);
        $this->assertStringContainsString("#{$addon->id}", $expected);

        foreach ([ChangeCycle::NEXT_RENEWAL, ChangeCycle::NOW] as $mode) {
            $this->assertSame($expected, $this->refused(fn () => ChangeCycle::apply($parent->fresh(), $twoYears, $mode, MoneyTrail::SOURCE_STAFF)));
        }
        $this->assertSame([null, null, '2026-12-01'], [$parent->fresh()->next_plan_id, $addon->fresh()->next_plan_id, $addon->fresh()->expires_at->toDateString()]);

        // An ended addon does not stop it.
        DB::table('services')->where('id', $addon->id)->update(['status' => Service::STATUS_CANCELLED]);
        $this->assertNull(ChangeCycle::refusal($parent->fresh(), $twoYears, ChangeCycle::NEXT_RENEWAL));
    }

    /** An addon still waiting for its first payment (prorated to the old cycle's date) stops the parent's change. */
    public function test_a_pending_addon_stops_its_parents_change(): void
    {
        ['parent' => $parent, 'extra' => $extra, 'yearly' => $yearly] = $this->serverWithAddon();
        $made = (new AddAddon)->handle($parent, $extra, 1, null, false, AddAddon::PRORATED);

        foreach ([ChangeCycle::NEXT_RENEWAL, ChangeCycle::NOW] as $mode) {
            $this->assertSame(__('services.addons.cycle.pending', ['id' => $made['service']->id]), ChangeCycle::refusal($parent->fresh(), $yearly, $mode));
        }
        $this->assertSame(Invoice::STATUS_UNPAID, $made['invoice']->fresh()->status, 'its first invoice is not cancelled');
    }

    /** An addon added while its parent's change waits takes it too, or is refused when it has no plan of that cycle. */
    public function test_an_addon_added_under_a_pending_change_takes_it(): void
    {
        ['parent' => $parent, 'extra' => $extra, 'yearly' => $yearly, 'twoYears' => $twoYears, 'extraYearly' => $extraYearly, 'addon' => $addon] = $this->serverWithAddon();
        ChangeCycle::apply($parent, $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);

        $made = (new AddAddon)->handle($parent->fresh(), $extra, 1, null, false, AddAddon::NO_INVOICE);
        $this->assertSame($extraYearly->id, (int) $made['service']->fresh()->next_plan_id);

        // To a cycle the addon has no price for (set before the rule, or by hand): refused.
        DB::table('services')->whereIn('id', [$addon->id, $made['service']->id])->update(['status' => Service::STATUS_CANCELLED]);
        DB::table('services')->where('id', $parent->id)->update(['next_plan_id' => $twoYears->id]);
        $this->assertSame(__('services.addons.add.no_cycle_plan', ['addon' => 'Extra IPv4', 'cycle' => $twoYears->name]),
            $this->refused(fn () => (new AddAddon)->handle($parent->fresh(), $extra, 1, null, false, AddAddon::NO_INVOICE)));
    }

    /**
     * The parent's pending change gone some other way (an upgrade to another product, staff's edit): the addon's is not
     * applied on its own; its renewal stays on its parent's cycle and date.
     */
    public function test_an_addons_pending_change_is_dropped_when_its_parents_is_gone(): void
    {
        ['parent' => $parent, 'addon' => $addon, 'yearly' => $yearly, 'extraMonthly' => $extraMonthly] = $this->serverWithAddon();
        ChangeCycle::apply($parent, $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);
        DB::table('services')->where('id', $parent->id)->update(['next_plan_id' => null]);

        $this->assertSame($extraMonthly->id, RenewalBilling::billedPlan($addon->fresh())?->id);
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->artisan('app:cron-job');

        $line = InvoiceItem::query()->where('reference_id', $addon->id)->whereDate('period_start', '2026-12-01')->sole();
        $this->assertSame(['2027-01-01', $extraMonthly->id, null], [$line->period_end->toDateString(), (int) $addon->fresh()->plan_id, $addon->fresh()->next_plan_id]);
    }

    /**
     * An addon's kept price (staff's «Keep this price») is not carried to the new cycle: the catalogue's price of its new
     * plan, as for a service (decided 2026-10-14). Pinned here (REV-765-4).
     */
    public function test_an_addons_kept_price_is_replaced_by_the_catalogues_on_a_change_of_cycle(): void
    {
        ['parent' => $parent, 'addon' => $addon, 'yearly' => $yearly] = $this->serverWithAddon();
        DB::table('services')->where('id', $addon->id)->update(['price' => 3.00, 'price_locked' => true]);

        $this->assertSame('50.00', number_format((float) ChangeCycle::quote($parent, $yearly, ChangeCycle::NEXT_RENEWAL)['addons'][$addon->id]['gross'], 2, '.', ''));
        ChangeCycle::apply($parent, $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);
        $this->assertSame(['3.00', true], [number_format((float) $addon->fresh()->price, 2, '.', ''), (bool) $addon->fresh()->price_locked], 'until the renewal');
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->artisan('app:cron-job');

        $this->assertSame(['50.00', false], [number_format((float) $addon->fresh()->price, 2, '.', ''), (bool) $addon->fresh()->price_locked]);
    }

    /** Refusals that say the way out: an addon due on another date, one to be cancelled, a suspended service, staff's Plan field. */
    public function test_the_refusals_of_a_parents_change_name_the_way_out(): void
    {
        ['parent' => $parent, 'addon' => $addon, 'yearly' => $yearly, 'extraYearly' => $extraYearly] = $this->serverWithAddon();

        DB::table('services')->where('id', $addon->id)->update(['expires_at' => '2026-11-01']);
        foreach ([ChangeCycle::NEXT_RENEWAL, ChangeCycle::NOW] as $mode) {
            $this->assertSame(__('services.addons.cycle.behind', ['id' => $addon->id]), ChangeCycle::refusal($parent->fresh(), $yearly, $mode));
        }
        DB::table('services')->where('id', $addon->id)->update(['expires_at' => '2026-12-01']);

        DB::table('services')->where('id', $parent->id)->update(['status' => Service::STATUS_SUSPENDED]);
        $this->assertSame(__('services.change_cycle_suspended'), ChangeCycle::refusal($parent->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL));
        DB::table('services')->where('id', $parent->id)->update(['status' => Service::STATUS_ACTIVE]);

        foreach (['services.addons.cycle.behind', 'services.addons.cycle.cancelling', 'services.change_cycle_suspended', 'services.addons.cycle.staff_plan'] as $key) {
            $this->assertNotSame(__($key, [], 'en'), __($key, [], 'el'), $key);
        }
        $this->assertSame(__('services.addons.cycle.staff_plan'), $this->refused(fn () => $parent->fresh()->applyStaffChanges(['plan_id' => $yearly->id])));
        $this->assertSame(__('services.addons.cycle.staff_plan'), $this->refused(fn () => $addon->fresh()->applyStaffChanges(['plan_id' => $extraYearly->id])));
        $this->assertNull(ChangeCycle::refusal($parent->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL));
    }

    /** «Now»: the addon moves to its plan of the new cycle, for its parent's new period, on the same invoice. */
    public function test_a_parents_change_now_puts_its_addons_on_the_new_cycle(): void
    {
        ['parent' => $parent, 'addon' => $addon, 'yearly' => $yearly, 'extraYearly' => $extraYearly] = $this->serverWithAddon();

        $invoice = ChangeCycle::apply($parent, $yearly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $lines = $invoice->items()->orderBy('id')->get();
        $this->assertSame([[$parent->id, '2027-11-10', '200.00'], [$addon->id, '2027-11-10', '50.00']],
            $lines->map(fn (InvoiceItem $line) => [(int) $line->reference_id, $line->period_end->toDateString(), number_format((float) $line->price, 2, '.', '')])->all());
        $this->assertSame([$extraYearly->id, '50.00'], [(int) $addon->fresh()->plan_id, number_format((float) $addon->fresh()->price, 2, '.', '')]);
        (new RecordPaymentService)->handle($invoice->fresh(), null, (float) $invoice->fresh()->remaining, transactionId: 's6-follow-2');
        $this->assertSame(['2027-11-10', '2027-11-10'], [$parent->fresh()->expires_at->toDateString(), $addon->fresh()->expires_at->toDateString()]);
    }

    /**
     * The importer refuses, before anything is written (dry run too), a live recurring addon on another cycle than its
     * live service, naming it. A one-time or free addon, an ended one, and one of a service imported ended are not asked.
     */
    public function test_the_importer_refuses_a_live_addon_on_another_cycle_than_its_service(): void
    {
        $this->assertTrue(WhmcsAddonImport::sameCycle('Semi-Annually', 'semiannually'));
        $this->assertFalse(WhmcsAddonImport::sameCycle('Monthly', 'Annually'));

        $whmcs = new \PDO('sqlite::memory:');
        $whmcs->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY)');
        $whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT, value TEXT)');
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, billingcycle TEXT, nextduedate TEXT, domainstatus TEXT)');
        $whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT, status TEXT, billingcycle TEXT, nextduedate TEXT)');
        $whmcs->exec("INSERT INTO tblhosting VALUES (1, 'Annually', '2027-05-18', 'Active'), (2, 'Monthly', '2026-12-18', 'Suspended'), (3, 'Annually', '2027-05-18', 'Terminated')");
        $whmcs->exec("INSERT INTO tblhostingaddons VALUES (201, 1, 'Active', 'Annually', '2027-05-18'), (202, 1, 'Active', 'Monthly', '2026-12-18'),
            (203, 2, 'Suspended', 'Annually', '2027-03-18'), (204, 2, 'Active', 'Monthly', '2026-12-18'), (205, 1, 'Active', 'One Time', '0000-00-00'),
            (206, 1, 'Terminated', 'Monthly', '2026-01-18'), (207, 3, 'Active', 'Monthly', '2026-12-18'), (208, 1, 'Active', 'Free Account', '0000-00-00')");
        $services = Service::query()->count();
        ImportFromWhmcs::$connect = fn () => $whmcs;
        putenv('WHMCS_SOURCE_PASSWORD=secret');
        try {
            $source = ['dbname' => 'whmcs_copy', '--source-user' => 'reader', '--source-host' => 'db.example.test'];
            $refused = 'WHMCS addon(s) on another billing cycle than their service: #202, #203.';
            $this->artisan('app:import-from-whmcs', $source + ['--dry-run' => true])->assertFailed()->expectsOutputToContain($refused);
            $this->artisan('app:import-from-whmcs', $source)->assertFailed()->expectsOutputToContain($refused)
                ->doesntExpectOutputToContain('--confirm-database');
        } finally {
            ImportFromWhmcs::$connect = null;
            putenv('WHMCS_SOURCE_PASSWORD');
        }
        $this->assertSame($services, Service::query()->count(), 'nothing written');
    }
}
