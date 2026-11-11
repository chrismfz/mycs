<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsForecastParity;
use App\Services\Import\WhmcsLinePeriods;
use App\Services\Invoice\EditPayment;
use App\Services\Invoice\ProcessPaidInvoiceService;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\UndoRefund;
use App\Services\Money\MoneyCheck;
use App\Services\Money\UnbilledServices;
use App\Services\ServiceUpgrade\ChangeCycle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * The importer gives the last paid line and the open renewal of each live service their period, from WHMCS's own due
 * date on the line (WhmcsLinePeriods, owner 2026-11-11): only on an exact match with the service's due date by this app's
 * arithmetic, anything else left as it came and named. Through the importer's own steps; invented rows, no network.
 */
class ImportLinePeriodsTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow('2026-09-20 10:00:00');
        $user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->productId = $product->product->id;
        $this->whmcs($user->client()->id, $this->productId);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(): void
    {
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => false, 'settings.cronjob_order_suspend' => 8,
            'settings.cronjob_order_terminate' => 60]);
    }

    private function cronOn(string $day): void
    {
        Carbon::setTestNow($day . ' 03:00:00');
        $this->settings();
        $this->artisan('app:cron-job');
    }

    /** @return array{0: string|null, 1: string|null, 2: string|null} the period and the applied mark of an invoice's only line */
    private function line(int $invoice): array
    {
        $line = InvoiceItem::query()->where('invoice_id', $invoice)->sole();

        return [$line->period_start?->toDateString(), $line->period_end?->toDateString(), $line->applied_at?->toDateTimeString()];
    }

    /** @return list<string> the open invoices billing the service, as «#id period_start» */
    private function open(int $service): array
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service)
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', Invoice::OPEN_STATUSES))->orderBy('id')->get()
            ->map(fn (InvoiceItem $line) => "#{$line->invoice_id} " . ($line->period_start?->toDateString() ?? 'no period'))->all();
    }

    /** @return list<array<string, mixed>> every row of a table, as it is */
    private function table(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all();
    }

    private function refused(\Closure $action): string
    {
        try {
            $action();
        } catch (DisplayException $e) {
            return $e->getMessage();
        }
        $this->fail('Not refused.');
    }

    public function test_an_exact_match_writes_the_last_paid_line_and_the_open_renewal(): void
    {
        // Paid to 2026-10-10 (#480, its line due 09-10), and the renewal from 10-10 invoiced already (#520).
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(400, 'Paid', [31 => 10.0], due: '2026-08-10', date: '2026-08-01');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(520, 'Unpaid', [31 => 10.0], due: '2026-10-10');
        // Suspended for non-payment: paid to 2026-08-25, its renewal from there unpaid.
        $this->whmcsService(42, '2026-08-25', status: 'Suspended');
        $this->whmcsInvoice(440, 'Paid', [42 => 10.0], due: '2026-07-25', date: '2026-07-18');
        $this->whmcsInvoice(542, 'Unpaid', [42 => 10.0], due: '2026-08-25');
        $this->importWhmcs();

        $this->assertSame(['2026-09-10', '2026-10-10', '2026-09-03 10:00:00'], $this->line(480), 'the last paid line: its period, applied when its invoice was paid');
        $this->assertSame(['2026-10-10', '2026-11-10', null], $this->line(520), 'the open renewal: the period from the due date, not applied');
        $this->assertSame([null, null, null], $this->line(400), 'an older paid line stays as it came');
        $this->assertSame(['2026-07-25', '2026-08-25', '2026-07-20 10:00:00'], $this->line(440));
        $this->assertSame(['2026-08-25', '2026-09-25', null], $this->line(542));
        $this->assertSame(['2026-10-10', '2026-08-25'], [Service::query()->findOrFail(31)->expires_at->toDateString(), Service::query()->findOrFail(42)->expires_at->toDateString()],
            'no due date moved');
        $said = $this->importSaid();
        $this->assertStringContainsString('Line periods: of 2 live recurring service(s) and addon(s), 2 last paid line(s) with their period by WHMCS\'s due date'
            . ' and 0 pro rata first order(s), applied; 2 open renewal(s) with theirs.', $said);
        $this->assertStringNotContainsString('got no period', $said);
        $this->assertSame([], (new MoneyCheck)->run(), 'nothing for staff in the money check');
    }

    public function test_a_mismatch_writes_nothing_and_names_the_service(): void
    {
        // Its date moved by hand in WHMCS (10-15): the paid line due 09-10 is not one cycle before it, and the unpaid
        // invoice due 10-10 is not on it.
        $this->whmcsService(31, '2026-10-15');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(520, 'Unpaid', [31 => 10.0], due: '2026-10-10');
        // No paid line at all (made by staff with no invoice): its open renewal is still its renewal.
        $this->whmcsService(32, '2026-09-25');
        $this->whmcsInvoice(521, 'Unpaid', [32 => 10.0], due: '2026-09-25');
        // Two unpaid invoices for the same renewal: which one is it?
        $this->whmcsService(33, '2026-09-25');
        $this->whmcsInvoice(470, 'Paid', [33 => 10.0], due: '2026-08-25');
        $this->whmcsInvoice(522, 'Unpaid', [33 => 10.0], due: '2026-09-25');
        $this->whmcsInvoice(523, 'Unpaid', [33 => 10.0], due: '2026-09-25');
        // A paid line WHMCS kept no due date on.
        $this->whmcsService(34, '2026-10-10');
        $this->whmcsInvoice(481, 'Paid', [34 => 10.0], due: '2026-09-10', lineDue: null);
        // Not live: never looked at, never named.
        $this->whmcsService(35, '2026-10-10', status: 'Terminated');
        $this->whmcsInvoice(482, 'Paid', [35 => 10.0], due: '2026-09-10');
        $this->whmcsService(36, '2026-10-10', status: 'Pending');
        $this->whmcsInvoice(483, 'Paid', [36 => 10.0], due: '2026-09-10');
        $this->importWhmcs();

        foreach ([480, 520, 481, 482, 483] as $invoice) {
            $this->importedWithoutPeriod($invoice);
        }
        $this->assertSame(['2026-09-25', '2026-10-25', null], $this->line(521));
        $this->assertSame(['2026-08-25', '2026-09-25', '2026-09-03 10:00:00'], $this->line(470), 'its paid line matches: written');
        $this->importedWithoutPeriod(522);
        $this->importedWithoutPeriod(523);
        $said = $this->importSaid();
        $line480 = InvoiceItem::query()->where('invoice_id', 480)->value('id');
        $line481 = InvoiceItem::query()->where('invoice_id', 481)->value('id');
        $this->assertStringContainsString('4 live service(s) whose last paid line or open renewal got no period', $said);
        $this->assertStringContainsString("#31 (its last paid line (#{$line480}) is due 2026-09-10, one cycle on is 2026-10-10, its due date 2026-10-15), "
            . '#32 (no paid line), #33 (2 unpaid lines due on its due date 2026-09-25), '
            . "#34 (its last paid line (#{$line481}) has no due date).", $said);
        $this->assertStringContainsString('1 last paid line(s)', $said);
        $this->assertStringContainsString('1 open renewal(s)', $said);
    }

    public function test_the_first_order_of_a_pro_rata_product_takes_what_the_customer_paid_for(): void
    {
        $prorata = $this->createProduct(['prorata_day' => 1, 'prorata_next_after_day' => 20]);
        $prorata->plan->update(['name' => 'Monthly']);
        // Ordered 09-08, paid 23.00 for 09-08 to 10-01: its only paid line, due the day it was made.
        $this->whmcsProductId = $prorata->product->id;
        $this->whmcsService(31, '2026-10-01', 30.0, regdate: '2026-09-08');
        $this->whmcsInvoice(480, 'Paid', [31 => 23.0], due: '2026-09-08', date: '2026-09-08');
        // The same dates on a product that is not pro rata: not a whole cycle, named.
        $this->whmcsProductId = $this->productId;
        $this->whmcsService(32, '2026-10-01', 30.0, regdate: '2026-09-08');
        $this->whmcsInvoice(481, 'Paid', [32 => 23.0], due: '2026-09-08', date: '2026-09-08');
        // Pro rata, but not its first order any more (two paid lines): the last one must match by itself.
        $this->whmcsProductId = $prorata->product->id;
        $this->whmcsService(33, '2026-10-01', 30.0, regdate: '2026-08-08');
        $this->whmcsInvoice(460, 'Paid', [33 => 23.0], due: '2026-08-08', date: '2026-08-08');
        $this->whmcsInvoice(482, 'Paid', [33 => 30.0], due: '2026-09-08', date: '2026-09-01');
        $this->importWhmcs();

        $this->assertSame(['2026-09-08', '2026-10-01', '2026-09-10 10:00:00'], $this->line(480));
        $this->importedWithoutPeriod(481);
        $this->importedWithoutPeriod(460);
        $this->importedWithoutPeriod(482);
        $said = $this->importSaid();
        $this->assertStringContainsString('0 last paid line(s) with their period by WHMCS\'s due date and 1 pro rata first order(s), applied', $said);
        $this->assertStringContainsString('2 live service(s) whose last paid line or open renewal got no period', $said);
        $this->assertStringContainsString('#32 (', $said);
        $this->assertStringContainsString('#33 (', $said);

        // Its next renewal is the whole month from its day, billed once.
        $this->cronOn('2026-09-25');
        $this->assertCount(1, $this->open(31));
        $renewal = InvoiceItem::query()->where('reference_id', 31)->where('reference_type', Service::class)->orderByDesc('id')->firstOrFail();
        $this->assertSame(['2026-10-01', '2026-11-01'], [$renewal->period_start->toDateString(), $renewal->period_end->toDateString()]);
    }

    public function test_a_month_end_date_is_written_only_where_whmcs_and_this_apps_arithmetic_agree(): void
    {
        // Made on the 31st. 01-31 to 02-28: the same on both sides. Its renewal from 02-28 ends 03-31 here (back on its day).
        Carbon::setTestNow('2026-02-20 10:00:00');
        $this->whmcsService(31, '2026-02-28', regdate: '2025-10-31');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-01-31', date: '2026-01-24');
        $this->whmcsInvoice(520, 'Unpaid', [31 => 10.0], due: '2026-02-28', date: '2026-02-18');
        // WHMCS went 02-28 to 03-28; here a period from 02-28 of a service made on the 31st ends 03-31: not written.
        $this->whmcsService(32, '2026-03-28', regdate: '2025-10-31');
        $this->whmcsInvoice(481, 'Paid', [32 => 10.0], due: '2026-02-28', date: '2026-02-18');
        $this->importWhmcs();

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-01-26 10:00:00'], $this->line(480));
        $this->assertSame(['2026-02-28', '2026-03-31', null], $this->line(520), 'as the cron would bill it: the period before it shows its day');
        $service = Service::query()->findOrFail(31);
        $this->assertSame('2026-03-31', $service->periodEnd($service->paidUntilBase())?->toDateString());
        $this->importedWithoutPeriod(481);
        $line = InvoiceItem::query()->where('invoice_id', 481)->value('id');
        $this->assertStringContainsString("#32 (its last paid line (#{$line}) is due 2026-02-28, one cycle on is 2026-03-31, its due date 2026-03-28)", $this->importSaid());
    }

    public function test_a_second_run_of_the_import_gives_the_same_result(): void
    {
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(400, 'Paid', [31 => 10.0], due: '2026-08-10', date: '2026-08-01');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(520, 'Unpaid', [31 => 10.0], due: '2026-10-10');
        $this->whmcsService(32, '2026-10-15');
        $this->whmcsInvoice(481, 'Paid', [32 => 10.0], due: '2026-09-10');
        $this->importWhmcs();
        $lines = $this->table('invoice_items');
        $said = $this->importSaid();
        $this->assertSame('2026-09-10', $this->line(480)[0]);

        // The step alone, on what it wrote already: the same two lines, the same service named.
        $again = (new WhmcsLinePeriods($this->whmcs))->run();
        $this->assertSame(['paid' => 1, 'prorata' => 0, 'open' => 1, 'services' => 2], $again['counts']);
        $this->assertSame([32], array_keys($again['named']));
        $this->assertSame($lines, $this->table('invoice_items'));

        // The whole import again from nothing, as the command runs it (migrate:fresh): row for row what the first gave.
        foreach (['money_events', 'invoice_transactions', 'invoice_items', 'invoices', 'services'] as $table) {
            DB::table($table)->delete();
        }
        $this->importWhmcs();
        $this->assertSame($lines, $this->table('invoice_items'));
        $this->assertSame($said, $this->importSaid());
    }

    /**
     * `mycs:parity` compares counts, statuses and sums of money; `mycs:forecast-parity` each service's dates and amounts.
     * Neither reads a period or an applied mark, and the step writes nothing else: every other column of every row is as
     * the import left it, and the forecast says the same with the periods and without them. What it forecasts («invoiced
     * on») is the day the cron bills the service that came with its periods.
     */
    public function test_the_parity_reports_read_the_same_with_the_periods_and_without(): void
    {
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsService(32, '2026-09-25');
        $this->whmcsInvoice(470, 'Paid', [32 => 10.0], due: '2026-08-25', date: '2026-08-18');
        $this->whmcsInvoice(521, 'Unpaid', [32 => 10.0], due: '2026-09-25');
        foreach (['ALTER TABLE tblhosting ADD COLUMN nextinvoicedate TEXT', "ALTER TABLE tblhosting ADD COLUMN paymentmethod TEXT DEFAULT 'banktransfer'",
            "ALTER TABLE tblhosting ADD COLUMN subscriptionid TEXT DEFAULT ''", 'UPDATE tblhosting SET nextinvoicedate = nextduedate',
            "INSERT INTO tblconfiguration VALUES ('AutoSuspension', 'on'), ('AutoSuspensionDays', '8'), ('AutoTermination', 'on'), ('AutoTerminationDays', '60'), ('CreateInvoiceDaysBefore', '7')"] as $sql) {
            $this->whmcs->exec($sql);
        }
        $this->importWhmcs();
        $this->settings();
        $with = ['items' => $this->table('invoice_items'), 'invoices' => $this->table('invoices'), 'services' => $this->table('services'),
            'payments' => $this->table('invoice_transactions'), 'journal' => $this->table('money_events')];
        $forecast = (new WhmcsForecastParity($this->whmcs))->run();
        $this->assertSame([], array_values(array_filter($forecast['differences'], fn (array $d) => !in_array($d['field'], ['suspended on', 'terminated on'], true))),
            'the forecast agrees but for the day after (its own rule)');

        // The import as it was before: the same rows without the three columns the step writes.
        $bare = fn (array $rows) => array_map(fn (array $row) => array_diff_key($row, ['period_start' => 1, 'period_end' => 1, 'applied_at' => 1]), $rows);
        $written = array_filter($with['items'], fn (array $row) => $row['period_start'] !== null);
        $this->assertCount(3, $written, 'two last paid lines and one open renewal');
        DB::table('invoice_items')->update(['period_start' => null, 'period_end' => null, 'applied_at' => null]);
        $this->assertSame($bare($with['items']), $bare($this->table('invoice_items')));
        $this->assertSame($forecast, (new WhmcsForecastParity($this->whmcs))->run(), 'the forecast reads no period');
        (new WhmcsLinePeriods($this->whmcs))->run();
        $this->assertSame($with, ['items' => $this->table('invoice_items'), 'invoices' => $this->table('invoices'), 'services' => $this->table('services'),
            'payments' => $this->table('invoice_transactions'), 'journal' => $this->table('money_events')], 'the step writes periods and applied marks, nothing else');

        // Forecast: service 31 invoiced on 2026-10-03 (7 days before 10-10). The cron: not the day before, on that day.
        $this->cronOn('2026-10-02');
        $this->assertSame([], $this->open(31));
        $this->cronOn('2026-10-03');
        $this->assertCount(1, $this->open(31));
        $this->assertStringEndsWith(' 2026-10-10', $this->open(31)[0]);
        $this->assertSame(['#521 2026-09-25'], $this->open(32), 'its open renewal is its bill: nothing beside it');
    }

    public function test_an_imported_service_renews_as_a_native_one(): void
    {
        $this->whmcsService(31, '2026-09-25');
        $this->whmcsInvoice(470, 'Paid', [31 => 10.0], due: '2026-08-25', date: '2026-08-18');
        $this->whmcsInvoice(521, 'Unpaid', [31 => 10.0], due: '2026-09-25');
        $this->importWhmcs();
        $this->settings();

        (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(521), null, '10.00', 'tr_521');

        $this->assertSame(['2026-09-25', '2026-10-25', '2026-09-20 10:00:00'], $this->line(521), 'the period it came with, applied now');
        $this->assertSame('2026-10-25', Service::query()->findOrFail(31)->expires_at->toDateString());
        // Applied again (the cron's replay of a paid invoice, BIL-9): nothing more.
        app(ProcessPaidInvoiceService::class)->handle(Invoice::query()->findOrFail(521));
        $this->assertSame('2026-10-25', Service::query()->findOrFail(31)->expires_at->toDateString());

        $this->cronOn('2026-10-17');
        $this->assertSame([], $this->open(31), 'not billed before its days');
        $this->cronOn('2026-10-18');
        $this->assertCount(1, $this->open(31));
        $this->assertStringEndsWith(' 2026-10-25', $this->open(31)[0], 'the next period, once');
        $this->assertSame([], (new UnbilledServices)->find());
    }

    public function test_an_imported_paid_invoice_is_refunded_as_a_native_one(): void
    {
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->importWhmcs();
        $this->settings();
        $payment = InvoiceTransaction::query()->where('invoice_id', 480)->sole();

        ExtensionHelper::refundLocally($payment, 10.00, notify: false);

        $line = InvoiceItem::query()->where('invoice_id', 480)->sole();
        $this->assertSame(Invoice::STATUS_REFUNDED, Invoice::query()->findOrFail(480)->status);
        $this->assertSame(['2026-09-10', '2026-10-10'], [$line->period_start->toDateString(), $line->period_end->toDateString()], 'its own period, the one it paid');
        $this->assertNotNull($line->forgone_at, 'given up: never billed again');
        // The service runs to 10-10 on a refunded invoice: told at once, as a native one is.
        $found = (new UnbilledServices)->find();
        $this->assertSame([[UnbilledServices::PERIOD_NOT_PAID, 31]], array_map(fn (array $f) => [$f['kind'], $f['service']], $found));
        $this->assertStringContainsString('runs to 2026-10-10 on invoice #480, which is refunded: nothing pays for the period from 2026-09-10', $found[0]['detail']);

        // Its NEXT period is billed at its time, and the refund is undone as a native one's: nothing renewed again.
        $this->cronOn('2026-10-03');
        $this->assertCount(1, $this->open(31));
        $this->assertStringEndsWith(' 2026-10-10', $this->open(31)[0]);
        $this->storeSettings(['credits_enabled' => true]);
        (new UndoRefund)->handle($payment->fresh(), UndoRefund::refunds($payment->fresh())->sole()->id);
        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->findOrFail(480)->status);
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString());
    }

    public function test_an_imported_service_running_on_an_invoice_unpaid_again_is_reported_as_a_native_one(): void
    {
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->importWhmcs();
        $this->settings();
        $this->assertSame([], (new UnbilledServices)->find());

        (new RecordPaymentService)->deleteTransaction(InvoiceTransaction::query()->where('invoice_id', 480)->sole());

        $found = (new UnbilledServices)->find();
        $this->assertSame([[UnbilledServices::PERIOD_NOT_PAID, 31]], array_map(fn (array $f) => [$f['kind'], $f['service']], $found));
        $this->assertStringContainsString('runs to 2026-10-10 on invoice #480, which is unpaid again since 2026-09-20 (owes 10.00 EUR): collect it, or move the due date back to 2026-09-10',
            $found[0]['detail']);
        $this->assertSame(['service_unbilled'], array_values(array_unique(array_column((new MoneyCheck)->run(), 'rule'))));

        // Paid again: a replay of a line applied before, no second month.
        (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(480), null, '10.00', 'tr_480_again');
        $this->assertSame([Invoice::STATUS_PAID, '2026-10-10'], [Invoice::query()->findOrFail(480)->status, Service::query()->findOrFail(31)->expires_at->toDateString()]);
        $this->assertSame([], (new UnbilledServices)->find());
    }

    /** A service the import named keeps what protected it before: the refusals written for a line with no period. */
    public function test_a_line_left_without_its_period_keeps_the_refusals_that_guard_it(): void
    {
        $this->whmcsService(31, '2026-10-15'); // moved by hand: no match
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsService(32, '2026-10-15');
        $this->whmcsInvoice(481, 'Paid', [32 => 10.0], due: '2026-09-10');
        $this->importWhmcs();
        $this->storeSettings(['credits_enabled' => true]);
        $this->importedWithoutPeriod(480);
        $this->assertStringContainsString('2 live service(s) whose last paid line or open renewal got no period', $this->importSaid());

        // An edit that would open and pay #480 again, once money was recorded on it here.
        $old = InvoiceTransaction::query()->where('invoice_id', 480)->sole();
        (new RecordPaymentService)->handle(480, null, '2.00', transactionId: 'extra', enteredByStaff: true);
        $this->assertSame(__('invoices.payment_edit.would_renew'), $this->refused(fn () => (new EditPayment)->handle($old, ['amount' => '10.01'])));

        // An undo of the full refund of #481.
        $other = InvoiceTransaction::query()->where('invoice_id', 481)->sole();
        ExtensionHelper::refundLocally($other, 10.00, notify: false);
        $this->assertSame(__('invoices.refund_undo.would_renew'), $this->refused(fn () => (new UndoRefund)->handle($other, UndoRefund::refunds($other)->sole()->id)));
        $this->assertSame(['2026-10-15', '2026-10-15'], [Service::query()->findOrFail(31)->expires_at->toDateString(), Service::query()->findOrFail(32)->expires_at->toDateString()]);
    }

    /**
     * An OLDER paid line comes without its period (the importer writes two lines). Its invoice refunded in full gives up
     * nothing: its service has a live line with a period, so this one is the past, not the period to come
     * (InvoiceObserver::givePeriodsUp, owner 2026-11-11; before, it gave up the NEXT period: S8-CRON-BILLING-2 on another invoice).
     */
    public function test_a_full_refund_of_an_older_paid_invoice_does_not_give_up_the_next_period(): void
    {
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(400, 'Paid', [31 => 10.0], due: '2026-08-10', date: '2026-08-01');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->importWhmcs();
        $this->settings();
        $this->importedWithoutPeriod(400);

        ExtensionHelper::refundLocally(InvoiceTransaction::query()->where('invoice_id', 400)->sole(), 10.00, notify: false);

        $line = InvoiceItem::query()->where('invoice_id', 400)->sole();
        $this->assertSame(Invoice::STATUS_REFUNDED, Invoice::query()->findOrFail(400)->status);
        $this->assertSame([null, null, null], [$line->period_start, $line->period_end, $line->forgone_at], 'no period given, none given up');
        $this->assertSame([], (new UnbilledServices)->find());
        $this->cronOn('2026-10-03');
        $this->assertCount(1, $this->open(31), 'the renewal from 2026-10-10 is billed');
        $this->assertStringEndsWith(' 2026-10-10', $this->open(31)[0]);
    }

    /** The control: a service the import named has no period line at all, and goes on as before (the next period is given up). */
    public function test_a_full_refund_on_a_service_left_without_periods_is_as_before(): void
    {
        $this->whmcsService(31, '2026-10-15'); // moved by hand: no match
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->importWhmcs();
        $this->settings();
        $this->importedWithoutPeriod(480);

        ExtensionHelper::refundLocally(InvoiceTransaction::query()->where('invoice_id', 480)->sole(), 10.00, notify: false);

        $line = InvoiceItem::query()->where('invoice_id', 480)->sole();
        $this->assertSame(['2026-10-15', '2026-11-15'], [$line->period_start?->toDateString(), $line->period_end?->toDateString()]);
        $this->assertNotNull($line->forgone_at, 'unchanged: the old guess, which the runbook tells staff of');
    }

    /**
     * An UNPAID line with no period, off the due date (an invoice made ahead for the month after the open renewal's), on a
     * service with periods, cancelled: nothing is given up, whichever way staff cancel it, since nothing here says which
     * period it billed. The cron goes on billing what is due at its time, so the service is never left suspended with
     * nothing to pay; a period staff meant to give up shows up as an invoice they can cancel.
     */
    #[DataProvider('cancellations')]
    public function test_an_unpaid_line_with_no_period_cancelled_on_a_service_with_periods_gives_up_nothing(?bool $keep): void
    {
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(530, 'Unpaid', [31 => 10.0], due: '2026-11-10');
        $this->importWhmcs();
        $this->settings();
        $invoice = $this->importedWithoutPeriod(530);

        Carbon::setTestNow('2026-10-05 10:00:00'); // inside the invoice days of the period from 10-10
        $invoice->keep_periods_billable = $keep;
        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'not wanted']);

        $line = InvoiceItem::query()->where('invoice_id', 530)->sole();
        $this->assertSame([null, null, null], [$line->period_start, $line->period_end, $line->forgone_at]);
        $this->cronOn('2026-10-06');
        $this->assertCount(1, $this->open(31));
        $this->assertStringEndsWith(' 2026-10-10', $this->open(31)[0], 'the period that is due is billed');
    }

    /** @return array<string, array{bool|null}> */
    public static function cancellations(): array
    {
        return ['as usual' => [null], 'give them all up' => [false], 'bill them all again' => [true]];
    }

    /** An older paid line reopened is still marked applied as of when it was paid (markAppliedBeforeMyCs): paid again, no second month. */
    public function test_an_older_paid_invoice_open_again_is_marked_applied_and_renews_nothing_when_paid_again(): void
    {
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(400, 'Paid', [31 => 10.0], due: '2026-08-10', date: '2026-08-01');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->importWhmcs();
        $this->settings();

        (new RecordPaymentService)->deleteTransaction(InvoiceTransaction::query()->where('invoice_id', 400)->sole());

        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->findOrFail(400)->status);
        $this->assertSame([null, null, '2026-08-03 10:00:00'], $this->line(400), 'applied as of its paid date, still no period');
        (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(400), null, '10.00', 'tr_400_again');
        $this->assertSame([Invoice::STATUS_PAID, '2026-10-10'], [Invoice::query()->findOrFail(400)->status, Service::query()->findOrFail(31)->expires_at->toDateString()]);

        // Open again and cancelled: an applied line with no period gives up nothing either.
        (new RecordPaymentService)->deleteTransaction(InvoiceTransaction::query()->where('invoice_id', 400)->sole());
        Invoice::query()->findOrFail(400)->update(['status' => Invoice::STATUS_CANCELLED]);
        $this->assertSame([null, null], array_slice($this->line(400), 0, 2));
        $this->assertNull(InvoiceItem::query()->where('invoice_id', 400)->sole()->forgone_at);
        $this->cronOn('2026-10-03');
        $this->assertCount(1, $this->open(31));
    }

    /** A WHMCS invoice of service $service: a Hosting line of 10.00 and a discount line of −1.00 beside it (9.00 to pay). */
    private function discountedInvoice(int $id, int $service, string $status, string $due, string $discount = 'GroupDiscount'): void
    {
        $paid = $status === 'Paid' ? '2026-09-03 10:00:00' : '0000-00-00 00:00:00';
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->whmcsClientId}, '', '2026-09-01', '{$due}', '{$paid}', '{$status}', 9, 0, 0)");
        // A group's discount names no service (relid 0); a promotion's names its own.
        foreach ([['Hosting', $service, 10], [$discount, $discount === 'GroupDiscount' ? 0 : $service, -1]] as [$type, $relid, $amount]) {
            $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed, duedate)
                VALUES ({$id}, {$this->whmcsClientId}, '{$type}', {$relid}, '{$type}', {$amount}, 0, '{$due}')");
        }
        if ($status === 'Paid') {
            $this->whmcs->exec("INSERT INTO tblaccounts VALUES ({$id}, {$this->whmcsClientId}, {$id}, 'banktransfer', '{$paid}', 9, 0, 0, 'tr_{$id}', 0)");
        }
    }

    /**
     * WHMCS bills a client group's discount (and a promotion) as a negative line of its own, so the Hosting line holds the
     * amount before it (10.00 with −1.00 beside it, 9.00 paid). With its period, the days left would be worth what the
     * LINE says (20 of 30 days of 10.00 = 6.67). The importer leaves that line as it came and names the service: the days
     * are worth the service's own price, which holds the discount (6.00, what was paid).
     */
    #[DataProvider('discounts')]
    public function test_the_last_paid_line_beside_a_discount_line_gets_no_period_and_its_days_are_worth_what_was_paid(string $discount): void
    {
        $this->whmcsService(31, '2026-10-10', 9.0);
        $this->discountedInvoice(480, 31, 'Paid', '2026-09-10', $discount);
        // On the same import, a service with no discount gets its periods.
        $this->whmcsService(32, '2026-10-10');
        $this->whmcsInvoice(481, 'Paid', [32 => 10.0], due: '2026-09-10');
        $this->whmcsInvoice(521, 'Unpaid', [32 => 10.0], due: '2026-10-10');
        $this->importWhmcs();

        $hosting = InvoiceItem::query()->where('invoice_id', 480)->where('reference_type', Service::class)->whereNull('kind')->sole();
        $this->assertSame([null, null, null], [$hosting->period_start, $hosting->period_end, $hosting->applied_at]);
        $this->assertSame('6.00', ChangeCycle::unused(Service::query()->findOrFail(31), CarbonImmutable::today()));
        $this->assertSame(['2026-09-10', '2026-10-10', '2026-09-03 10:00:00'], $this->line(481));
        $this->assertSame(['2026-10-10', '2026-11-10', null], $this->line(521));
        $this->assertSame('6.67', ChangeCycle::unused(Service::query()->findOrFail(32), CarbonImmutable::today()), '20 of 30 days of the 10.00 it paid');
        $said = $this->importSaid();
        $this->assertStringContainsString('1 live service(s) whose last paid line or open renewal got no period', $said);
        $this->assertStringContainsString("#31 (its last paid line (#{$hosting->id}) is on an invoice with a discount line (invoice #480): it holds the amount before the discount).", $said);
        $this->assertStringContainsString('1 last paid line(s)', $said);
    }

    /** @return array<string, array{string}> */
    public static function discounts(): array
    {
        return ['a client group\'s' => ['GroupDiscount'], 'a promotion\'s' => ['PromoHosting']];
    }

    /**
     * The OPEN renewal beside a discount line is written all the same. Leaving it out would not keep its days from being
     * priced by the line: paid here, it takes the same period from the payment (RenewServiceService), and the days left
     * read the same with the importer's period and without it. Leaving it out would only bring back the guesses of sweep 8.
     */
    public function test_an_open_renewal_beside_a_discount_line_is_written_and_reads_the_same_once_paid_as_without_it(): void
    {
        $unused = [];
        foreach ([31, 32] as $service) {
            $this->whmcsService($service, '2026-09-25', 9.0);
            $this->discountedInvoice(400 + $service, $service, 'Paid', '2026-08-25');
            $this->discountedInvoice(500 + $service, $service, 'Unpaid', '2026-09-25');
        }
        $this->importWhmcs();
        $this->settings();
        $open = fn (int $invoice) => InvoiceItem::query()->where('invoice_id', $invoice)->where('reference_type', Service::class)->whereNull('kind')->sole();
        $this->assertSame(['2026-09-25', '2026-10-25', null], [$open(531)->period_start?->toDateString(), $open(531)->period_end?->toDateString(), $open(531)->applied_at]);
        $this->assertNull($open(431)->period_start, 'its paid line, beside a discount line, has none');
        // Service 32 as if the importer had left its open renewal alone too.
        DB::table('invoice_items')->where('id', $open(532)->id)->update(['period_start' => null, 'period_end' => null]);

        foreach ([31, 32] as $service) {
            (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(500 + $service), null, '9.00', 'tr_' . $service);
            $this->assertSame('2026-10-25', Service::query()->findOrFail($service)->expires_at->toDateString());
        }
        Carbon::setTestNow('2026-10-05 10:00:00');
        foreach ([31, 32] as $service) {
            $unused[$service] = ChangeCycle::unused(Service::query()->findOrFail($service), CarbonImmutable::today());
        }
        $this->assertSame(['6.67', '6.67'], [$unused[31], $unused[32]], 'the same days left, by the line either way (20 of 30 days of the line\'s 10.00)');
    }

    public function test_a_source_without_the_lines_due_date_is_said_and_nothing_is_written(): void
    {
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->whmcs->exec('ALTER TABLE tblinvoiceitems DROP COLUMN duedate');
        $this->importWhmcs();

        $this->importedWithoutPeriod(480);
        $this->assertStringContainsString("The lines' due dates (tblinvoiceitems.duedate) could not be read: no invoice line was given its period", $this->importSaid());
    }
}
