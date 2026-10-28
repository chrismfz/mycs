<?php

namespace Tests\Feature;

use App\Admin\Pages\IncomeByClient;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Credit;
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
use App\Services\Reports\IncomePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Income by client (owner 2026-10-28): who brings in how much, against the same period a year before, the largest's
 * weight, new and lost clients, active services and what they bring in a year. Net, one currency, the same total as
 * Income & forecast.
 */
class ClientIncomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.default_currency' => 'USD']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function client(string $company = '', array $extra = []): Client
    {
        $client = User::factory()->create()->client();
        if ($extra !== []) {
            $client->forceFill($extra)->save();
        }
        if ($company !== '') {
            $client->ensurePrimaryContact()->forceFill(['type' => 'business', 'company_name' => $company])->save();
        }

        return $client->fresh();
    }

    /** An invoice of one line (net $price, 24% on top) for $client, in $status; paid ones on $paidAt. */
    private function invoice(Client $client, float $price, string $status, ?string $paidAt = null, array $line = [], string $currency = 'USD'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => $currency, 'due_at' => $paidAt]);
        $invoice->items()->create($line + ['description' => 'x', 'quantity' => 1, 'price' => $price, 'tax_rate' => '24']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status, 'paid_at' => $paidAt]);

        return $invoice;
    }

    private function service(Client $client, float $price, string $unit, int $period, array $extra = [], string $type = 'recurring'): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'p',
            'billing_unit' => $unit, 'billing_period' => $period, 'type' => $type]);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return Service::factory()->create($extra + ['client_id' => $client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1, 'expires_at' => '2026-11-05']);
    }

    /** @return array<int, array<string, mixed>> by client id */
    private function rows(array $report): array
    {
        return collect($report['rows'])->keyBy('client_id')->all();
    }

    public function test_each_clients_income_this_year_to_date_against_the_same_days_last_year(): void
    {
        $a = $this->client();
        $b = $this->client();
        $this->invoice($a, 100, Invoice::STATUS_PAID, '2026-03-01 09:00:00');
        $this->invoice($a, 50, Invoice::STATUS_PAID, '2026-10-21 23:59:59');            // today, late: in
        $this->invoice($a, 80, Invoice::STATUS_PAID, '2025-10-21 23:00:00');            // the same days last year
        $this->invoice($a, 999, Invoice::STATUS_PAID, '2025-10-22 00:00:00');           // after them: out
        $this->invoice($a, 40, Invoice::STATUS_UNPAID);
        $this->invoice($a, 30, Invoice::STATUS_PAID, '2026-05-01 09:00:00', ['reference_type' => Credit::class]); // add funds
        $this->invoice($a, 70, Invoice::STATUS_PAID, '2026-05-01 09:00:00', [], 'EUR');
        $this->invoice($b, 20, Invoice::STATUS_PAID, '2026-06-01 09:00:00');

        $report = ClientIncome::year('USD', 2026);
        $rows = $this->rows($report);

        $this->assertSame([150 * 100, 80 * 100], [$rows[$a->id]['received'], $rows[$a->id]['last_year']]);
        $this->assertSame([2000, 0], [$rows[$b->id]['received'], $rows[$b->id]['last_year']]);
        $this->assertSame([$a->id, $b->id], array_column($report['rows'], 'client_id'), 'the largest first');
        $this->assertSame([17000, 8000, 2], [$report['total'], $report['last_year_total'], $report['paid']]);
        // The same total as Income & forecast's year so far.
        $this->assertSame(IncomePlan::yearToDate('USD')['received'], number_format($report['total'] / 100, 2, '.', ''));
    }

    public function test_a_past_year_is_compared_with_the_whole_year_before(): void
    {
        $a = $this->client();
        $this->invoice($a, 100, Invoice::STATUS_PAID, '2025-12-31 23:00:00');
        $this->invoice($a, 60, Invoice::STATUS_PAID, '2024-12-20 09:00:00');

        $report = ClientIncome::year('USD', 2025);

        $this->assertFalse($report['to_date']);
        $this->assertSame([10000, 6000], [$report['rows'][0]['received'], $report['rows'][0]['last_year']]);
    }

    public function test_the_largest_weigh_new_clients_and_those_who_stopped_paying(): void
    {
        $clients = [];
        foreach (range(1, 12) as $i) {
            $clients[$i] = $this->client();
            $this->invoice($clients[$i], 10 * $i, Invoice::STATUS_PAID, '2026-04-01 09:00:00');   // 10 … 120
        }
        $this->invoice($clients[12], 5, Invoice::STATUS_PAID, '2025-04-01 09:00:00');          // paid before: not new
        $gone = $this->client();
        $this->invoice($gone, 45, Invoice::STATUS_PAID, '2025-04-01 09:00:00');                // last year only

        $report = ClientIncome::year('USD', 2026);
        $rows = $this->rows($report);

        $this->assertSame(78000, $report['total']);                         // 10 + … + 120
        $this->assertSame(75000, $report['top10']);                         // 30 + … + 120
        $this->assertSame(78000, $report['top50']);
        $this->assertSame(['count' => 11, 'amount' => 66000], $report['new']);
        $this->assertFalse($rows[$clients[12]->id]['new']);
        $this->assertSame(['count' => 1, 'amount' => 4500], $report['lost']);
        $this->assertSame(0, $rows[$gone->id]['received']);
        $this->assertSame(12, $report['paid'], 'a client with nothing this year is not one who paid');
    }

    public function test_a_top_client_falling_over_30_percent_is_flagged(): void
    {
        $fell = $this->client();
        $this->invoice($fell, 69, Invoice::STATUS_PAID, '2026-04-01 09:00:00');
        $this->invoice($fell, 100, Invoice::STATUS_PAID, '2025-04-01 09:00:00');
        $exactly = $this->client();
        $this->invoice($exactly, 70, Invoice::STATUS_PAID, '2026-04-01 09:00:00');
        $this->invoice($exactly, 100, Invoice::STATUS_PAID, '2025-04-01 09:00:00');

        $rows = $this->rows(ClientIncome::year('USD', 2026));

        $this->assertTrue($rows[$fell->id]['fallen']);
        $this->assertFalse($rows[$exactly->id]['fallen'], '30% is not over 30%');
    }

    public function test_active_services_and_what_they_and_the_domains_bring_in_a_year(): void
    {
        $a = $this->client();
        $this->invoice($a, 1, Invoice::STATUS_PAID, '2026-04-01 09:00:00');
        $this->service($a, 10, 'month', 1);                                  // 120 a year
        $this->service($a, 30, 'month', 3, ['quantity' => 2]);               // 60 × 4 = 240
        $this->service($a, 0, 'month', 1, [], 'free');                      // counted, nothing a year
        $this->service($a, 99, 'month', 1, ['status' => Service::STATUS_CANCELLED]);
        $this->service($a, 99, 'month', 1, ['currency_code' => 'EUR']);
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'gr'], ['is_active' => true, 'grace_days' => 15, 'redemption_days' => 0]);
        Domain::factory()->create(['client_id' => $a->id, 'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr', 'domain_tld_id' => $tld->id,
            'status' => DomainStatus::Active, 'expires_at' => '2027-03-01', 'years' => 2, 'currency_code' => 'USD', 'recurring_amount' => '30.00', 'auto_renew' => true]);

        $row = ClientIncome::year('USD', 2026)['rows'][0];

        $this->assertSame([3, (120 + 240 + 15) * 100], [$row['services'], $row['annual']]);
    }

    public function test_a_client_group_narrows_everything(): void
    {
        $group = ClientGroup::create(['name' => 'Resellers', 'discount_percent' => 0]);
        $in = $this->client('', ['client_group_id' => $group->id]);
        $out = $this->client();
        $this->invoice($in, 40, Invoice::STATUS_PAID, '2026-04-01 09:00:00');
        $this->invoice($out, 60, Invoice::STATUS_PAID, '2026-04-01 09:00:00');

        $report = ClientIncome::year('USD', 2026, $group->id);

        $this->assertSame([[$in->id], 4000], [array_column($report['rows'], 'client_id'), $report['total']]);
    }

    public function test_only_staff_who_may_see_invoices_see_the_page(): void
    {
        $this->actingAs(Staff::factory()->withPermissions(['admin.reports.view'])->create())->get(IncomeByClient::getUrl())->assertForbidden();
        $this->actingAs(Staff::factory()->withPermissions(['admin.reports.view', 'admin.invoices.viewAny'])->create())
            ->get(IncomeByClient::getUrl())->assertOk()->assertSee(__('Income by client'));
    }

    public function test_the_page_ranks_searches_sorts_and_exports(): void
    {
        $staff = Staff::factory()->withPermissions(['admin.reports.view', 'admin.invoices.viewAny'])->create();
        $big = $this->client('Alpha Web');
        $small = $this->client('=HYPERLINK("x")');
        $this->invoice($big, 300, Invoice::STATUS_PAID, '2026-04-01 09:00:00');
        $this->invoice($big, 200, Invoice::STATUS_PAID, '2025-04-01 09:00:00');
        $this->invoice($small, 100, Invoice::STATUS_PAID, '2026-04-01 09:00:00');
        $this->service($small, 10, 'month', 1);
        $this->service($small, 10, 'month', 1);

        $page = $this->livewireAs($staff)->test(IncomeByClient::class)
            ->assertSeeInOrder(['Alpha Web', '$300.00', '$200.00', '+50%', '75.0%', '75.0%', '=HYPERLINK', '$100.00', '100.0%'])
            ->assertSeeInOrder([__('All clients (:count)', ['count' => 2]), '$400.00']);

        $page->call('sortBy', 'services')->assertSeeInOrder(['=HYPERLINK', 'Alpha Web']);
        $page->set('search', 'alpha')->assertSee('Alpha Web')->assertDontSee('HYPERLINK');
        $page->set('search', '')->set('sort', 'nonsense')->assertSet('sort', 'received')->set('year', 1999)->assertSet('year', 2026);

        $page->callAction('csv')->assertFileDownloaded('income-by-client-USD-2026-2026-10-21.csv');
    }

    public function test_the_export_never_starts_a_cell_with_a_formula(): void
    {
        $staff = Staff::factory()->withPermissions(['admin.reports.view', 'admin.invoices.viewAny'])->create();
        $client = $this->client('=HYPERLINK("x")');
        $this->invoice($client, 100, Invoice::STATUS_PAID, '2026-04-01 09:00:00');

        $this->actingAs($staff);
        $page = new IncomeByClient;
        $page->mount();
        $response = (fn () => $this->csv())->call($page);
        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('100.00', $csv);
    }
}
