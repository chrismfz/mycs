<?php

namespace Tests\Feature;

use App\Events\Service\RenewalTaxChanged;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Invoice\CreateInvoiceSnapshotService;
use App\Services\Reports\IncomePlan;
use App\Services\Reports\IncomeReport;
use App\Services\Reports\RevenueForecast;
use App\Services\Tax\EuVat;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 2026-11-07, area tax-reports: adversarial verification of the finder's candidates. Each test fails on main
 * (00268d7f) for the reason in its docblock.
 */
class SweepTaxReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive', 'settings.default_currency' => 'EUR']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function client(string $country): Client
    {
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['country' => $country]);

        return $client->fresh();
    }

    private function service(Client $client, float $price, string $unit, array $extra = [], string $currency = 'EUR'): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'p',
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => $currency]);

        return Service::factory()->create($extra + ['client_id' => $client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => $currency, 'price' => $price, 'quantity' => 1, 'expires_at' => '2026-11-05']);
    }

    /** The tax rate a new line of this account gets now. */
    private function lineRate(Client $client): string
    {
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);

        return (string) $invoice->items()->create(['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1])->tax_rate;
    }

    /**
     * TAX-REPORTS-1 (a): staff change a tax rate (as EuVatRates::check tells them to). The renewal lines are taxed at the
     * new rate, but no RenewalTaxChanged fires, so a Stripe/PayPal subscription keeps charging net × the old rate.
     */
    public function test_tax_reports_1_a_changed_tax_rate_reprices_subscriptions(): void
    {
        $rate = TaxRate::create(['name' => 'VAT', 'rate' => 22, 'country' => 'EE']);
        $client = $this->client('EE');
        $this->service($client, 10, 'month', ['subscription_id' => 'sub_1']);
        $this->assertSame('22.0000', $this->lineRate($client));
        Event::fake([RenewalTaxChanged::class]);

        $rate->update(['rate' => 24]);
        app()->forgetScopedInstances(); // the next request or job: the per-request rate cache starts empty

        $this->assertSame('24.0000', $this->lineRate($client->fresh()), 'the renewal lines charge the new rate');
        Event::assertDispatched(RenewalTaxChanged::class);
    }

    /**
     * TAX-REPORTS-1 (b): a reverse-charged business contact becomes an individual. EuVat::reverseCharged now says no and
     * renewal lines get VAT, but 'type' is not in Contact's wasChanged list, so the subscription keeps charging net.
     */
    public function test_tax_reports_1_a_contact_that_stops_being_a_business_reprices_subscriptions(): void
    {
        config(['settings.eu_vat_enabled' => true, 'settings.eu_vat_home_country' => 'EE']);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'EE']);
        TaxRate::create(['name' => 'MwSt', 'rate' => 19, 'country' => 'DE']);
        $client = User::factory()->create()->client();
        $client->primaryContact->update(['country' => 'DE', 'vat_number' => 'DE129273398', 'type' => 'business', 'company_name' => 'Example']);
        $client->primaryContact->forceFill(['vat_status' => EuVat::STATUS_VALID, 'vat_checked_at' => now()])->save();
        $client = $client->fresh();
        $this->service($client, 10, 'month', ['subscription_id' => 'sub_1']);
        $this->assertSame('0.0000', $this->lineRate($client), 'reverse charged');
        Event::fake([RenewalTaxChanged::class]);

        $client->primaryContact->update(['type' => 'individual']);

        $this->assertSame('19.0000', $this->lineRate($client->fresh()), 'the renewal lines charge VAT now');
        Event::assertDispatched(RenewalTaxChanged::class);
    }

    /**
     * TAX-REPORTS-2 (a): quantity 0 bills a renewal of nothing (RenewalBilling puts the quantity on the line; ADV-503-5
     * fixed it in ClientIncome only). The forecast and the cash plan still count it as 1.
     */
    public function test_tax_reports_2_a_service_of_quantity_zero_is_expected_to_bring_nothing(): void
    {
        $this->service($this->client('US'), 10, 'month', ['quantity' => 0], 'USD');
        $from = CarbonImmutable::parse('2026-11-01');

        $this->assertSame('0.00', IncomePlan::months('USD', $from, 1)[0]['renewals']);
        $this->assertSame('0.00', RevenueForecast::for('USD', $from, 1)['months'][0]['total']);
    }

    /**
     * TAX-REPORTS-2 (b): a weekly plan is renewed by the billing (ADV-503-6 fixed it in ClientIncome only), but the
     * forecast's months() gives null for 'week' and the loop stops: four renewals of 10.00 in November show as 0.
     */
    public function test_tax_reports_2_a_weekly_service_is_expected_in_the_forecast(): void
    {
        $this->service($this->client('US'), 10, 'week', [], 'USD');
        $from = CarbonImmutable::parse('2026-11-01');

        $this->assertSame('40.00', IncomePlan::months('USD', $from, 1)[0]['renewals'], 'due 5, 12, 19, 26 November');
    }

    /**
     * TAX-REPORTS-3: the export's 'country' is the matched tax rule's country, not the customer's: with only an «all»
     * rule (myip's single 24% rule, as WhmcsTaxImport maps it) every row says 'all'.
     */
    public function test_tax_reports_3_the_income_export_shows_the_customers_country(): void
    {
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'all']);
        $client = $this->client('DE');
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'x', 'quantity' => 1, 'price' => 100, 'tax_rate' => '24']);
        app(CreateInvoiceSnapshotService::class)->handle($invoice->fresh());
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => '2026-03-01 10:00:00']);

        $this->assertSame('DE', IncomeReport::year('EUR', 2026)['invoices'][0]['country']);
    }

    /**
     * TAX-REPORTS-4: an invoice whose lines total zero, with an active debit note of +5.00. IncomeReport takes the note
     * as net (F5 of #280); RevenueForecast::netByInvoice drops it, so the dashboard/forecast disagree with the export.
     */
    public function test_tax_reports_4_a_zero_invoice_with_a_note_is_the_same_income_everywhere(): void
    {
        $client = $this->client('EE');
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'upgrade', 'quantity' => 1, 'price' => 10, 'tax_rate' => '0']);
        $invoice->items()->create(['description' => 'waived', 'quantity' => 1, 'price' => -10, 'tax_rate' => '0']);
        $invoice->adjustmentNotes()->create(['type' => 'debit', 'status' => 'active', 'amount' => 5.00, 'description' => 'fee']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => '2026-03-01 10:00:00']);

        $report = IncomeReport::year('EUR', 2026)['totals']['net'];
        $collected = RevenueForecast::collected('EUR', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01'));

        $this->assertSame('5.00', $report, 'the export counts the note');
        $this->assertSame($report, $collected, 'the same income as the dashboard and the forecast');
    }
}
