<?php

namespace Tests\Feature;

use App\Admin\Pages\PriceMismatches;
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
use App\Models\Setting;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Billing\RenewalPrice;
use App\Services\Reports\PriceMismatch;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Adversarial review of the Price mismatches report (PR #557): what it lists that renews at its catalog price, what
 * breaks the page, what it costs per Livewire request. test_bug_* fail on the PR as it stands; test_holds_* pin what held.
 */
class PriceMismatchesAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private ?object $product = null;

    /** One product, its monthly plan at 20.00 USD (shared by every service here). */
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

    private function domain(?string $own, array $attributes = [], string $renew = '12.00'): Domain
    {
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        DomainTldPrice::query()->firstOrCreate(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR'], ['price' => $renew]);
        $sld = 'n' . uniqid();

        return Domain::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id, 'sld' => $sld, 'tld' => 'com',
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

    // ---------------------------------------------------------------------------------------------------------- bugs

    /**
     * ADV-PM-1 (high): DomainResource has no «edit» page (index and view only), so the first domain row on the page
     * throws on its link and the whole report is a 500. The PR's own page test has services only.
     */
    public function test_bug_a_domain_row_breaks_the_page(): void
    {
        $domain = $this->domain('9.50');

        $this->asAdmin()->test(PriceMismatches::class)->assertOk()->assertSee("Domain #{$domain->id}");
    }

    /**
     * ADV-PM-2 (high): updated() has no property filter, and Livewire calls it for every property, the page too: «Next»
     * sets page 2, updated() sets it back to 1. Nothing past the first 50 rows can be reached.
     */
    public function test_bug_next_page_stays_on_the_first(): void
    {
        $services = [];
        foreach (range(1, PriceMismatches::PER_PAGE + 1) as $i) {
            $services[] = $this->service(number_format(20 + $i, 2, '.', '')); // +1.00 … +51.00 above the catalog
        }
        $smallest = $services[0]; // +1.00: the last row, alone on page 2

        $page = $this->asAdmin()->test(PriceMismatches::class)->assertDontSee("Service #{$smallest->id}<");
        $page->set('page', 2);

        $this->assertSame(2, $page->get('page'), 'updated() sent «Next» back to page 1');
        $page->assertSee("Service #{$smallest->id}");
    }

    /**
     * ADV-PM-3 (medium): a coupon «for 2 cycles» after its 2nd cycle is paid: calculatePrice counts cycle 3 and gives the
     * full 20.00, services.price still holds the discounted 10.00 (RenewalBilling ends the coupon when it bills cycle 3,
     * at the full price). The report lists it as «renews at 10.00, 10.00 below the catalog», but it renews at 20.00: every
     * service in the last cycle of a coupon is a false positive. RenewalPrice::for() is the shared «as the billing prices
     * it» (portal Upcoming renewals, revenue forecast) and is not used.
     */
    public function test_bug_a_coupon_in_its_last_cycle_is_listed_though_the_renewal_bills_the_catalog(): void
    {
        $coupon = Coupon::create(['code' => 'HALF2', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 50, 'recurring' => 2]);
        $start = now()->subMonths(2)->startOfDay();
        $service = $this->service('10.00', ['coupon_id' => $coupon->id, 'coupon_started_at' => $start, 'coupon_expired_at' => null,
            'expires_at' => $start->copy()->addMonths(2)->toDateString()]);
        $this->paidInvoiceFor($service, '10.00', $start->toDateString(), $start->copy()->addMonth()->toDateString());
        $this->paidInvoiceFor($service, '10.00', $start->copy()->addMonth()->toDateString(), $start->copy()->addMonths(2)->toDateString());

        $this->assertSame(20.0, (float) RenewalPrice::for($service->fresh(), $start->toImmutable()->addMonths(2), false)->price, 'precondition: the billing renews it at 20.00');

        $this->assertArrayNotHasKey('service' . $service->id, $this->rows(), 'its next renewal is billed at the catalog (cycle 3 > 2): nothing to fix');
    }

    /**
     * ADV-PM-4 (medium): a plan with no price in the service's currency (Plan::price() returns an empty Price, read as
     * 0): the catalog reads 0.00 and every such service is listed «above the catalog» by its whole price. One currency
     * missing on one plan floods the report; there is no catalog price to compare with (as a TLD that no longer offers
     * the period, which the domains half already skips).
     */
    public function test_bug_a_plan_without_a_price_in_the_services_currency_reads_as_free(): void
    {
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $service = $this->service('20.00', ['currency_code' => 'EUR']); // the plan has USD only

        $row = $this->rows()['service' . $service->id] ?? null;

        $this->assertFalse($row !== null && $row['catalog'] === '0.00', 'listed against a catalog of 0.00 that does not exist');
    }

    /**
     * ADV-PM-5 (medium): nothing is kept between requests: every Livewire update (a filter, «Next») runs the whole sweep
     * again, every service through calculatePrice. A filter change on 20 services reads the database per service.
     */
    public function test_bug_a_filter_change_runs_the_whole_sweep_again(): void
    {
        foreach (range(1, 20) as $i) {
            $this->service('25.00');
        }
        $page = $this->asAdmin()->test(PriceMismatches::class)->assertOk();

        DB::enableQueryLog();
        $page->set('direction', 'above');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(20, $queries, "a filter change ran {$queries} queries for 20 services");
    }

    /**
     * ADV-PM-6 (medium): per-service queries in calculatePrice that the eager loads miss: the client's group, the
     * coupon's products and required products, the paid renewal invoices count, each option value's prices. The sweep's
     * query count grows with the services (~2000 at the pilot): 10 → 30 services should not add a query per service.
     */
    public function test_bug_the_sweep_queries_once_per_service(): void
    {
        $coupon = Coupon::create(['code' => 'TEN', 'type' => 'percentage', 'applies_to' => 'all', 'value' => 10, 'recurring' => 0]);
        $option = ConfigOption::create(['name' => 'Disk', 'type' => 'radio', 'sort' => 0]);
        $option->products()->attach($this->product()->product->id);
        $value = ConfigOption::create(['name' => '10 GB', 'sort' => 0, 'parent_id' => $option->id]);
        $plan = Plan::factory()->create(['priceable_id' => $value->id, 'priceable_type' => ConfigOption::class, 'name' => '10 GB',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 1.00, 'currency_code' => 'USD']);
        $make = function (int $count) use ($coupon, $option, $value) {
            foreach (range(1, $count) as $i) {
                $service = $this->service('30.00', ['coupon_id' => $coupon->id]);
                $service->configs()->create(['config_option_id' => $option->id, 'config_value_id' => $value->id]);
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

        $this->assertLessThan(20, $thirty - $ten, "10 → 30 services: {$ten} → {$thirty} queries");
    }

    /**
     * ADV-PM-7 (low): the page needs services.viewAny only, but each row links to the service's edit page, which needs
     * admin.services.update: staff who may read it get a link to a 403. Link it only when they can open it.
     */
    public function test_bug_staff_without_update_get_a_link_to_a_page_they_cannot_open(): void
    {
        $service = $this->service('15.00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.reports.view', 'admin.services.viewAny', 'admin.domains.viewAny'])->create();

        $this->livewireAs($staff)->test(PriceMismatches::class)->assertOk()->assertSee("Service #{$service->id}")
            ->assertDontSee(ServiceResource::getUrl('edit', ['record' => $service->id]), false);
    }

    // ------------------------------------------------------------------------------------------------------- holds

    /** services.price is per unit, as calculatePrice: a quantity of 3 at the catalog's unit price is not listed. */
    public function test_holds_quantity_is_per_unit(): void
    {
        $service = $this->service('20.00', ['quantity' => 3]);

        $this->assertArrayNotHasKey('service' . $service->id, $this->rows());
    }

    /** Tax-inclusive catalog: services.price (net) against calculatePrice's net, 20.00 with 24% in it → 16.13 each. */
    public function test_holds_tax_inclusive_compares_net_with_net(): void
    {
        foreach (['tax_enabled' => [true, 'boolean'], 'tax_type' => ['inclusive', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
        $service = $this->service('16.13');
        $service->client->update(['country' => 'GR']);
        $this->assertSame('16.13', (string) $service->fresh()->calculatePrice(), 'precondition: the catalog nets to 16.13');

        $this->assertArrayNotHasKey('service' . $service->id, $this->rows());
    }

    /** A pending or cancelled service and a free or one-time plan are never listed. */
    public function test_holds_only_live_recurring_services(): void
    {
        $pending = $this->service('5.00', ['status' => Service::STATUS_PENDING]);
        $cancelled = $this->service('5.00', ['status' => Service::STATUS_CANCELLED]);
        $this->service('20.00'); // at the catalog's, so the plan stays (services pay for it)
        $other = $this->createProduct();
        $oneTime = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $other->plan->id,
            'product_id' => $other->product->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => '5.00', 'quantity' => 1]);
        DB::table('plans')->where('id', $other->plan->id)->update(['type' => 'one-time']);
        $third = $this->createProduct();
        Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $third->plan->id,
            'product_id' => $third->product->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => '5.00', 'quantity' => 1]);
        DB::table('plans')->where('id', $third->plan->id)->update(['type' => 'free']);

        $this->assertSame([], array_keys($this->rows()));
        $this->assertSame(5, Service::query()->count(), 'all five are there, none listed');
        $this->assertNotSame((float) $oneTime->price, (float) $oneTime->fresh()->calculatePrice(), 'precondition: the one-time one differs');
        $this->assertSame([Service::STATUS_PENDING, Service::STATUS_CANCELLED], [$pending->status, $cancelled->status]);
    }

    /** price_locked is listed (staff's own price) and says so. */
    public function test_holds_a_locked_price_is_listed_with_its_badge(): void
    {
        $service = $this->service('15.00', ['price_locked' => true]);

        $this->assertTrue($this->rows()['service' . $service->id]['locked']);
        $this->asAdmin()->test(PriceMismatches::class)->assertSee('Price locked');
    }

    /** Domains: two years against the 2-year renew price; a .gr two-character name and a TLD with no price are skipped. */
    public function test_holds_domain_years_staff_priced_and_missing_tld_price(): void
    {
        $twoYears = $this->domain('24.00', ['years' => 2]);
        DomainTldPrice::query()->create(['domain_tld_id' => $twoYears->domain_tld_id, 'operation' => 'renew', 'years' => 2, 'currency_code' => 'EUR', 'price' => '22.00']);
        $noPeriod = $this->domain('50.00', ['years' => 5]); // no 5-year price
        $grTld = DomainTld::query()->create(['tld' => 'gr', 'grace_days' => 0, 'redemption_days' => 0, 'is_active' => true]);
        DomainTldPrice::query()->create(['domain_tld_id' => $grTld->id, 'operation' => 'renew', 'years' => 2, 'currency_code' => 'EUR', 'price' => '20.00']);
        $gr = $this->domain('300.00', ['years' => 2, 'sld' => 'ab', 'tld' => 'gr', 'fqdn' => 'ab.gr', 'domain_tld_id' => $grTld->id]);

        $rows = $this->rows();

        $this->assertSame(['24.00', '22.00', '2.00'], [$rows['domain' . $twoYears->id]['price'], $rows['domain' . $twoYears->id]['catalog'], $rows['domain' . $twoYears->id]['difference']]);
        $this->assertArrayNotHasKey('domain' . $noPeriod->id, $rows);
        $this->assertArrayNotHasKey('domain' . $gr->id, $rows);
    }

    /** Names are escaped: a product name with markup is shown as text. */
    public function test_holds_names_are_escaped(): void
    {
        $this->product()->product->update(['name' => '<img src=x onerror=alert(1)>']);
        $this->service('15.00');

        $this->asAdmin()->test(PriceMismatches::class)->assertOk()
            ->assertDontSee('<img src=x onerror=alert(1)>', false)->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
    }

    /** A page out of range (huge or negative) shows the last or the first page, never an empty table. */
    public function test_holds_a_page_out_of_range_is_clamped(): void
    {
        $service = $this->service('15.00');

        // Given at mount: set() would go through updated(), which puts any page back to 1 (ADV-PM-2).
        $this->asAdmin()->test(PriceMismatches::class, ['page' => 999])->assertSee("Service #{$service->id}");
        $this->asAdmin()->test(PriceMismatches::class, ['page' => -5])->assertSee("Service #{$service->id}");
    }

    /** A staff member without services.viewAny or domains.viewAny cannot open it (it names customers). */
    public function test_holds_access_needs_all_three_permissions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->withPermissions(['admin.reports.view', 'admin.services.viewAny'])->create(), 'staff');
        $this->assertFalse(PriceMismatches::canAccess());
        $this->actingAs(Staff::factory()->withPermissions(['admin.reports.view', 'admin.domains.viewAny'])->create(), 'staff');
        $this->assertFalse(PriceMismatches::canAccess());
        $this->actingAs(Staff::factory()->withPermissions(['admin.reports.view', 'admin.services.viewAny', 'admin.domains.viewAny'])->create(), 'staff');
        $this->assertTrue(PriceMismatches::canAccess());
    }

    /** Every string the page shows has a Greek translation. */
    public function test_holds_greek_translations(): void
    {
        $el = json_decode((string) file_get_contents(lang_path('el.json')), true);
        $view = (string) file_get_contents(resource_path('views/admin/pages/price-mismatches.blade.php'))
            . file_get_contents(app_path('Admin/Pages/PriceMismatches.php')) . file_get_contents(app_path('Services/Reports/PriceMismatch.php'));
        preg_match_all("/(?:__|trans_choice)\\('((?:[^'\\\\]|\\\\.)*)'/", $view, $m);

        $missing = array_values(array_filter(array_unique(array_map(fn ($k) => stripslashes($k), $m[1])), fn ($k) => !isset($el[$k])));
        $this->assertSame([], $missing);
    }
}
