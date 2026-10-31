<?php

namespace Tests\Feature;

use App\Admin\Pages\PriceMismatches;
use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ServiceResource;
use App\Enums\DomainStatus;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Reports\PriceMismatch;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Round-2 adversarial review of the Price mismatches report (PR #557, after the round-1 fixes: RenewalPrice, the kept
 * sweep, per-permission links). test_bug_* fail on the PR as it stands; test_holds_* pin what held.
 */
class PriceMismatchesAdversarialR2Test extends TestCase
{
    use RefreshDatabase;

    private ?object $product = null;

    /** One product, its monthly plan at 20.00 USD. */
    private function product(): object
    {
        if ($this->product === null) {
            $this->product = $this->createProduct();
            Price::query()->where('plan_id', $this->product->plan->id)->update(['price' => 20.00, 'setup_fee' => 0]);
        }

        return $this->product;
    }

    private function service(float|string $price, array $attributes = []): Service
    {
        $product = $this->product();

        return Service::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1,
            'expires_at' => now()->addMonth()->toDateString()]);
    }

    private function domain(string $own): Domain
    {
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        DomainTldPrice::query()->firstOrCreate(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR'], ['price' => '12.00']);
        $sld = 'n' . uniqid();

        return Domain::factory()->create(['client_id' => User::factory()->create()->client()->id, 'sld' => $sld, 'tld' => 'com',
            'fqdn' => $sld . '.com', 'domain_tld_id' => $tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active,
            'expires_at' => now()->addMonth()->toDateString(), 'currency_code' => 'EUR', 'years' => 1, 'recurring_amount' => $own]);
    }

    private function paidInvoiceFor(Service $service, string $amount, string $start, string $end): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Period', 'price' => $amount, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        return collect(PriceMismatch::rows())->keyBy(fn (array $row) => $row['type'] . $row['id'])->all();
    }

    private function asAdmin()
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $this->livewireAs(Staff::factory()->admin()->create());
    }

    /** Selects that sweep the services (the report's own query), over $run. */
    private function sweeps(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $n = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'from "services" where "status" in')
            && str_contains($q['query'], '"plan_id" is not null'))->count();
        DB::disableQueryLog();

        return $n;
    }

    // ---------------------------------------------------------------------------------------------------------- bugs

    /**
     * ADV-PM-R2-1 (medium): a coupon that «requires» a product the account no longer holds (C4 of #274). RenewalBilling
     * ends it at the next renewal and bills the full price (calculatePrice(applyCoupon: false)); RenewalPrice::for() does
     * not have that rule, so the report compares the discounted services.price (10.00) with a catalog that already drops
     * the coupon (20.00) and lists «10.00 below the catalog», though the renewal bills 20.00. Every such service is a false
     * positive. Fix: the requirement rule in RenewalPrice::for() (the forecast and Upcoming renewals have the same gap).
     */
    public function test_bug_a_coupon_whose_required_product_is_gone_is_listed_though_the_renewal_bills_the_catalog(): void
    {
        $required = Product::factory()->create();
        $coupon = Coupon::create(['code' => 'NEEDS', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 0]);
        $coupon->requiredProducts()->sync([$required->id]);
        $service = $this->service('10.00', ['coupon_id' => $coupon->id, 'coupon_started_at' => now()->subMonth()->startOfDay()]);

        $this->assertArrayNotHasKey("service{$service->id}", $this->rows(),
            'listed at 10.00 against 20.00, but RenewalBilling ends the coupon (its required product is gone) and bills 20.00');
    }

    /**
     * ADV-PM-R2-2 (low): the kept sweep holds the row names already translated (trans_choice «:count year», the plan's
     * cycle label) in the locale of whoever swept: one cache key for every locale. Staff in English read «1 έτος» for ten
     * minutes after a Greek colleague's sweep. Fix: the locale in the cache key, or keep the parts and translate in the view.
     */
    public function test_bug_the_kept_sweep_carries_the_sweepers_locale(): void
    {
        $domain = $this->domain('9.50');
        app()->setLocale('el');
        PriceMismatch::kept(fresh: true);

        app()->setLocale('en');
        $row = collect(PriceMismatch::kept()['rows'])->firstWhere('id', $domain->id);

        $this->assertStringContainsString('1 year', $row['name'], "an English reader got «{$row['name']}»");
    }

    /**
     * ADV-PM-R2-3 (low): «as of» prints Y-m-d H:i:s («2026-10-31 10:15:00»), while the admin writes every date d/m/Y H:i
     * (owner 2026-10-21, AdminPanelProvider). The zone is right (now() in app.timezone, Settings → General).
     */
    public function test_bug_as_of_is_not_in_the_admins_date_format(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(10, 15));
        $this->service('15.00');

        $this->asAdmin()->test(PriceMismatches::class)->assertOk()->assertSee('31/10/2026 10:15');
    }

    /**
     * ADV-PM-R2-4 (low): «Refresh» has no limit and no lock: each click (or a scripted wire call) runs the whole sweep
     * again, a few queries per service with a coupon. Ten clicks are ten sweeps; two staff clicking at once sweep in
     * parallel (Cache::remember has no lock). Staff only, so low. Fix: a per-staff RateLimiter (a few a minute) and
     * Cache::lock around the sweep.
     */
    public function test_bug_refresh_is_not_rate_limited(): void
    {
        $this->service('15.00');
        $page = $this->asAdmin()->test(PriceMismatches::class);

        $sweeps = $this->sweeps(function () use ($page) {
            foreach (range(1, 10) as $i) {
                $page->call('refresh');
            }
        });

        $this->assertLessThan(10, $sweeps, "ten clicks on Refresh swept {$sweeps} times");
    }

    /**
     * ADV-PM-R2-5 (low): the catalog side calls $service->cycleNumber() for every service with a coupon_id, also when the
     * coupon has long ended (coupon_expired_at set, where calculatePrice ignores the cycle) and for a «forever» coupon
     * (cycles 0): two or three queries each (its invoice lines, its live period lines) for nothing. Imported services keep
     * the coupon_id after it ended. Fix: pass the cycle only when coupon_expired_at is null and cycles() > 0.
     */
    public function test_bug_an_ended_coupon_still_costs_queries_per_service(): void
    {
        $coupon = Coupon::create(['code' => 'OLD3', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 3]);
        $make = function (int $count) use ($coupon) {
            foreach (range(1, $count) as $i) {
                $this->service('25.00', ['coupon_id' => $coupon->id, 'coupon_started_at' => now()->subYear()->startOfDay(),
                    'coupon_expired_at' => now()->subMonths(8)]);
            }
        };
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            PriceMismatch::rows();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $make(10);
        $ten = $count();
        $make(20);
        $thirty = $count();

        $this->assertLessThan(20, $thirty - $ten, "10 → 30 services with an ended coupon: {$ten} → {$thirty} queries");
    }

    /**
     * ADV-PM-R2-6 (low): an option value with no price in the service's currency (or none for its cycle) is counted as
     * 0.00 on the catalog side (HasPlans::price → null → number_format 0). The round-1 fix skips a plan with no price in
     * the currency (ADV-PM-4), but not an option: a service with a 5.00 option that lost its USD price is listed «+5.00
     * above the catalog», as if the catalog gave the option away. Fix: skip (or mark «no catalog price») as for the plan.
     */
    public function test_bug_an_option_without_a_price_in_the_currency_counts_as_free(): void
    {
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $option = ConfigOption::create(['name' => 'Disk', 'type' => 'radio', 'sort' => 0]);
        $option->products()->attach($this->product()->product->id);
        $value = ConfigOption::create(['name' => '10 GB', 'sort' => 0, 'parent_id' => $option->id]);
        $plan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => '10 GB',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 5.00, 'currency_code' => 'EUR']); // no USD price
        $service = $this->service('25.00');
        $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $value->id]);

        $this->assertArrayNotHasKey("service{$service->id}", $this->rows(),
            'listed +5.00 against a catalog that counts an option with no USD price as free');
    }

    /**
     * ADV-PM-R2-7 (low): el.json gives «Renews» (the filter) and «Renews at» (the price column) the same «Ανανεώνεται»:
     * the column header reads «Renews» above a price. «Τιμή ανανέωσης» (or «Ανανεώνεται στα») for the column.
     */
    public function test_bug_renews_at_reads_as_renews_in_greek(): void
    {
        app()->setLocale('el');

        $this->assertNotSame(__('Renews'), __('Renews at'));
    }

    // ------------------------------------------------------------------------------------------------------- holds

    /**
     * One sweep for every viewer leaks nothing: canAccess asks the same three permissions of everyone, and the links are
     * decided per viewer at render (an admin's sweep does not give a limited staff member the edit links).
     */
    public function test_holds_the_shared_sweep_links_per_viewer(): void
    {
        $service = $this->service('15.00');
        $this->asAdmin()->test(PriceMismatches::class)->assertSee(ServiceResource::getUrl('edit', ['record' => $service->id]), false);

        $staff = Staff::factory()->withPermissions(['admin.reports.view', 'admin.services.viewAny', 'admin.domains.viewAny'])->create();
        $this->livewireAs($staff)->test(PriceMismatches::class)->assertOk()->assertSee("Service #{$service->id}")
            ->assertDontSee(ServiceResource::getUrl('edit', ['record' => $service->id]), false)
            ->assertDontSee(ClientResource::getUrl('edit', ['record' => $service->client_id]), false);
    }

    /** Kept means stale until Refresh (as of is shown): a price fixed meanwhile is still listed, Refresh drops it. */
    public function test_holds_refresh_drops_a_price_fixed_meanwhile(): void
    {
        $service = $this->service('15.00');
        $page = $this->asAdmin()->test(PriceMismatches::class)->assertSee("Service #{$service->id}");
        $service->update(['price' => 20.00]);

        $page->call('$refresh')->assertSee("Service #{$service->id}");
        $page->call('refresh')->assertDontSee("Service #{$service->id}");
        $this->assertTrue(Cache::has('reports:price-mismatch:' . app()->getLocale()), 'kept per language (R2-2)');
    }

    /**
     * A cycle change «from the next renewal» on a service past several renewals: compared at the next plan's price, as
     * RenewalBilling bills it (calculatePrice on the next plan), not at the monthly price still on the service.
     */
    public function test_holds_a_cycle_change_after_several_renewals_compares_the_next_plan(): void
    {
        $yearly = Plan::factory()->create(['priceable_id' => $this->product()->product->id, 'priceable_type' => Product::class,
            'name' => 'Yearly', 'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $yearly->id, 'price' => 200.00, 'currency_code' => 'USD']);
        $start = now()->subMonths(3)->startOfDay();
        $service = $this->service('20.00', ['next_plan_id' => $yearly->id, 'expires_at' => $start->copy()->addMonths(3)->toDateString()]);
        foreach (range(0, 2) as $m) {
            $this->paidInvoiceFor($service, '20.00', $start->copy()->addMonths($m)->toDateString(), $start->copy()->addMonths($m + 1)->toDateString());
        }

        $this->assertArrayNotHasKey("service{$service->id}", $this->rows(), 'renews at 200.00 on its yearly plan, its catalog');
    }

    /** A service with no expires_at is compared from today, with no error. */
    public function test_holds_no_expiry_date(): void
    {
        $service = $this->service('15.00');
        DB::table('services')->where('id', $service->id)->update(['expires_at' => null]);

        $this->assertSame('-5.00', $this->rows()["service{$service->id}"]['difference']);
    }

    /** Livewire 4 calls updated($name, $value): the one-parameter signature works, a filter goes back to page 1. */
    public function test_holds_a_filter_goes_back_to_the_first_page(): void
    {
        foreach (range(1, PriceMismatches::PER_PAGE + 1) as $i) {
            $this->service(number_format(20 + $i, 2, '.', ''));
        }

        $page = $this->asAdmin()->test(PriceMismatches::class)->set('page', 2);
        $this->assertSame(2, $page->get('page'));
        $page->set('direction', 'above');
        $this->assertSame(1, $page->get('page'));
    }
}
