<?php

namespace Tests\Feature;

use App\Admin\Pages\RevenueForecast;
use App\Admin\Widgets\EstimatedIncome;
use App\Console\Commands\ImportFromWhmcs;
use App\Enums\DomainStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Credit;
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
use App\Services\Invoice\RecordPaymentService;
use App\Services\Reports\RevenueForecast as Forecast;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial review of #275 (revenue forecast, estimated income widget, invoices.paid_at). Fake data only.
 * Failing tests are findings (R1…); «holds_…» tests are attacks that are handled.
 */
class RevenueForecastAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Queue::fake();
        Cache::flush();
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => false, 'settings.default_currency' => 'USD']);
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(Product $product, string $unit, int $period, float $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => "{$unit}{$period}",
            'billing_unit' => $unit, 'billing_period' => $period, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan;
    }

    private function service(float $price, string $unit, int $period, string $expires, array $extra = []): Service
    {
        $product = Product::factory()->create();
        $plan = $this->plan($product, $unit, $period, $price);

        return Service::factory()->create($extra + ['client_id' => $this->user->client()->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => $price, 'quantity' => 1, 'expires_at' => $expires]);
    }

    /** @return array<string, array<string, mixed>> by 'Y-m' */
    private function months(array $forecast): array
    {
        return collect($forecast['months'])->keyBy(fn ($row) => $row['month']->format('Y-m'))->all();
    }

    private function invoice(float $price, ?string $reference = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => now()->addWeek()]);
        $invoice->items()->create(array_filter(['description' => 'Line', 'quantity' => 1, 'price' => $price, 'reference_type' => $reference],
            fn ($value) => $value !== null));

        return $invoice->fresh();
    }

    private function paid(float $price, ?string $reference = null): Invoice
    {
        $invoice = $this->invoice($price, $reference);
        (new RecordPaymentService)->handle($invoice, null, $price, transactionId: 'wire-' . $invoice->id);

        return $invoice->fresh();
    }

    // ---------------------------------------------------------------- forecast: what renews

    /**
     * R1 (High): a domain priced by its TLD (recurring_amount null, the usual case: renewalPrice() falls back to the
     * TLD's renew price, as RenewalBilling bills it) is forecast at 0.00.
     */
    public function test_r1_a_domain_at_its_tlds_price_is_forecast_at_that_price(): void
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'test'], ['is_active' => true, 'grace_days' => 15, 'redemption_days' => 0]);
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'USD', 'price' => '20.00']);
        Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'test', 'fqdn' => 'example.test',
            'domain_tld_id' => $tld->id, 'status' => DomainStatus::Active, 'expires_at' => '2026-12-05', 'years' => 1,
            'currency_code' => 'USD', 'recurring_amount' => null, 'auto_renew' => true]);

        $months = $this->months(Forecast::for('USD'));

        $this->assertSame('20.00', $months['2026-12']['domains'], 'the cron bills it its TLD price: 20.00, not nothing');
    }

    /**
     * R2 (Medium): a service with an end-of-period cancellation request is never billed again (RenewalBilling skips a
     * service with a cancellation; the cron terminates it at its date), yet it is forecast every month for a year.
     */
    public function test_r2_a_service_cancelled_at_the_end_of_its_period_is_not_forecast(): void
    {
        $service = $this->service(10.00, 'month', 1, '2026-11-05');
        DB::table('service_cancellations')->insert(['service_id' => $service->id, 'reason' => 'leaving', 'type' => 'end_of_period',
            'created_at' => now(), 'updated_at' => now()]);

        $months = $this->months(Forecast::for('USD'));

        $this->assertSame(['0.00', '0.00'], [$months['2026-11']['cycles'][1], $months['2027-05']['cycles'][1]],
            'it ends on 2026-11-05: nothing renews');
    }

    /**
     * R3 (Medium): a change of cycle «from the next renewal» (next_plan_id, SVC-14b) bills the next period at the new
     * plan's cycle and price; the forecast keeps the old monthly price every month.
     */
    public function test_r3_a_change_of_cycle_at_the_next_renewal_is_forecast_at_its_new_cycle(): void
    {
        $service = $this->service(10.00, 'month', 1, '2026-11-05');
        $annual = $this->plan($service->product, 'year', 1, 100.00);
        DB::table('services')->where('id', $service->id)->update(['next_plan_id' => $annual->id]);

        $months = $this->months(Forecast::for('USD'));

        $this->assertSame(['0.00', '100.00'], [$months['2026-11']['cycles'][1], $months['2026-11']['cycles'][12]],
            'November renews as the annual plan at 100.00');
        $this->assertSame('0.00', $months['2026-12']['cycles'][1], 'and nothing monthly after it');
    }

    /**
     * R4 (Low): a domain past its expiry with auto renew (Expired, Grace, Redemption) is billed by the cron
     * (RenewalBilling::BILLED_DOMAINS), but the forecast only reads Active ones.
     */
    public function test_r4_a_domain_in_grace_that_the_cron_bills_is_forecast(): void
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'test'], ['is_active' => true, 'grace_days' => 30, 'redemption_days' => 0]);
        Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'late', 'tld' => 'test', 'fqdn' => 'late.test',
            'domain_tld_id' => $tld->id, 'status' => DomainStatus::Grace, 'expires_at' => '2026-10-10', 'years' => 1,
            'currency_code' => 'USD', 'recurring_amount' => '15.00', 'auto_renew' => true]);

        $months = $this->months(Forecast::for('USD'));

        $this->assertSame('15.00', $months['2026-10']['domains']);
    }

    // ---------------------------------------------------------------- paid_at

    /**
     * R5 (High): the WHMCS importer writes invoices without paid_at (it never reads `datepaid`), and it runs after the
     * migration's backfill: every imported paid invoice has paid_at null, so «same month last year» is 0.00 for the
     * whole first year after the cut-over.
     */
    public function test_r5_an_imported_paid_invoice_counts_in_the_month_whmcs_says_it_was_paid(): void
    {
        $this->whmcsInvoice(801, 'Paid', '2025-11-03', [['Hosting', 100.00]]);
        $this->whmcsPayment(7001, 801, 100.00, '2025-11-03 10:00:00');

        $this->import();

        $this->assertSame('2025-11-03', Invoice::query()->findOrFail(801)->paid_at?->toDateString(), 'WHMCS datepaid');
        $this->assertSame('100.00', $this->months(Forecast::for('EUR'))['2026-11']['last_year']);
    }

    /**
     * R6 (High): an imported WHMCS «Add Funds» invoice keeps its line with no reference (only Hosting is mapped), so it
     * is not recognised as a credit purchase: the money counts when it is bought AND again when the credit pays an
     * invoice (WHMCS keeps the paid invoice's lines whole, its `credit` apart).
     */
    public function test_r6_an_imported_add_funds_invoice_is_not_counted_with_the_invoice_its_credit_paid(): void
    {
        $this->whmcsInvoice(811, 'Paid', '2025-11-05', [['AddFunds', 50.00]]);
        $this->whmcsPayment(7011, 811, 50.00, '2025-11-05 10:00:00');
        $this->whmcsInvoice(812, 'Paid', '2025-11-10', [['Hosting', 50.00]], credit: 50.00);

        $this->import();
        // As if R5 were fixed: when WHMCS says they were paid.
        DB::table('invoices')->where('id', 811)->update(['paid_at' => '2025-11-05 10:00:00']);
        DB::table('invoices')->where('id', 812)->update(['paid_at' => '2025-11-10 10:00:00']);

        $this->assertSame('50.00', $this->months(Forecast::for('EUR'))['2026-11']['last_year'], '50 came in, once');
    }

    /**
     * R7 (Medium): staff record a payment with the date it arrived (Add Payment's date, PayInvoices): the transaction
     * takes that date, but paid_at takes the moment it was typed. A transfer of 30 September recorded on 21 October
     * counts in October; the migration's backfill (last payment's date) would say September for the same invoice.
     */
    public function test_r7_paid_at_is_the_date_staff_gave_the_payment(): void
    {
        $invoice = $this->invoice(30.00);

        (new RecordPaymentService)->recordByStaff($invoice, null, 30.0, 'wire-back', paidAt: Carbon::parse('2026-09-30 12:00:00'));

        $this->assertSame('2026-09-30', $invoice->fresh()->paid_at?->toDateString());
        $this->assertSame('0.00', Forecast::for('USD')['current']['collected'], 'paid in September');
    }

    // ---------------------------------------------------------------- what was paid: notes, refunds

    /**
     * R8 (High): a partial refund back to the customer leaves the invoice paid with a refund note (afterRefund); the
     * report adds its lines only, so the refunded money still counts.
     */
    public function test_r8_a_partial_refund_is_taken_off_what_was_collected(): void
    {
        $invoice = $this->paid(100.00);

        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 40.0);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('60.00', Forecast::for('USD')['current']['collected'], '100 in, 40 back');
    }

    /**
     * R9 (High): a partial refund to account credit counts twice: the first invoice keeps its 100 (the refund note is
     * ignored), and the 40 of credit counts again when it pays the next invoice. 100 came in; 140 is reported.
     */
    public function test_r9_a_refund_to_credit_then_spent_is_not_counted_twice(): void
    {
        $first = $this->paid(100.00);
        $next = $this->invoice(40.00);

        ExtensionHelper::refundToCredit($first->transactions()->sole(), 40.0);
        if ($next->fresh()->status !== Invoice::STATUS_PAID) {
            (new RecordPaymentService)->payWithCredit($next->id);
        }

        $this->assertSame(Invoice::STATUS_PAID, $next->fresh()->status);
        $this->assertSame('100.00', Forecast::for('USD')['current']['collected']);
    }

    /**
     * R10 (Medium): an invoice brought down by a credit note (adjustment note, e.g. a goodwill discount) and paid for
     * the rest counts its full lines: 100 reported, 80 paid.
     */
    public function test_r10_a_credit_note_on_a_paid_invoice_is_taken_off(): void
    {
        $invoice = $this->invoice(100.00);
        $invoice->adjustmentNotes()->create(['type' => 'credit', 'amount' => -20, 'description' => 'Goodwill']);
        (new RecordPaymentService)->handle($invoice->fresh(), null, 80.0, transactionId: 'wire-80');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('80.00', Forecast::for('USD')['current']['collected']);
    }

    // ---------------------------------------------------------------- page

    /**
     * R11 (Low): the currency is a public Livewire property; a value that is not a currency is replaced for the figures
     * but not for the CSV's name: the file is named after the forged code (a «/» makes the download fail).
     */
    public function test_r11_the_csv_is_named_after_the_currency_it_holds(): void
    {
        $staff = Staff::factory()->withPermissions(['admin.reports.view'])->create();

        $this->livewireAs($staff)->test(RevenueForecast::class)->set('currency', 'ZZZ')->callAction('csv')
            ->assertFileDownloaded('revenue-forecast-USD-2026-10-21.csv');
    }

    // ---------------------------------------------------------------- holds

    public function test_holds_paid_reopened_and_paid_again_is_dated_by_its_last_payment(): void
    {
        Carbon::setTestNow('2026-09-10 10:00:00');
        $invoice = $this->paid(30.00);
        $this->assertSame('2026-09-10', $invoice->paid_at->toDateString());

        Carbon::setTestNow('2026-10-05 10:00:00');
        (new RecordPaymentService)->deleteTransaction($invoice->transactions()->sole());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame('0.00', Forecast::for('USD')['current']['collected'], 'unpaid: not counted, whatever paid_at says');

        Carbon::setTestNow('2026-10-21 10:00:00');
        (new RecordPaymentService)->handle($invoice->fresh(), null, 30.0, transactionId: 'wire-again');
        $this->assertSame('2026-10-21', $invoice->fresh()->paid_at->toDateString());
        $this->assertSame('30.00', Forecast::for('USD')['current']['collected']);
    }

    public function test_holds_credit_bought_then_spent_counts_once(): void
    {
        $this->paid(50.00, Credit::class);
        $invoice = $this->invoice(50.00);
        (new RecordPaymentService)->payWithCredit($invoice->id);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('50.00', Forecast::for('USD')['current']['collected']);
        $this->assertSame('50.00', Forecast::for('USD')['current']['invoiced'], 'the credit purchase is not invoiced income either');
    }

    public function test_holds_a_fully_refunded_invoice_is_not_counted(): void
    {
        $invoice = $this->paid(100.00);
        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 100.0);

        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->assertSame('0.00', Forecast::for('USD')['current']['collected']);
    }

    public function test_holds_a_negative_line_is_taken_off(): void
    {
        $invoice = $this->invoice(100.00);
        $invoice->items()->create(['description' => 'Discount', 'quantity' => 1, 'price' => -25]);
        (new RecordPaymentService)->handle($invoice->fresh(), null, 75.0, transactionId: 'wire-75');

        $this->assertSame('75.00', Forecast::for('USD')['current']['collected']);
    }

    public function test_holds_net_of_tax_on_the_collected_figure(): void
    {
        config(['settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'x', 'quantity' => 2, 'price' => 50, 'tax_rate' => '24']);
        $invoice = $invoice->fresh();
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->total, transactionId: 'wire-tax');

        $this->assertSame('100.00', Forecast::for('USD')['current']['collected']);
    }

    public function test_holds_quantity_and_a_staff_price_renew_as_billed(): void
    {
        $this->service(7.33, 'month', 1, '2026-11-02', ['quantity' => 3]);

        $months = $this->months(Forecast::for('USD'));

        $this->assertSame('21.99', $months['2026-11']['cycles'][1]);
    }

    public function test_holds_a_suspended_or_one_time_service_is_not_forecast(): void
    {
        $this->service(10.00, 'month', 1, '2026-11-02', ['status' => 'suspended']);
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'once',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'one-time']);
        Service::factory()->create(['client_id' => $this->user->client()->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 50, 'quantity' => 1, 'expires_at' => '2026-11-02']);

        $this->assertSame('0.00', Forecast::for('USD')['total']);
    }

    public function test_holds_the_annual_estimate_rounds_once(): void
    {
        // 3 × 0.01 triennially: WHMCS 0.03 / 3 = 0.01; rounding each one would give 0.00.
        foreach (range(1, 3) as $ignored) {
            $this->service(0.01, 'year', 3, '2027-01-01');
        }
        $this->service(1116.00, 'year', 3, '2027-01-01');

        $this->assertSame('372.01', Forecast::annualEstimate()['USD']['annual']);
    }

    public function test_holds_staff_without_the_permission_do_not_see_the_widget(): void
    {
        $this->service(10.00, 'month', 1, '2026-11-02');

        $this->actingAs(Staff::factory()->withPermissions(['admin.clients.viewAny'])->create());
        $this->assertFalse(EstimatedIncome::canView());

        $this->actingAs(Staff::factory()->withPermissions(['admin.reports.view'])->create());
        $this->assertTrue(EstimatedIncome::canView());
    }

    public function test_holds_a_forged_currency_shows_the_default_ones_figures(): void
    {
        $this->service(10.00, 'month', 1, '2026-11-02');
        $staff = Staff::factory()->withPermissions(['admin.reports.view'])->create();

        $page = $this->livewireAs($staff)->test(RevenueForecast::class)->set('currency', "USD' OR 1=1 --");

        $this->assertSame('10.00', $page->instance()->forecast()['months'][1]['total']);
    }

    public function test_holds_the_backfill_dates_a_paid_invoice_by_its_last_payment(): void
    {
        $withPayments = $this->paid(30.00);
        DB::table('invoice_transactions')->where('invoice_id', $withPayments->id)->update(['created_at' => '2025-03-04 09:00:00']);
        DB::table('invoice_transactions')->insert(['invoice_id' => $withPayments->id, 'amount' => 0.01, 'status' => 'failed',
            'created_at' => '2025-06-01 09:00:00', 'updated_at' => '2025-06-01 09:00:00']);
        $open = $this->invoice(10.00);
        DB::table('invoices')->where('id', $withPayments->id)->update(['updated_at' => '2025-08-08 08:00:00']);

        $migration = require database_path('migrations/2026_12_12_000000_invoices_paid_at.php');
        $migration->down();
        $migration->up();

        $this->assertSame('2025-03-04', substr((string) DB::table('invoices')->where('id', $withPayments->id)->value('paid_at'), 0, 10),
            'its last succeeded payment, not a failed one nor its last change');
        $this->assertNull(DB::table('invoices')->where('id', $open->id)->value('paid_at'));
    }

    // ---------------------------------------------------------------- WHMCS import harness (as ImportMassPayTest)

    private ?PDO $whmcs = null;

    private function whmcs(): PDO
    {
        if ($this->whmcs === null) {
            $this->whmcs = new PDO('sqlite::memory:');
            $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
            $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
            $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
                status TEXT, total REAL, credit REAL, taxrate REAL)');
            $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
                amount REAL, taxed INT)');
            $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
                amountout REAL, fees REAL, transid TEXT)');
            $this->whmcs->exec('INSERT INTO tblcurrencies VALUES (1, \'EUR\')');
            $this->whmcs->exec('INSERT INTO tblclients VALUES (' . $this->user->client()->id . ', 1)');
        }

        return $this->whmcs;
    }

    /** @param  list<array{0: string, 1: float}>  $items */
    private function whmcsInvoice(int $id, string $status, string $paid, array $items, float $credit = 0): void
    {
        $client = $this->user->client()->id;
        $total = array_sum(array_column($items, 1)) - $credit;
        $this->whmcs()->exec("INSERT INTO tblinvoices VALUES ({$id}, {$client}, '', '{$paid}', '{$paid}', '{$paid} 10:00:00', '{$status}', {$total}, {$credit}, 0)");
        static $line = 9000;
        foreach ($items as [$type, $amount]) {
            $line++;
            $this->whmcs()->exec("INSERT INTO tblinvoiceitems VALUES ({$line}, {$id}, {$client}, '{$type}', 0, 'Line', {$amount}, 0)");
        }
    }

    private function whmcsPayment(int $id, int $invoice, float $in, string $date): void
    {
        $client = $this->user->client()->id;
        $this->whmcs()->exec("INSERT INTO tblaccounts VALUES ({$id}, {$client}, {$invoice}, 'banktransfer', '{$date}', {$in}, 0, 0, 'tx_{$id}')");
    }

    private function import(): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs());
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        foreach (['importInvoices', 'importInvoiceItems', 'importPayments', 'importMassPay'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
    }
}
