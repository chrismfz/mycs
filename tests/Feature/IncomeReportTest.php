<?php

namespace Tests\Feature;

use App\Admin\Pages\IncomeReport;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\User;
use App\Services\Reports\IncomeReport as Report;
use App\Services\Reports\RevenueForecast;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A year's income for the accountant (ROADMAP 6), as cash: every invoice paid in the year, net, tax and total, its
 * customer and how it was paid; a refund made later on an earlier invoice a row of its own in its year (a year sent
 * never changes); credit purchases never income; XLSX and CSV from a signed link.
 */
class IncomeReportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'EUR', 'name' => 'Euro', 'suffix' => ' EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR', 'settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        $this->user = User::factory()->create();
        $this->user->client()->primaryContact->update(['country' => 'EE', 'first_name' => '=HYPERLINK("http://example.test")',
            'company_name' => '=HYPERLINK("http://example.test")', 'vat_number' => 'EE000000000']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paid(string $when, float $net, ?string $reference = null, string $number = '', ?float $note = null, string $status = Invoice::STATUS_PAID): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        if ($net > 0) {
            $invoice->items()->create(array_filter(['description' => 'x', 'quantity' => 1, 'price' => $net, 'tax_rate' => '24', 'reference_type' => $reference]));
        }
        if ($note !== null) {
            // A note goes on before it is paid (a paid invoice keeps its total).
            $invoice->adjustmentNotes()->create(['type' => 'credit', 'status' => 'active', 'amount' => $note, 'description' => 'refund']);
        }
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status, 'paid_at' => $when, 'number' => $number ?: null]);

        return $invoice;
    }

    private function staff(): Staff
    {
        return Staff::factory()->withPermissions(['admin.reports.view', 'admin.invoices.viewAny'])->create();
    }

    public function test_the_year_by_invoice_and_by_month(): void
    {
        $this->paid('2026-01-15 10:00:00', 100.00, number: '2026-0001');
        $this->paid('2026-03-02 10:00:00', 50.00, note: -24.80); // 20 net + 4.80 tax refunded the same year
        $this->paid('2026-03-20 10:00:00', 30.00, Credit::class); // add funds: not income
        $this->paid('2025-12-31 23:00:00', 999.00);              // last year

        $report = Report::year('EUR', 2026);

        $this->assertCount(2, $report['invoices']);
        $this->assertSame(['2026-0001', '100.00', '24.00', '124.00', 'EE', 'EE000000000'], [$report['invoices'][0]['number'], $report['invoices'][0]['net'],
            $report['invoices'][0]['tax'], $report['invoices'][0]['total'], $report['invoices'][0]['country'], $report['invoices'][0]['vat']]);
        $this->assertSame(['30.00', '7.20', '37.20'], [$report['invoices'][1]['net'], $report['invoices'][1]['tax'], $report['invoices'][1]['total']]);
        $this->assertSame([1, '30.00'], [$report['months'][2]['count'], $report['months'][2]['net']], 'March');
        $this->assertSame([2, '130.00', '31.20', '161.20'], array_values($report['totals']));
        $this->assertSame('130.00', RevenueForecast::collected('EUR', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01')),
            'the same income as the dashboard and the forecast');
    }

    public function test_a_later_refund_is_a_row_of_its_year_and_the_year_sent_never_changes(): void
    {
        $invoice = $this->paid('2025-06-10 10:00:00', 100.00, number: '2025-0007');
        $before = Report::year('EUR', 2025)['totals'];

        // Refunded in full in 2026: the invoice is refunded, the note made in 2026.
        DB::table('adjustment_notes')->insert(['invoice_id' => $invoice->id, 'type' => 'credit', 'status' => 'active', 'amount' => -124.00,
            'description' => 'refund', 'is_refund' => true, 'created_at' => '2026-02-01 10:00:00', 'updated_at' => '2026-02-01 10:00:00']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_REFUNDED]);

        $this->assertSame($before, Report::year('EUR', 2025)['totals'], '2025 as it was sent');
        $refund = Report::year('EUR', 2026)['invoices'];
        $this->assertSame([['2025-0007', '-100.00', '-24.00', '-124.00']], array_map(fn ($row) => [$row['number'], $row['net'], $row['tax'], $row['total']], $refund));
    }

    public function test_an_invoice_of_nothing_with_a_note_has_no_tax(): void
    {
        $this->paid('2026-04-01 10:00:00', 0.0, note: -5.00);

        $row = Report::year('EUR', 2026)['invoices'];

        $this->assertSame([], $row, 'no lines: nothing to count');
    }

    public function test_staff_may_see_it_only_with_the_invoices_and_download_it_from_a_signed_link(): void
    {
        $this->paid('2026-05-05 10:00:00', 10.00);
        $this->actingAs(Staff::factory()->withPermissions(['admin.reports.view'])->create())->get(IncomeReport::getUrl())->assertForbidden();
        $staff = $this->staff();
        $this->actingAs($staff)->get(IncomeReport::getUrl())->assertOk()->assertSee(__('By month'));

        $this->actingAs($staff);
        $page = new IncomeReport;
        $page->mount();
        $xlsx = $this->get($page->exportUrl('xlsx'))->assertOk();
        $this->assertStringContainsString('income-2026-EUR.xlsx', (string) $xlsx->headers->get('content-disposition'));
        $zip = new \ZipArchive;
        $zip->open($xlsx->baseResponse->getFile()->getPathname());
        $sheets = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $sheets .= (string) $zip->getFromIndex($i);
        }
        $zip->close();
        $this->assertStringNotContainsString('<f>', $sheets, 'a formula cell');
        $this->assertStringContainsString('HYPERLINK', $sheets, 'the name, as text');

        $csv = $this->get($page->exportUrl('csv'))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString(';"\'=HYPERLINK', $csv);
        $this->assertStringContainsString(';10,00;2,40;12,40;', $csv);

        $this->get(route('admin.reports.income.export', ['year' => 2026, 'currency' => 'EUR', 'format' => 'csv']))->assertForbidden(); // unsigned
    }
}
