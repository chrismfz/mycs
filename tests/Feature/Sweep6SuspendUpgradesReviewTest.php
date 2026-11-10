<?php

namespace Tests\Feature;

use App\Livewire\Services\Show;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Independent and adversarial review of #770 (sweep 6: no suspension for a just-issued invoice; an upgrade and a change
 * of cycle never wait together). Each test fails for the reason its finding reports (REV-770-n). Invented fixtures only.
 */
class Sweep6SuspendUpgradesReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 03:00:00');
        config(['settings.cronjob_suspend_requires_invoice' => false, 'settings.cronjob_order_suspend' => 8,
            'settings.cronjob_order_terminate' => 60, 'settings.cronjob_invoice' => 7]);
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A monthly service at 10.00 whose product also sells a yearly plan at 100.00. @return array{Service, Plan, Plan} */
    private function monthlyWithYearly(string $expires): array
    {
        $product = $this->createProduct();
        $monthly = $product->plan;
        Plan::query()->whereKey($monthly->id)->update(['billing_unit' => 'month', 'billing_period' => 1]);
        $yearly = Plan::factory()->create(['priceable_id' => $product->product->id, 'priceable_type' => Product::class, 'name' => 'Yearly',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $yearly->id, 'price' => 100.00, 'setup_fee' => 0, 'currency_code' => 'USD']);
        $service = Service::factory()->create([
            'client_id' => $this->client->id, 'plan_id' => $monthly->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ]);

        return [$service, $monthly->fresh(), $yearly->fresh()];
    }

    private function plan(Product $product, string $unit, float $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => ucfirst($unit) . 'ly',
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    private function cronOn(string $day): void
    {
        Carbon::setTestNow($day . ' 03:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /** @return list<string> the issue days of the service's open renewal invoices */
    private function openIssuedOn(Service $service): array
    {
        return Invoice::query()->where('status', Invoice::STATUS_UNPAID)
            ->whereHas('items', fn ($line) => $line->where('reference_type', Service::class)->where('reference_id', $service->id))
            ->orderBy('id')->get()->map(fn (Invoice $invoice) => $invoice->created_at->toDateString())->all();
    }

    /**
     * REV-770-1. The customer's own «Change billing cycle» (Livewire\Services\Show::changeCycle → ChangeCycle::apply,
     * NEXT_RENEWAL) cancels the open renewal of the old cycle and the next daily run bills the period again: a new
     * invoice, issued that night. Before #770 the service was suspended by its due date all the same (WHMCS). Now the
     * clock starts again at the new invoice: monthly, due 10-15, invoice issued 10-08, to be suspended on 10-24. On
     * 10-23 the customer sets «yearly from the next renewal» and takes it straight back (two clicks, nothing to pay):
     * the run of 10-24 issues a new monthly invoice and does not suspend.
     */
    public function test_rev_770_1_a_customer_does_not_put_off_the_suspension_by_changing_the_cycle_and_taking_it_back(): void
    {
        [$service, $monthly, $yearly] = $this->monthlyWithYearly('2026-10-15');
        $this->cronOn('2026-10-08');
        $this->assertSame(['2026-10-08'], $this->openIssuedOn($service), 'precondition: billed 7 days ahead');
        $this->cronOn('2026-10-23');
        $this->assertSame('active', $service->fresh()->status, 'precondition: 8 days past due, suspended tomorrow');

        Carbon::setTestNow('2026-10-23 12:00:00');
        // On their service's page: «Change billing cycle» to yearly, then again, back to monthly.
        foreach ([$yearly, $monthly] as $plan) {
            $this->livewireAs($this->user)->test(Show::class, ['service' => $service->fresh()])
                ->set('cyclePlan', $plan->id)->call('changeCycle')->assertHasNoErrors();
        }
        $this->assertNull($service->fresh()->next_plan_id, 'precondition: taken back, the service is as it was');
        $this->assertSame([], $this->openIssuedOn($service), 'precondition: its open renewal went with the change');

        $this->cronOn('2026-10-24');

        $this->assertSame('suspended', $service->fresh()->status,
            'due 10-15, nothing paid, Suspend Days 8: not suspended on 10-24, its renewal was issued again tonight ('
            . implode(', ', $this->openIssuedOn($service)) . ') after the customer changed the cycle and took it back');
    }

    /**
     * REV-770-1, repeated: once a week the customer switches the cycle (monthly → yearly → monthly …, each «from the
     * next renewal», nothing charged). Each switch cancels the open renewal and the night's run issues a new one, so the
     * invoice is never Suspend Days old. Due 10-15; on 12-20 (66 days past due, past Termination Days 60) it still runs,
     * nothing paid: never suspended, so never terminated either.
     */
    public function test_rev_770_1_a_customer_switching_the_cycle_every_week_is_still_suspended(): void
    {
        [$service, $monthly, $yearly] = $this->monthlyWithYearly('2026-10-15');
        $this->cronOn('2026-10-08');

        $to = $yearly;
        for ($day = Carbon::parse('2026-10-09'); $day->lte('2026-12-20'); $day->addDay()) {
            // Every 7th day from 10-14, at noon: the other cycle, from the next renewal.
            if ($day->gte('2026-10-14') && (int) Carbon::parse('2026-10-14')->diffInDays($day) % 7 === 0 && $service->fresh()->status === 'active') {
                Carbon::setTestNow($day->toDateString() . ' 12:00:00');
                ChangeCycle::apply($service->fresh(), $to, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
                $to = (int) $to->id === (int) $yearly->id ? $monthly : $yearly;
            }
            $this->cronOn($day->copy()->addDay()->toDateString());
        }

        $service->refresh();
        $this->assertSame('2026-10-15', $service->expires_at->toDateString(), 'precondition: nothing was ever paid');
        $this->assertNotSame('active', $service->status,
            'due 10-15 and never paid, still running on 12-21: each change of cycle had its renewal issued again (open now, issued '
            . implode(', ', $this->openIssuedOn($service)) . ')');
    }

    /**
     * REV-770-2. An open renewal with no issue date (`created_at` NULL: the importer writes NULL for a WHMCS invoice
     * whose `date` is 0000-00-00 or unreadable, ImportFromWhmcs::validateDate) is neither «no open invoice» nor «issued
     * Suspend Days ago»: its service is never suspended, however long overdue. Before #770 the default rule did not read
     * the invoice at all. Here: due 09-01, never paid, the run of 10-20 (49 days past due).
     */
    public function test_rev_770_2_an_open_invoice_without_an_issue_date_does_not_keep_its_service_from_suspension_for_good(): void
    {
        [$service] = $this->monthlyWithYearly('2026-09-01');
        $invoice = $this->billedAhead($service);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => null]);

        $this->cronOn('2026-10-20');

        $this->assertSame(1, InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->count(),
            'precondition: its one renewal, still open, nothing billed again');
        $this->assertSame('suspended', $service->fresh()->status, '49 days past due with an open renewal: never suspended, its invoice has no issue date');
    }

    /**
     * REV-770-3. An addon takes its parent's change of cycle (`next_plan_id` is set on it too, ChangeCycle::apply). A
     * change of the addon's package is then refused with «take it back first (Change billing cycle, keep the current
     * one)», but an addon has no «Change billing cycle»: ChangeCycle refuses it («change the main service's billing
     * cycle»). The refusal names a way through that is itself refused on that service; the real one (take the change back
     * on the main service #N) is not named.
     */
    public function test_rev_770_3_the_way_through_named_to_an_addon_whose_parent_changes_cycle_is_one_it_can_take(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        [$parent, , $yearly] = $this->monthlyWithYearly('2026-12-01');
        $ip = Product::factory()->create(['name' => 'Extra IPv4', 'is_addon' => true]);
        $ipMonthly = $this->plan($ip, 'month', 2.00);
        $this->plan($ip, 'year', 24.00);
        $ips = Product::factory()->create(['name' => 'Extra IPv4 x2', 'is_addon' => true]);
        $this->plan($ips, 'month', 4.00);
        $this->plan($ips, 'year', 48.00);
        $addon = Service::factory()->create(['client_id' => $this->client->id, 'parent_service_id' => $parent->id, 'product_id' => $ip->id,
            'plan_id' => $ipMonthly->id, 'status' => 'active', 'expires_at' => '2026-12-01', 'currency_code' => 'USD', 'price' => 2.00, 'quantity' => 1]);

        ChangeCycle::apply($parent->fresh(), $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
        $this->assertNotNull($addon->fresh()->next_plan_id, 'precondition: the addon follows its parent to the yearly cycle');

        $placed = PlaceUpgrade::handle($addon->fresh(), $ips, [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertNotContains($placed->outcome, [PlaceUpgrade::APPLIED, PlaceUpgrade::INVOICED], 'precondition: refused (the cut of S6-PLAN-CYCLE-ADDONS-1)');

        // What the refusal tells them to do, on the service it was refused for: «Change billing cycle, keep the current one».
        $named = ChangeCycle::refusal($addon->fresh(), $ipMonthly, ChangeCycle::NEXT_RENEWAL);
        $this->assertTrue($placed->outcome !== PlaceUpgrade::CYCLE_CHANGE_PENDING || $named === null,
            'the addon is told «' . __('services.upgrade_cycle_change_pending', [], 'en') . '», and taking it back on the addon answers «' . $named . '»');
    }
}
