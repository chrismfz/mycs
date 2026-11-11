<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use App\Services\Import\WhmcsLinePeriods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * The fix of S9-IMPORT-PERIODS-1 (Sweep9ImportPeriodsTest): an open renewal on an invoice of a customer other than the
 * service's gets no period from the importer, and the service is named under a reason of its own. The last paid line
 * keeps its period whoever's invoice it is on (review of #804, REV-804-2). Invented rows, no network.
 */
class Sweep9ImportOtherClientTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    /** The service's first customer in WHMCS: some invoices stayed with it. */
    private int $first;

    /** The service's customer. */
    private int $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->first = User::factory()->create()->client()->id;
        $this->owner = User::factory()->create()->client()->id;
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($this->owner, $product->product->id);
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$this->first}, 1)");
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The WHMCS invoice stayed with the first customer when the service moved. */
    private function leftWithFirst(int $invoice): void
    {
        $this->whmcs->exec("UPDATE tblinvoices SET userid = {$this->first} WHERE id = {$invoice}");
        $this->whmcs->exec("UPDATE tblinvoiceitems SET userid = {$this->first} WHERE invoiceid = {$invoice}");
        $this->whmcs->exec("UPDATE tblaccounts SET userid = {$this->first} WHERE invoiceid = {$invoice}");
    }

    private function cronOn(string $day): void
    {
        Carbon::setTestNow($day . ' 03:00:00');
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => false, 'settings.cronjob_order_suspend' => 8,
            'settings.cronjob_order_terminate' => 60]);
        $this->artisan('app:cron-job');
    }

    /** @return array{0: string|null, 1: string|null, 2: string|null} the period and the applied mark of an invoice's only line */
    private function line(int $invoice): array
    {
        $line = InvoiceItem::query()->where('invoice_id', $invoice)->sole();

        return [$line->period_start?->toDateString(), $line->period_end?->toDateString(), $line->applied_at?->toDateTimeString()];
    }

    private function lineId(int $invoice): int
    {
        return (int) InvoiceItem::query()->where('invoice_id', $invoice)->sole()->id;
    }

    /** @return list<string> the open invoices of $client that bill the service, as «period_start» */
    private function openOf(int $client, int $service): array
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service)
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', Invoice::OPEN_STATUSES)->where('client_id', $client))->orderBy('id')->get()
            ->map(fn (InvoiceItem $line) => $line->period_start?->toDateString() ?? 'no period')->all();
    }

    /** @return list<array<string, mixed>> */
    private function table(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn (object $row) => (array) $row)->all();
    }

    public function test_an_open_renewal_on_another_customers_invoice_gets_no_period_and_the_services_customer_is_billed(): void
    {
        $this->whmcsService(31, '2026-12-01');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-11-01', date: '2026-10-25');
        $this->whmcsInvoice(530, 'Unpaid', [31 => 10.0], due: '2026-12-01', date: '2026-11-17');
        $this->leftWithFirst(530);
        $this->importWhmcs();
        $said = $this->importSaid();

        $this->assertSame($this->first, (int) Invoice::query()->findOrFail(530)->client_id);
        $this->assertSame([null, null, null], $this->line(530), "the open renewal on the first customer's invoice: no period");
        $this->assertSame(['2026-11-01', '2026-12-01', '2026-10-27 10:00:00'], $this->line(480), "the last paid line, on its own customer's invoice: as before");
        $reason = "1 a line on another customer's invoice: #31 (line #{$this->lineId(530)} of invoice #530).";
        $this->assertSame(1, substr_count($said, $reason), $said);
        $this->assertSame(1, substr_count($said, 'another customer'), 'named once');
        $this->assertStringContainsString('1 live service(s) whose last paid line or open renewal got no period', $said);
        $this->assertStringContainsString('1 last paid line(s) with their period by WHMCS\'s due date and 0 pro rata first order(s), applied; 0 open renewal(s) with theirs.', $said);

        // The cron bills the service's own customer for the period from its due date, as before #788.
        $this->cronOn('2026-11-25');
        $this->assertSame(['2026-12-01'], $this->openOf($this->owner, 31), 'the service\'s customer is asked for 2026-12-01');
        $this->assertSame(['no period'], $this->openOf($this->first, 31), "the first customer's invoice is as it came");
        $this->cronOn('2026-11-26');
        $this->assertSame(['2026-12-01'], $this->openOf($this->owner, 31), 'once');
    }

    public function test_a_last_paid_line_on_another_customers_invoice_keeps_its_period_as_before(): void
    {
        // Paid by the first customer to 2026-12-01 (#480); before it, a month the service's own customer paid (#440).
        $this->whmcsService(31, '2026-12-01');
        $this->whmcsInvoice(440, 'Paid', [31 => 10.0], due: '2026-10-01', date: '2026-09-25');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-11-01', date: '2026-10-25');
        $this->leftWithFirst(480);
        // A neighbour with nothing wrong.
        $this->whmcsService(32, '2026-12-05');
        $this->whmcsInvoice(481, 'Paid', [32 => 10.0], due: '2026-11-05', date: '2026-10-28');
        $this->importWhmcs();
        $said = $this->importSaid();

        $this->assertSame(['2026-11-01', '2026-12-01', '2026-10-27 10:00:00'], $this->line(480), "the last paid line on the first customer's invoice: its period, as on main");
        $this->assertSame([null, null, null], $this->line(440), 'an older paid line stays as it came');
        $this->assertSame(['2026-11-05', '2026-12-05', '2026-10-30 10:00:00'], $this->line(481));
        $this->assertStringNotContainsString('another customer', $said);
        $this->assertStringNotContainsString('got no period', $said);
        $this->assertSame('2026-12-01', Service::query()->findOrFail(31)->expires_at->toDateString(), 'no due date moved');

        // The cron bills the period from the due date to the service's customer.
        $this->cronOn('2026-11-25');
        $this->assertSame(['2026-12-01'], $this->openOf($this->owner, 31));
    }

    public function test_both_lines_on_another_customers_invoices_name_the_service_once_for_the_open_one(): void
    {
        $this->whmcsService(31, '2026-12-01');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-11-01', date: '2026-10-25');
        $this->whmcsInvoice(530, 'Unpaid', [31 => 10.0], due: '2026-12-01', date: '2026-11-17');
        $this->leftWithFirst(480);
        $this->leftWithFirst(530);
        $this->importWhmcs();
        $said = $this->importSaid();

        $this->assertSame(['2026-11-01', '2026-12-01', '2026-10-27 10:00:00'], $this->line(480), 'the paid line: its period, as on main');
        $this->assertSame([null, null, null], $this->line(530));
        $this->assertSame(1, substr_count($said, "1 a line on another customer's invoice: #31 (line #{$this->lineId(530)} of invoice #530)."), $said);
        $this->assertSame(1, substr_count($said, 'another customer'));
        $this->assertStringContainsString('1 live service(s) whose', $said);
    }

    public function test_the_same_customers_lines_get_both_periods_as_before(): void
    {
        $this->whmcsService(31, '2026-12-01');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-11-01', date: '2026-10-25');
        $this->whmcsInvoice(530, 'Unpaid', [31 => 10.0], due: '2026-12-01', date: '2026-11-17');
        // The first customer has a service and invoices of its own: nothing of the other's.
        $this->whmcs->exec("INSERT INTO tblhosting VALUES (40, {$this->first}, NULL, {$this->whmcsProductId}, '2024-01-01', 'example.test', 1, 10.0, 'Monthly',
            '2026-12-03', 'Active', '', '', '', '', '', '', 0, '0000-00-00')");
        $this->whmcsInvoice(485, 'Paid', [40 => 10.0], due: '2026-11-03', date: '2026-10-27');
        $this->leftWithFirst(485);
        $this->importWhmcs();
        $said = $this->importSaid();

        $this->assertSame(['2026-11-01', '2026-12-01', '2026-10-27 10:00:00'], $this->line(480));
        $this->assertSame(['2026-12-01', '2027-01-01', null], $this->line(530));
        $this->assertSame(['2026-11-03', '2026-12-03', '2026-10-29 10:00:00'], $this->line(485));
        $this->assertStringContainsString('of 2 live recurring service(s) and addon(s), 2 last paid line(s) with their period by WHMCS\'s due date'
            . ' and 0 pro rata first order(s), applied; 1 open renewal(s) with theirs.', $said);
        $this->assertStringNotContainsString('got no period', $said);
        $this->assertStringNotContainsString('another customer', $said);
    }

    public function test_a_mass_pay_child_of_the_same_customer_is_not_another_customers_line(): void
    {
        // #480 was paid through the Mass Pay invoice #490: the money is on another invoice, of the SAME customer.
        $this->whmcsService(31, '2026-12-01');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-11-01', paid: 0.0, date: '2026-10-25');
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (490, {$this->owner}, '', '2026-10-26', '2026-10-26', '2026-10-27 10:00:00', 'Paid', 10.0, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed, duedate) VALUES (490, {$this->owner}, 'Invoice', 480,
            'Invoice #480', 10.0, 0, '2026-10-26')");
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES (490, {$this->owner}, 490, 'banktransfer', '2026-10-27 10:00:00', 10.0, 0, 0, 'tr_490', 0)");
        $this->importWhmcs();
        $said = $this->importSaid();

        $line = InvoiceItem::query()->where('invoice_id', 480)->where('reference_type', Service::class)->sole();
        $this->assertSame(['2026-11-01', '2026-12-01'], [$line->period_start?->toDateString(), $line->period_end?->toDateString()], $said);
        $this->assertNotNull($line->applied_at);
        $this->assertStringNotContainsString('another customer', $said);
        $this->assertStringNotContainsString('got no period', $said);
    }

    public function test_a_second_run_gives_the_same_result(): void
    {
        $this->whmcsService(31, '2026-12-01');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-11-01', date: '2026-10-25');
        $this->whmcsInvoice(530, 'Unpaid', [31 => 10.0], due: '2026-12-01', date: '2026-11-17');
        $this->leftWithFirst(530);
        $this->whmcsService(32, '2026-12-05');
        $this->whmcsInvoice(481, 'Paid', [32 => 10.0], due: '2026-11-05', date: '2026-10-28');
        $this->leftWithFirst(481);
        $this->importWhmcs();
        $said = $this->importSaid();
        $lines = $this->table('invoice_items');

        // The step alone, on what it wrote already.
        $again = (new WhmcsLinePeriods($this->whmcs))->run();
        $this->assertSame(['paid' => 2, 'prorata' => 0, 'open' => 0, 'services' => 2], $again['counts']);
        $this->assertSame(['other_client' => [31 => "line #{$this->lineId(530)} of invoice #530"]], $again['named']);
        $this->assertSame($lines, $this->table('invoice_items'));

        // The whole import again from nothing, as the command runs it: row for row what the first gave, said the same.
        foreach (['money_events', 'invoice_transactions', 'invoice_items', 'invoices', 'services'] as $table) {
            DB::table($table)->delete();
        }
        $this->importWhmcs();
        $this->assertSame($said, $this->importSaid());
        $strip = fn (array $rows) => array_map(fn (array $row) => array_diff_key($row, ['created_at' => 1, 'updated_at' => 1]), $rows);
        $this->assertSame($strip($lines), $strip($this->table('invoice_items')));
    }

    /**
     * An addon imported as a service of its own goes by the same path (its lines point at its service, whose customer is
     * its parent's): here a service made an addon's as WhmcsAddonImport leaves one, and the step run on it.
     */
    public function test_an_addons_line_on_another_customers_invoice_gets_no_period_either(): void
    {
        $this->whmcsService(31, '2026-12-01');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-11-01', date: '2026-10-25');
        $this->whmcsService(32, '2026-12-01');
        $this->whmcsInvoice(481, 'Paid', [32 => 10.0], due: '2026-11-01', date: '2026-10-25');
        $this->whmcsInvoice(531, 'Unpaid', [32 => 10.0], due: '2026-12-01', date: '2026-11-17');
        $this->leftWithFirst(531);
        $this->importWhmcs();
        DB::table('services')->where('id', 32)->update(['parent_service_id' => 31, 'whmcs_addon_id' => 7]);
        DB::table('invoice_items')->update(['period_start' => null, 'period_end' => null, 'applied_at' => null]);

        $result = (new WhmcsLinePeriods($this->whmcs))->run();

        $this->assertSame(['other_client' => [32 => "line #{$this->lineId(531)} of invoice #531"]], $result['named']);
        $this->assertSame([null, null, null], $this->line(531));
        $this->assertSame(['2026-11-01', '2026-12-01', '2026-10-27 10:00:00'], $this->line(481), "the addon's paid line on its own customer's invoice: as before");
        $this->assertSame(['paid' => 2, 'prorata' => 0, 'open' => 0, 'services' => 2], $result['counts']);
    }
}
