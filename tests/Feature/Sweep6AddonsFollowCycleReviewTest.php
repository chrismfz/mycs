<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Livewire\Services\Show;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyTrail;
use App\Services\Service\AddonCycle;
use App\Services\ServiceUpgrade\ChangeCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Independent and adversarial review of #765 (addons follow their parent's billing cycle, owner 2026-11-09). Each test is
 * a finding REV-765-n: it fails on the PR's head and says what the rule asks. Fake data only.
 */
class Sweep6AddonsFollowCycleReviewTest extends TestCase
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
     * A monthly server (20.00, or 200.00 a year) due 2026-12-01, and «Extra IPv4» (5.00 a month, 50.00 a year), a monthly
     * addon of it due the same day.
     *
     * @return array{parent: Service, addon: Service, extra: Product, monthly: Plan, yearly: Plan, extraMonthly: Plan, extraYearly: Plan}
     */
    private function serverWithAddon(array $addonAttributes = [], ?User $owner = null): array
    {
        $host = Product::factory()->create(['name' => 'Server']);
        $monthly = $this->plan($host, 'month', 20.00);
        $yearly = $this->plan($host, 'year', 200.00);
        $extra = Product::factory()->create(['name' => 'Extra IPv4', 'is_addon' => true, 'stock' => null]);
        $extraMonthly = $this->plan($extra, 'month', 5.00);
        $extraYearly = $this->plan($extra, 'year', 50.00);
        $parent = $this->service($host, $monthly, 20.00, '2026-11-01', '2026-12-01', $owner === null ? [] : ['client_id' => $owner->client()->id]);
        $addon = $this->service($extra, $extraMonthly, 5.00, '2026-11-01', '2026-12-01',
            $addonAttributes + ['client_id' => $parent->client_id, 'parent_service_id' => $parent->id]);

        return compact('parent', 'addon', 'extra', 'monthly', 'yearly', 'extraMonthly', 'extraYearly');
    }

    /** The lines of $service's period from $start, on invoices still open. */
    private function openLines(Service $service, string $start)
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->whereDate('period_start', $start)
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', Invoice::OPEN_STATUSES))->get();
    }

    private function sameCycleAndDate(Service $parent, Service $addon, string $why): void
    {
        [$parent, $addon] = [$parent->fresh(), $addon->fresh()];
        $this->assertTrue(AddonCycle::same($parent->plan, $addon->plan), "{$why}: the server is on «{$parent->plan->name}», its addon on «{$addon->plan->name}»");
        $this->assertSame($parent->expires_at->toDateString(), $addon->expires_at->toDateString(), "{$why}: one due date");
    }

    /**
     * REV-765-1 (high). The addon is one period behind its server: billed apart («Do not charge automatically» on the
     * addon; the same with its own payment method or the account's «Separate invoices»), the server's December is paid
     * (due 2027-01-01), the addon's 5.00 for December is still open (due 2026-12-01). The customer sets the server to
     * yearly from the next renewal. The addon's open December invoice is cancelled as «a renewal of the old cycle» and the
     * cron bills it again at once in the new one: 50.00 for 2026-12-01 → 2027-12-01, a month before its server changes, and
     * for a month the server paid monthly. From then on the server is due on 1 January and the addon on 1 December, for
     * good, and taking the change back leaves the addon yearly under a monthly server. The rule (an addon has its
     * parent's cycle and due date) wants either a refusal naming the addon (pay its invoice first) or both still together.
     */
    public function test_rev_765_1_an_addon_a_period_behind_is_not_moved_to_the_new_cycle_before_its_parent(): void
    {
        ['parent' => $parent, 'addon' => $addon, 'yearly' => $yearly, 'monthly' => $monthly] = $this->serverWithAddon(['no_auto_charge' => true]);
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->artisan('app:cron-job');
        $december = $this->openLines($parent, '2026-12-01')->sole()->invoice;
        $this->assertNotSame($december->id, $this->openLines($addon, '2026-12-01')->sole()->invoice_id, 'billed apart');
        (new RecordPaymentService)->handle($december, null, (float) $december->remaining, transactionId: 'rev-765-1');
        $this->assertSame(['2027-01-01', '2026-12-01'], [$parent->fresh()->expires_at->toDateString(), $addon->fresh()->expires_at->toDateString()]);

        Carbon::setTestNow('2026-11-26 10:00:00');
        try {
            ChangeCycle::apply($parent->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
        } catch (DisplayException $e) {
            // The KISS way out: refused, naming the addon whose invoice is to be paid first.
            $this->assertStringContainsString("#{$addon->id}", $e->getMessage());
            $this->assertSame(Invoice::STATUS_UNPAID, $this->openLines($addon, '2026-12-01')->sole()->invoice->status);

            return;
        }
        $this->artisan('app:cron-job');

        // Its December is still a month of the cycle its server paid December in, at 5.00: never a year from 1 December.
        $line = $this->openLines($addon, '2026-12-01')->sole();
        $this->assertSame(['2027-01-01', '5.00'], [$line->period_end->toDateString(), number_format((float) $line->price, 2, '.', '')],
            'the addon\'s open December: the month its server is in, not the first year of a cycle its server has not started');
        $this->assertSame($monthly->billing_unit, $addon->fresh()->plan->billing_unit, 'on its server\'s cycle until the server changes');
    }

    /**
     * REV-765-2 (medium). Server and addon overdue on one invoice (25.00, due 2026-12-01) and suspended: the addon with
     * its parent. The customer sets the server to yearly from the next renewal, which cancels that invoice for both; the
     * cron bills the overdue period again for the server only (200.00): an addon suspended with its parent is never billed
     * (RenewalBilling: «its period is on its parent's invoice»), and now it is on none. The customer pays all there is to
     * pay; the server and the addon come back; the next night the addon gets an invoice of its own (50.00, already 10 days
     * overdue) and is suspended again in the same run. The rule: it renews on its parent's invoice, every time.
     */
    public function test_rev_765_2_a_suspended_parents_change_bills_its_addon_on_the_same_invoice(): void
    {
        ['parent' => $parent, 'addon' => $addon, 'yearly' => $yearly] = $this->serverWithAddon();
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->artisan('app:cron-job');
        Carbon::setTestNow('2026-12-10 10:00:00');
        $this->artisan('app:cron-job');
        $this->assertSame([Service::STATUS_SUSPENDED, Service::STATUS_SUSPENDED], [$parent->fresh()->status, $addon->fresh()->status]);

        try {
            ChangeCycle::apply($parent->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
        } catch (DisplayException) {
            // The KISS way out: no change of cycle while it is suspended for non-payment (pay first); nothing cancelled.
            $this->assertCount(1, $this->openLines($addon, '2026-12-01'));

            return;
        }
        $this->artisan('app:cron-job');

        $server = $this->openLines($parent, '2026-12-01')->sole();
        $extra = $this->openLines($addon, '2026-12-01');
        $this->assertCount(1, $extra, 'the addon\'s overdue period is billed again with its server\'s, not left on no invoice');
        $this->assertSame((int) $server->invoice_id, (int) $extra->first()->invoice_id, 'one invoice for both');

        // Paying what is open brings both back for good: no second invoice and suspension the night after.
        foreach (Invoice::query()->where('client_id', $parent->client_id)->where('status', Invoice::STATUS_UNPAID)->get() as $invoice) {
            (new RecordPaymentService)->handle($invoice, null, (float) $invoice->remaining, transactionId: 'rev-765-2-' . $invoice->id);
        }
        Carbon::setTestNow('2026-12-11 10:00:00');
        $this->artisan('app:cron-job');
        $this->assertSame([Service::STATUS_ACTIVE, Service::STATUS_ACTIVE], [$parent->fresh()->status, $addon->fresh()->status]);
        $this->sameCycleAndDate($parent, $addon, 'after the payment');
    }

    /**
     * REV-765-3 (low). An addon's pending change is dropped when its parent's is gone (billedPlan), but the cron reads the
     * days to invoice ahead from a row loaded without `parent_service_id` (CronJob: get(['id', 'plan_id', 'next_plan_id',
     * 'product_id'])): there isAddon() is false and billedPlan answers the dropped yearly plan. With monthly renewals
     * invoiced 3 days ahead and the others 7, six days before the due date the addon's monthly renewal is invoiced alone,
     * four days before its server's: not on its parent's invoice.
     */
    public function test_rev_765_3_the_cron_reads_an_addons_invoice_days_from_the_plan_it_bills(): void
    {
        ['parent' => $parent, 'addon' => $addon, 'yearly' => $yearly] = $this->serverWithAddon();
        $this->settings(['cronjob_invoice_monthly' => [3, 'integer']]);
        ChangeCycle::apply($parent, $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);
        DB::table('services')->where('id', $parent->id)->update(['next_plan_id' => null]); // the parent's change gone another way

        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->artisan('app:cron-job');
        $this->assertCount(0, $this->openLines($parent, '2026-12-01'), 'a monthly renewal 6 days ahead: its 3 days not reached');
        $this->assertCount(0, $this->openLines($addon, '2026-12-01'), 'the addon renews monthly with its server: not invoiced 4 days before it, alone');

        Carbon::setTestNow('2026-11-28 10:00:00');
        $this->artisan('app:cron-job');
        $this->assertSame((int) $this->openLines($parent, '2026-12-01')->sole()->invoice_id, (int) $this->openLines($addon, '2026-12-01')->sole()->invoice_id);
    }

    /**
     * REV-765-4 (medium). The customer's «Change billing cycle» lists each cycle with the server's new amount only
     * («1 year (200.00 USD)») and a line that the addons change too. The addon's new amount is nowhere: here it goes from
     * 3.00 a month, a price staff set with «Keep this price», to the catalogue's 50.00 a year (not 36.00), by the
     * customer's own click, without a figure. The existing rule for a service (a staff price is not carried over, decided
     * 2026-10-14) is pinned by no test for an addon, and it was meant to show the customer the amount they move to.
     * (It does not either, since SVC-14b: Blade reads «@else (…)» as @else's own argument and drops the «(200.00 USD)» of
     * every option, so the customer picks a cycle with no amount at all. Not this PR's, but the same line to fix.)
     */
    public function test_rev_765_4_the_customer_sees_what_the_addons_will_cost_on_the_new_cycle(): void
    {
        $owner = User::factory()->create();
        ['parent' => $parent, 'addon' => $addon, 'yearly' => $yearly] = $this->serverWithAddon(['price' => 3.00, 'price_locked' => true], $owner);
        $this->assertSame('50.00', number_format((float) ChangeCycle::quote($parent, $yearly, ChangeCycle::NEXT_RENEWAL)['addons'][$addon->id]['line'], 2, '.', ''));

        $page = $this->livewireAs($owner)->test(Show::class, ['service' => $parent])->set('showCycle', true);

        $page->assertSee(__('services.change_cycle_addons'));
        // The addon's 50.00, or the 250.00 of both: either says it.
        $page->assertSee('50.00');
    }

    /**
     * REV-765-5 (medium). The rule holds in ChangeCycle only. Staff's service form still lists every plan of the product
     * («Plan»), and saving another cycle there (Service::applyStaffChanges, where a free or one-time plan is already
     * refused) moves the server to yearly and leaves its addon monthly, with nothing said. The cheap guard is there:
     * refuse a plan of another cycle while the service has live addons (and on an addon, one of another cycle than its
     * parent's), pointing to «Change billing cycle».
     */
    public function test_rev_765_5_staffs_plan_field_does_not_leave_an_addon_on_another_cycle(): void
    {
        ['parent' => $parent, 'addon' => $addon, 'yearly' => $yearly, 'extraYearly' => $extraYearly] = $this->serverWithAddon();

        foreach ([[$parent, $yearly], [$addon, $extraYearly]] as [$service, $plan]) {
            try {
                $service->fresh()->applyStaffChanges(['plan_id' => $plan->id]);
            } catch (DisplayException) {
                // Refused: what the rule asks.
            }
            $this->assertTrue(AddonCycle::same($parent->fresh()->plan, $addon->fresh()->plan),
                "staff's plan field on #{$service->id}: the server is on «{$parent->fresh()->plan->name}», its addon on «{$addon->fresh()->plan->name}»");
            DB::table('services')->where('id', $service->id)->update(['plan_id' => $service->plan_id]);
        }
    }
}
