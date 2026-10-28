<?php

namespace Tests\Feature;

use App\Admin\Pages\IncomeByClient;
use App\Models\Client;
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
 * Adversarial review of #503 (income by client): each test is one finding, failing on e6588094.
 */
class ClientIncomeAdversarialTest extends TestCase
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

    private function client(string $company = '', array $contact = []): Client
    {
        $client = User::factory()->create()->client();
        if ($company !== '' || $contact !== []) {
            $client->ensurePrimaryContact()->forceFill($contact + ($company !== '' ? ['type' => 'business', 'company_name' => $company] : []))->save();
        }

        return $client->fresh();
    }

    private function invoice(Client $client, float $price, ?string $paidAt, array $line = [], string $currency = 'USD'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => $currency, 'due_at' => $paidAt]);
        $invoice->items()->create($line + ['description' => 'x', 'quantity' => 1, 'price' => $price, 'tax_rate' => '24']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => $paidAt]);

        return $invoice;
    }

    private function service(Client $client, float $price, string $unit, int $period, array $extra = []): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'p',
            'billing_unit' => $unit, 'billing_period' => $period, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return Service::factory()->create($extra + ['client_id' => $client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1, 'expires_at' => '2026-11-05']);
    }

    private function staff(): Staff
    {
        return Staff::factory()->withPermissions(['admin.reports.view', 'admin.invoices.viewAny'])->create();
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(array $report): array
    {
        return collect($report['rows'])->keyBy('client_id')->all();
    }

    /**
     * ADV-503-1: a paid invoice whose client is gone (invoices.client_id is nullOnDelete; WHMCS imports of deleted
     * clients) is income on Income & forecast but on no row here. Expected: the total is the Income & forecast page's
     * (as docs/income-planning.md and the page's footnote say), e.g. via an «unknown client» row. Actual: $100 short.
     */
    public function test_adv_503_1_the_total_matches_income_and_forecast_with_an_invoice_whose_client_is_gone(): void
    {
        $a = $this->client();
        $this->invoice($a, 50, '2026-03-01 09:00:00');
        $gone = $this->invoice($this->client(), 100, '2026-04-01 09:00:00');
        DB::table('invoices')->where('id', $gone->id)->update(['client_id' => null]);

        $report = ClientIncome::year('USD', 2026);

        $this->assertSame(IncomePlan::yearToDate('USD')['received'], number_format($report['total'] / 100, 2, '.', ''));
        $past = ClientIncome::year('USD', 2025);
        $this->assertSame(IncomePlan::year('USD', 2025)['totals']['received'], number_format($past['total'] / 100, 2, '.', ''));
    }

    /**
     * ADV-503-2: a paid invoice imported from WHMCS without a payment date (paid_at null; RevenueForecast::paidNet knows
     * them, F4 of #278) is a payment before the year. Expected: the client is not «new». Actual: new (paid_at < from is
     * false for NULL).
     */
    public function test_adv_503_2_a_client_with_an_old_paid_invoice_without_a_date_is_not_new(): void
    {
        $old = $this->client();
        $this->invoice($old, 80, null);
        $this->invoice($old, 20, '2026-05-01 09:00:00');

        $report = ClientIncome::year('USD', 2026);

        $this->assertFalse($this->rows($report)[$old->id]['new']);
        $this->assertSame(0, $report['new']['count']);
    }

    /**
     * ADV-503-3: «new» is «no payment before the year» (docs/income-planning.md), but only this currency's payments are
     * looked at: a client of years in EUR who paid a USD invoice this year shows as a «new client» in USD. Expected: not
     * new. Actual: new.
     */
    public function test_adv_503_3_a_client_who_paid_before_in_another_currency_is_not_new(): void
    {
        $old = $this->client();
        $this->invoice($old, 300, '2024-06-01 09:00:00', [], 'EUR');
        $this->invoice($old, 20, '2026-05-01 09:00:00');

        $this->assertFalse($this->rows(ClientIncome::year('USD', 2026))[$old->id]['new']);
    }

    /**
     * ADV-503-4: a service with a cancellation request is never renewed (RenewalBilling skips it; renewalCells leaves it
     * out with whereDoesntHave('cancellation')). Expected: no yearly value of renewals from it. Actual: $120.
     */
    public function test_adv_503_4_a_service_to_be_cancelled_brings_nothing_in_renewals(): void
    {
        $a = $this->client();
        $this->invoice($a, 1, '2026-04-01 09:00:00');
        $service = $this->service($a, 10, 'month', 1);
        $service->cancellation()->create(['type' => 'end_of_period', 'reason' => 'x']);

        $this->assertSame(0, ClientIncome::year('USD', 2026)['rows'][0]['annual']);
    }

    /**
     * ADV-503-5: quantity 0 bills a renewal of 0 (RenewalBilling puts the service's quantity on the line), the docs'
     * formula is price × quantity. Expected: 0 a year. Actual: $120 (max(1, quantity)).
     */
    public function test_adv_503_5_a_service_of_quantity_zero_brings_nothing_a_year(): void
    {
        $a = $this->client();
        $this->invoice($a, 1, '2026-04-01 09:00:00');
        $this->service($a, 10, 'month', 1, ['quantity' => 0]);

        $this->assertSame(0, ClientIncome::year('USD', 2026)['rows'][0]['annual']);
    }

    /**
     * ADV-503-6: plans by the day or week are offered (ProductResource billing_unit day/week, Plan knows them) and
     * renewed, but RevenueForecast::months knows only months and years. Expected: $10 a week = $520 a year. Actual: 0
     * (the service is counted, its value is not).
     */
    public function test_adv_503_6_a_weekly_service_has_a_yearly_value(): void
    {
        $a = $this->client();
        $this->invoice($a, 1, '2026-04-01 09:00:00');
        $this->service($a, 10, 'week', 1);

        $this->assertSame(52000, ClientIncome::year('USD', 2026)['rows'][0]['annual']);
    }

    /**
     * ADV-503-7: a client whose year nets below zero (a negative invoice: a WHMCS credit, a refund larger than the
     * year's lines) past the top 10 makes the top 10 weigh more than the total. Expected: the concentration bar's widths
     * stay within 0–100%. Actual: «Top 10 · 200%» and a negative width for «11–50».
     */
    public function test_adv_503_7_the_concentration_bar_never_goes_past_100_percent(): void
    {
        foreach (range(1, 11) as $i) {
            $this->invoice($this->client(), 100, '2026-04-01 09:00:00');
        }
        $this->invoice($this->client(), -700, '2026-04-02 09:00:00');

        $report = ClientIncome::year('USD', 2026);
        $this->assertSame(40000, $report['total']);
        $html = $this->livewireAs($this->staff())->test(IncomeByClient::class)->html();

        preg_match_all('/style="width: (-?\d+)%"/', $html, $widths);
        $this->assertNotEmpty($widths[1]);
        foreach ($widths[1] as $width) {
            $this->assertGreaterThanOrEqual(0, (int) $width);
            $this->assertLessThanOrEqual(100, (int) $width);
        }
    }

    /**
     * ADV-503-8: the search escapes % and _ with a backslash but sets no ESCAPE: on SQLite (the CI's first job, and
     * every bin/test) a backslash escapes nothing, so a name with % or _ is never found by itself. The repo's way is an
     * explicit ESCAPE (TicketResource::likeText). Expected: «50% Hosting» found by «50%». Actual: nothing found.
     */
    public function test_adv_503_8_a_search_with_a_percent_sign_finds_the_name_on_every_database(): void
    {
        $this->invoice($this->client('50% Hosting'), 100, '2026-04-01 09:00:00');
        $this->invoice($this->client('Other_Co'), 50, '2026-04-01 09:00:00');

        $this->livewireAs($this->staff())->test(IncomeByClient::class)
            ->set('search', '50%')->assertSee('50% Hosting')
            ->set('search', 'Other_Co')->assertSee('Other_Co');
    }

    /**
     * ADV-503-9: a person is named «first last» on the page, but each field is searched on its own: typing the name as
     * shown finds nobody. Expected: «Maria Papadopoulou» found. Actual: «No income in this period.»
     */
    public function test_adv_503_9_a_client_is_found_by_the_full_name_shown(): void
    {
        $this->invoice($this->client('', ['first_name' => 'Maria', 'last_name' => 'Papadopoulou']), 100, '2026-04-01 09:00:00');

        $this->livewireAs($this->staff())->test(IncomeByClient::class)
            ->set('search', 'Maria Papadopoulou')->assertSee('Papadopoulou')->assertDontSee(__('No income in this period.'));
    }
}
