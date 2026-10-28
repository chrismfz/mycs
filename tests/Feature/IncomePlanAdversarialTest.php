<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Reports\IncomePlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #500 (IncomePlan): inputs where a figure breaks docs/income-planning.md («every period once»,
 * «the same days a year before»). Each test fails on 209d604f.
 */
class IncomePlanAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Queue::fake();
        Cache::flush();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(float $price, string $unit, int $period, string $expires): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'p',
            'billing_unit' => $unit, 'billing_period' => $period, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return Service::factory()->create(['client_id' => $this->user->client()->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1, 'expires_at' => $expires]);
    }

    /** An invoice of one line (net $price, 24% on top), in $status; paid ones on $paidAt. */
    private function invoice(float $price, string $status, ?string $due = null, ?string $paidAt = null, array $line = []): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create($line + ['description' => 'x', 'quantity' => 1, 'price' => $price, 'tax_rate' => '24']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status, 'paid_at' => $paidAt]);

        return $invoice->fresh();
    }

    /** @return array<string, array<string, mixed>> by 'Y-m' */
    private function months(string $from = '2026-10-01', int $count = 4): array
    {
        return collect(IncomePlan::months('USD', CarbonImmutable::parse($from), $count))->keyBy(fn ($row) => $row['month']->format('Y-m'))->all();
    }

    /**
     * ADV-500-1 (high). An open renewal line of a service with no period: every open «Hosting» invoice the WHMCS importer
     * brings at the cut-over (ImportFromWhmcs::importInvoiceItems sets reference_type Service, never period_start), and
     * any line staff add by hand. The billing treats it as the service's next period: the cron bills nothing while it is
     * open (RenewalBilling: an open invoice), and paid it takes the first uncovered period (RenewServiceService). Here the
     * November period (monthly 10, due 5 November) is on such an invoice: November expects 10 once, by the invoice.
     * invoicedPeriods() only knows lines with a period_start, so the renewal is counted as well: 20 expected.
     * Fix: an open invoice's Service line without a period covers the service's next renewal (its first one from
     * expires_at not on another line), as RenewServiceService would give it.
     */
    public function test_an_open_renewal_line_without_a_period_is_not_expected_twice(): void
    {
        $service = $this->service(10, 'month', 1, '2026-11-05');
        $this->invoice(10, Invoice::STATUS_UNPAID, '2026-11-05', null, ['reference_type' => Service::class, 'reference_id' => $service->id]);

        $november = $this->months()['2026-11'];

        $this->assertSame(['10.00', '0.00', '10.00'], [$november['invoices'], $november['renewals'], $november['expected']],
            'one period, one invoice: not its renewal as well');
        $this->assertSame('10.00', $this->months()['2026-12']['renewals'], 'December is the next period');
    }

    /**
     * ADV-500-2 (medium). A domain's expiry moved by a registrar sync after its renewal was invoiced (CNIC's renewal date,
     * a day or more off): DomainRenewalService treats an expiry up to SAME_PERIOD_DAYS (31) after the line's start as the
     * same period, so the cron does not bill it again. Here the line starts 30 October (invoice due then, open), the
     * synced expiry is 3 November. October expects 15 by the invoice; November nothing (that period is invoiced).
     * invoicedPeriods() matches by the 'Y-m' of period_start only, so November counts the renewal as well: 15 + 15.
     * Fix: skip by the billing's rule (DomainRenewalService::billedLine: a live line whose period covers the expiry,
     * within SAME_PERIOD_DAYS), not by the calendar month of period_start; for services likewise by the line's period
     * covering the renewal date rather than its month.
     */
    public function test_a_domain_renewal_invoiced_before_a_sync_moved_its_expiry_into_the_next_month_is_counted_once(): void
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['is_active' => true, 'grace_days' => 30, 'redemption_days' => 30]);
        $domain = Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $tld->id, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-03', 'years' => 1,
            'currency_code' => 'USD', 'recurring_amount' => '15.00', 'auto_renew' => true]);
        $this->invoice(15, Invoice::STATUS_UNPAID, '2026-10-30', null,
            ['reference_type' => Domain::class, 'reference_id' => $domain->id, 'period_start' => '2026-10-30', 'period_end' => '2027-10-30']);
        // The billing's own view: this period is billed (the cron bills nothing more for it).
        $this->assertNotNull(DomainRenewalService::billedLine($domain->fresh(), Carbon::parse('2026-11-03')));

        $months = $this->months();

        $this->assertSame('15.00', $months['2026-10']['invoices']);
        $this->assertSame(['0.00', '0.00'], [$months['2026-11']['renewals'], $months['2026-11']['expected']],
            'the period is on the October invoice: not a November renewal as well');
    }

    /**
     * ADV-500-3 (low). «Φέτος έως σήμερα … απέναντι στις ίδιες μέρες πέρσι». On 28 February the year after a leap year
     * (2029-02-28) the year so far is 1 January – 28 February (59 days); the same days a year before are 1 January –
     * 28 February 2028, not 29 February 2028 as well. summary() takes the end of today a year back ($until 1 March 2029
     * → 1 March 2028), so 29 February 2028's 10 comes in: 15 instead of 5. (The reverse case, 29 February itself, is
     * covered by the author's test.) Fix: the end of the same day a year back, CarbonImmutable::now()->subYearNoOverflow()
     * ->addDay()->startOfDay().
     */
    public function test_on_28_february_after_a_leap_year_the_year_before_stops_at_28_february(): void
    {
        Carbon::setTestNow('2029-02-28 12:00:00');
        $this->invoice(5, Invoice::STATUS_PAID, '2028-02-28', '2028-02-28 09:00:00');
        $this->invoice(10, Invoice::STATUS_PAID, '2028-02-29', '2028-02-29 09:00:00');

        $this->assertSame('5.00', IncomePlan::summary('USD')['year']['last_year']);
    }
}
