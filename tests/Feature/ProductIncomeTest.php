<?php

namespace Tests\Feature;

use App\Admin\Pages\IncomeByProduct;
use App\Enums\DomainStatus;
use App\Models\Category;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Staff;
use App\Models\User;
use App\Services\Reports\IncomePlan;
use App\Services\Reports\ProductIncome;
use App\Services\Reports\RevenueForecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Income by product (owner 2026-10-28): each paid invoice's net shared out over its lines, to its service's product and
 * category, a domain's extension, or «Other»; against the same period a year before; active services, income per
 * service, the yearly value of renewals, new and cancelled. Net, one currency, the same total as Income & forecast.
 */
class ProductIncomeTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.default_currency' => 'USD']);
        $this->client = User::factory()->create()->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, ?Category $category): Product
    {
        return Product::factory()->create(['name' => $name, 'category_id' => $category?->id]);
    }

    private function category(string $name): Category
    {
        return Category::create(['name' => $name, 'slug' => str($name)->slug()->toString()]);
    }

    private function service(Product $product, float $price, array $extra = [], string $unit = 'month', int $period = 1): Service
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'p',
            'billing_unit' => $unit, 'billing_period' => $period, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return Service::factory()->create($extra + ['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1, 'expires_at' => '2026-11-05']);
    }

    private function domain(string $sld, array $extra = []): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'grace_days' => 15, 'redemption_days' => 0]);

        return Domain::factory()->create($extra + ['client_id' => $this->client->id, 'sld' => $sld, 'tld' => 'gr', 'fqdn' => $sld . '.gr',
            'domain_tld_id' => $tld->id, 'status' => DomainStatus::Active, 'expires_at' => '2027-03-01', 'years' => 2,
            'currency_code' => 'USD', 'recurring_amount' => '30.00', 'auto_renew' => true]);
    }

    /**
     * A paid invoice of these lines (net prices, 24% on top), paid on $paidAt.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function paid(string $paidAt, array $lines, ?Client $client = null, string $currency = 'USD', ?\Closure $open = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => ($client ?? $this->client)->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => $currency]);
        foreach ($lines as $line) {
            $invoice->items()->create($line + ['description' => 'x', 'quantity' => 1, 'tax_rate' => '24']);
        }
        if ($open !== null) {
            $open($invoice); // while it is open (a paid one keeps its total)
        }
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => $paidAt]);

        return $invoice;
    }

    /** @return array<string, array<string, mixed>> groups by name, each with its items by name */
    private function byName(array $report): array
    {
        return collect($report['groups'])->keyBy('name')->map(fn ($group) => $group + ['by' => collect($group['items'])->keyBy('name')->all()])->all();
    }

    public function test_each_line_counts_for_its_product_category_domain_or_other(): void
    {
        $hosting = $this->category('Shared Hosting');
        $semi = $this->category('Semi Dedicated');
        $b10 = $this->service($this->product('Business 10', $hosting), 10);
        $b20 = $this->service($this->product('Business 20', $hosting), 20);
        $sd = $this->service($this->product('SD 1', $semi), 100);
        $domain = $this->domain('example');
        $this->paid('2026-03-01 09:00:00', [
            ['price' => 10, 'reference_type' => Service::class, 'reference_id' => $b10->id],
            ['price' => 20, 'reference_type' => Service::class, 'reference_id' => $b20->id],
            ['price' => 15, 'reference_type' => Domain::class, 'reference_id' => $domain->id],
            ['price' => 7, 'description' => 'Setup by hand'],
            ['price' => 50, 'reference_type' => Credit::class],                 // add funds: not income
        ]);
        $this->paid('2026-06-01 09:00:00', [['price' => 100, 'reference_type' => Service::class, 'reference_id' => $sd->id]]);
        $this->paid('2026-06-01 09:00:00', [['price' => 99, 'reference_type' => Service::class, 'reference_id' => $sd->id]], null, 'EUR');

        $report = ProductIncome::year('USD', 2026);
        $groups = $this->byName($report);

        $this->assertSame(['Semi Dedicated', 'Shared Hosting', __('Domains'), __('Other (no service)')], array_column($report['groups'], 'name'));
        $this->assertSame([10000, 3000, 1500, 700], array_column($report['groups'], 'received'));
        $this->assertSame([2000, 1000], [$groups['Shared Hosting']['by']['Business 20']['received'], $groups['Shared Hosting']['by']['Business 10']['received']]);
        $this->assertSame(1500, $groups[__('Domains')]['by']['.gr']['received']);
        $this->assertSame([], $groups[__('Other (no service)')]['items']);
        $this->assertSame(15200, $report['total']);
        $this->assertSame(IncomePlan::yearToDate('USD')['received'], RevenueForecast::money($report['total']));
    }

    public function test_an_invoices_notes_are_shared_out_over_its_lines_to_the_cent(): void
    {
        $category = $this->category('Hosting');
        $a = $this->service($this->product('A', $category), 10);
        $b = $this->service($this->product('B', $category), 10);
        $this->paid('2026-03-01 09:00:00', [
            ['price' => 10, 'reference_type' => Service::class, 'reference_id' => $a->id],
            ['price' => 20, 'reference_type' => Service::class, 'reference_id' => $b->id],
        ], open: fn (Invoice $invoice) => $invoice->adjustmentNotes()->create(['type' => 'credit', 'amount' => -10, 'description' => 'Goodwill']));

        $report = ProductIncome::year('USD', 2026);
        $items = $this->byName($report)['Hosting']['by'];

        // 30 net, 37.20 gross, a credit of 10 gross: 30 − 10 × 30 / 37.20 = 21.94; A a third (7.31), B the rest (14.63).
        $this->assertSame(2194, $report['total']);
        $this->assertSame([731, 1463], [$items['A']['received'], $items['B']['received']]);
        $this->assertSame(IncomePlan::yearToDate('USD')['received'], RevenueForecast::money($report['total']));
    }

    public function test_an_imported_domain_line_counts_for_its_domain_when_it_names_it(): void
    {
        $domain = $this->domain('example');
        $other = User::factory()->create()->client();
        // As the WHMCS importer writes a «Domain» line: no type, the domain's id.
        $this->paid('2026-03-01 09:00:00', [['price' => 15, 'reference_id' => $domain->id, 'description' => 'Domain Renewal - example.gr - 2 Year/s']]);
        $this->paid('2026-03-01 09:00:00', [['price' => 8, 'reference_id' => $domain->id, 'description' => 'Upgrade of something else']]);
        $this->paid('2026-03-01 09:00:00', [['price' => 9, 'reference_id' => $domain->id, 'description' => 'Domain Renewal - example.gr']], $other);

        $groups = $this->byName(ProductIncome::year('USD', 2026));

        $this->assertSame(1500, $groups[__('Domains')]['received']);
        $this->assertSame(1700, $groups[__('Other (no service)')]['received'], 'not its name, or another client\'s: other');
    }

    public function test_services_per_service_yearly_value_new_and_cancelled(): void
    {
        $category = $this->category('Hosting');
        $product = $this->product('Business 10', $category);
        $old = $this->service($product, 10, ['created_at' => '2025-02-01 09:00:00']);
        $this->service($product, 10, ['created_at' => '2026-02-01 09:00:00']);
        $leaving = $this->service($product, 10, ['created_at' => '2026-03-01 09:00:00']);
        ServiceCancellation::create(['service_id' => $leaving->id, 'reason' => 'x', 'type' => 'end_of_period']);
        $this->service($product, 10, ['status' => Service::STATUS_CANCELLED, 'created_at' => '2024-01-01 09:00:00']);
        $this->paid('2026-04-01 09:00:00', [['price' => 90, 'reference_type' => Service::class, 'reference_id' => $old->id]]);
        $this->domain('one', ['created_at' => '2026-05-01 09:00:00']);
        $this->domain('two', ['created_at' => '2020-05-01 09:00:00', 'status' => DomainStatus::Cancelled, 'expires_at' => '2026-04-01', 'auto_renew' => false]);

        $groups = $this->byName(ProductIncome::year('USD', 2026));
        $item = $groups['Hosting']['by']['Business 10'];

        $this->assertSame([3, 9000, 24000, 2, 1], [$item['services'], $item['received'], $item['annual'], $item['new'], $item['ended']],
            'active 3; 2 a year at 120 each (the one to be cancelled renews nothing); new this year 2; 1 cancellation');
        $gr = $groups[__('Domains')]['by']['.gr'];
        $this->assertSame([1, 1500, 1, 1], [$gr['services'], $gr['annual'], $gr['new'], $gr['ended']]);
    }

    public function test_against_the_same_days_last_year_and_a_past_year_against_the_whole_year_before(): void
    {
        $category = $this->category('Hosting');
        $service = $this->service($this->product('A', $category), 10);
        $line = fn (float $price) => [['price' => $price, 'reference_type' => Service::class, 'reference_id' => $service->id]];
        $this->paid('2026-10-21 23:00:00', $line(40));
        $this->paid('2025-10-21 23:00:00', $line(30));
        $this->paid('2025-12-01 09:00:00', $line(20));                         // after the same days: 2025's only
        $this->paid('2024-06-01 09:00:00', $line(10));

        $now = $this->byName(ProductIncome::year('USD', 2026))['Hosting'];
        $past = $this->byName(ProductIncome::year('USD', 2025))['Hosting'];

        $this->assertSame([4000, 3000], [$now['received'], $now['last_year']]);
        $this->assertSame([5000, 1000], [$past['received'], $past['last_year']]);
    }

    public function test_the_page_opens_a_category_sorts_and_exports(): void
    {
        $this->actingAs(Staff::factory()->withPermissions(['admin.clients.viewAny'])->create())->get(IncomeByProduct::getUrl())->assertForbidden();
        $staff = Staff::factory()->withPermissions(['admin.reports.view'])->create();
        $hosting = $this->category('Shared Hosting');
        $semi = $this->category('Semi Dedicated');
        $b10 = $this->service($this->product('Business 10', $hosting), 10);
        foreach (range(1, 3) as $i) {
            $this->service($this->product('Business ' . (10 + $i), $hosting), 10);
        }
        $sd = $this->service($this->product('SD 1', $semi), 100);
        $this->paid('2026-03-01 09:00:00', [['price' => 60, 'reference_type' => Service::class, 'reference_id' => $b10->id]]);
        $this->paid('2025-03-01 09:00:00', [['price' => 80, 'reference_type' => Service::class, 'reference_id' => $b10->id]]);
        $this->paid('2026-03-01 09:00:00', [['price' => 300, 'reference_type' => Service::class, 'reference_id' => $sd->id]]);

        $this->actingAs($staff)->get(IncomeByProduct::getUrl())->assertOk()->assertSee(__('Income by product'));
        $page = $this->livewireAs($staff)->test(IncomeByProduct::class)
            ->assertSeeInOrder(['Semi Dedicated', '$300.00', '83.3%', '$300.00', 'Shared Hosting', '$60.00', '$80.00', '-25%'])
            ->assertSeeInOrder([__('The largest rise'), 'SD 1', __('The largest fall'), 'Business 10 -25%'])
            ->assertDontSee('Business 13');

        $page->call('toggle', 'category:' . $hosting->id)->assertSee('Business 13');
        $page->call('sortBy', 'services')->assertSeeInOrder(['Shared Hosting', 'Semi Dedicated']);
        $page->set('sort', 'nonsense')->assertSet('sort', 'received')->set('year', 1999)->assertSet('year', 2026);
        $page->callAction('csv')->assertFileDownloaded('income-by-product-USD-2026-2026-10-21.csv');
    }
}
