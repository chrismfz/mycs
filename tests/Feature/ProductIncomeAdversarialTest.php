<?php

namespace Tests\Feature;

use App\Admin\Pages\IncomeByProduct;
use App\Enums\DomainStatus;
use App\Models\Category;
use App\Models\Client;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Reports\ClientIncome;
use App\Services\Reports\ProductIncome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #504 (income by product): each test fails on 4735c081 and states what is expected.
 */
class ProductIncomeAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
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

    private function service(Product $product, float $price, array $extra = []): Service
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'p',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
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

    /** @param list<array<string, mixed>> $lines */
    private function paid(string $paidAt, array $lines, ?\Closure $open = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        foreach ($lines as $line) {
            $invoice->items()->create($line + ['description' => 'x', 'quantity' => 1, 'tax_rate' => '24']);
        }
        if ($open !== null) {
            $open($invoice);
        }
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => $paidAt]);

        return $invoice;
    }

    /** @return array<string, array<string, mixed>> */
    private function byName(array $report): array
    {
        return collect($report['groups'])->keyBy('name')->map(fn ($group) => $group + ['by' => collect($group['items'])->keyBy('name')->all()])->all();
    }

    private function staff(): Staff
    {
        return Staff::factory()->withPermissions(['admin.reports.view'])->create();
    }

    /**
     * ADV-504-1 (low). The share-out rounds every line but the last half up on its own and gives the last what is left, so
     * the rounding of all the others lands on the last line's product: with many lines (a reseller's invoice of 20
     * renewals) and a note that halves the invoice, 19 lines of 12.35 get 6.18 each (6.175 up) and the 20th gets 6.08,
     * 9½ cents off. Expected: no product more than a cent away from its exact share (6.175 → 6.17 or 6.18), i.e. a
     * cumulative rounding (each part = round(running total × net / sum) − the previous one) or a largest remainder.
     */
    public function test_the_share_out_does_not_pile_the_rounding_of_many_lines_on_the_last(): void
    {
        $category = $this->category('Hosting');
        $a = $this->service($this->product('A', $category), 12.35);
        $b = $this->service($this->product('B', $category), 12.35);
        $lines = array_fill(0, 19, ['price' => 12.35, 'tax_rate' => '0', 'reference_type' => Service::class, 'reference_id' => $a->id]);
        $lines[] = ['price' => 12.35, 'tax_rate' => '0', 'reference_type' => Service::class, 'reference_id' => $b->id];
        // 247.00 net and gross, a credit note of half of it: 123.50 to share out, 6.175 a line.
        $this->paid('2026-03-01 09:00:00', $lines, fn (Invoice $invoice) => $invoice->adjustmentNotes()->create(['type' => 'credit', 'amount' => -123.50, 'description' => 'Half off']));

        $report = ProductIncome::year('USD', 2026);
        $items = $this->byName($report)['Hosting']['by'];

        $this->assertSame(12350, $report['total']);
        $this->assertEqualsWithDelta(617.5, $items['B']['received'], 1, 'B: one line of 6.175, a cent at most away');
        $this->assertEqualsWithDelta(19 * 617.5, $items['A']['received'], 1, 'A: 19 lines of 6.175, a cent at most away');
    }

    /**
     * ADV-504-2 (low). The card «The largest income» gives the largest category's share of the total, and the total nets
     * «Other» below zero (a WHMCS credit, a refund line larger than the rest: ADV-503-7): Hosting 100 against a total of
     * 50 reads «200.0% of the income». The sibling page measures the largest against the positive amounts
     * (docs/income-planning.md, ADV-503-7). Expected: the card's share over the positive groups' sum, never above 100%.
     */
    public function test_the_largest_card_never_weighs_more_than_all_the_income(): void
    {
        $service = $this->service($this->product('Business 10', $this->category('Hosting')), 100);
        $this->paid('2026-03-01 09:00:00', [['price' => 100, 'reference_type' => Service::class, 'reference_id' => $service->id]]);
        $this->paid('2026-04-01 09:00:00', [['price' => -50, 'description' => 'Refund of an old invoice']]);

        $this->livewireAs($this->staff())->test(IncomeByProduct::class)
            ->assertDontSee('200.0%')
            ->assertSeeInOrder([__('The largest income'), 'Hosting', '100.0%']);
    }

    /**
     * ADV-504-3 (medium). An expired domain on automatic renewal is renewed by the cron (RenewalBilling::BILLED_DOMAINS)
     * and counts in «Yearly value of renewals» (ClientIncome::renewingDomains), but it is not active (grace/redemption
     * only), so an extension whose only domain is expired and brought nothing in either year is dropped by group()
     * («nothing in either year and no active service») together with its yearly value. The per-client page (same client,
     * who has income) counts it: the two pages disagree on the yearly value. Expected: an item with a yearly value is
     * kept (or its value is not lost), so the products' yearly values add up to the clients'.
     */
    public function test_an_expired_domain_renewing_keeps_its_yearly_value(): void
    {
        $service = $this->service($this->product('Business 10', $this->category('Hosting')), 10);
        $this->paid('2026-03-01 09:00:00', [['price' => 10, 'reference_type' => Service::class, 'reference_id' => $service->id]]);
        $this->domain('lapsed', ['created_at' => '2020-01-01 09:00:00', 'status' => DomainStatus::Expired, 'expires_at' => '2026-10-10']);

        $byProduct = array_sum(array_column(ProductIncome::year('USD', 2026)['groups'], 'annual'));
        $byClient = array_sum(array_column(ClientIncome::year('USD', 2026)['rows'], 'annual'));

        $this->assertSame($byClient, $byProduct, 'the yearly value of renewals by product adds up to the one by client');
    }

    /**
     * ADV-504-4 (low/medium, the owner's call). «New» counts every service row created in the year, whatever its status:
     * an order never paid (pending, then cancelled by the cron for non-payment) is a «new service» of its product. A
     * shop with many abandoned orders shows many new services and no income behind them. Expected: an order that never
     * went live (still pending) is not new — e.g. count only services that are or were active (not pending, and not
     * cancelled without ever being activated).
     */
    public function test_an_order_never_paid_is_not_a_new_service(): void
    {
        $product = $this->product('Business 10', $this->category('Hosting'));
        $live = $this->service($product, 10, ['created_at' => '2026-02-01 09:00:00']);
        $this->service($product, 10, ['created_at' => '2026-09-01 09:00:00', 'status' => Service::STATUS_PENDING]);
        $this->paid('2026-03-01 09:00:00', [['price' => 10, 'reference_type' => Service::class, 'reference_id' => $live->id]]);

        $item = $this->byName(ProductIncome::year('USD', 2026))['Hosting']['by']['Business 10'];

        $this->assertSame(1, $item['new'], 'the pending order is not a new service');
    }

    /**
     * ADV-504-5 (low). An imported line with no type goes to a domain when its description contains the domain's name,
     * as a substring: «example.gr» is found in «myexample.gr». A WHMCS Addon/Upgrade/PromoHosting line keeps its own
     * relid (an addon's or a hosting's id), which can be a domain's id of the same client; its description names the
     * hosting's domain. Expected: the name matched as a whole name (not preceded by a letter, digit, «-» or «.»), so
     * «Addon - myexample.gr» is not example.gr's and stays in «Other».
     */
    public function test_an_imported_line_naming_a_longer_domain_is_not_this_one(): void
    {
        $domain = $this->domain('example');
        $this->paid('2026-03-01 09:00:00', [['price' => 15, 'reference_id' => $domain->id, 'description' => 'Backup Addon - myexample.gr (01/03/2026 - 31/03/2026)']]);

        $groups = $this->byName(ProductIncome::year('USD', 2026));

        $this->assertSame(1500, $groups[__('Other (no service)')]['received'] ?? 0);
        $this->assertSame(0, $groups[__('Domains')]['received'] ?? 0);
    }

    /**
     * ADV-504-6 (low). With nothing received this year (January, or a currency that stopped) the card «The largest
     * income» still names the group with the most last year, at «0.0% of the income»: the groups are sorted by income,
     * then last year's. Expected: no largest income when no group brought anything in («—»).
     */
    public function test_no_largest_income_when_nothing_came_in(): void
    {
        $service = $this->service($this->product('Business 10', $this->category('Hosting')), 10);
        $this->paid('2025-03-01 09:00:00', [['price' => 10, 'reference_type' => Service::class, 'reference_id' => $service->id]]);

        $page = $this->livewireAs($this->staff())->test(IncomeByProduct::class);

        $this->assertNull($page->instance()->highlights($page->instance()->report())['largest']);
    }
}
