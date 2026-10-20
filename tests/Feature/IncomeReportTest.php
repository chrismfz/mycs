<?php

namespace Tests\Feature;

use App\Admin\Pages\IncomeReport;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\User;
use App\Services\Reports\IncomeReport as Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A year's income for the accountant (ROADMAP 6): every invoice paid in the year, net, tax and total, its customer and
 * how it was paid, and the months; refunds and notes taken off, credit purchases never income; XLSX and CSV.
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
        $this->user->client()->primaryContact->update(['country' => 'EE', 'first_name' => '=HYPERLINK("http://example.test")', 'company_name' => '=HYPERLINK("http://example.test")', 'vat_number' => 'EE000000000']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paid(string $when, float $net, ?string $reference = null, string $number = '', ?float $note = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $invoice->items()->create(array_filter(['description' => 'x', 'quantity' => 1, 'price' => $net, 'tax_rate' => '24', 'reference_type' => $reference]));
        if ($note !== null) {
            // A note goes on before it is paid (a paid invoice keeps its total).
            $invoice->adjustmentNotes()->create(['type' => 'credit', 'status' => 'active', 'amount' => $note, 'description' => 'refund']);
        }
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID, 'paid_at' => $when, 'number' => $number ?: null]);

        return $invoice;
    }

    public function test_the_year_by_invoice_and_by_month(): void
    {
        $this->paid('2026-01-15 10:00:00', 100.00, number: '2026-0001');
        $this->paid('2026-03-02 10:00:00', 50.00, note: -24.80); // 20 net + 4.80 tax refunded
        $this->paid('2026-03-20 10:00:00', 30.00, Credit::class); // add funds: not income
        $this->paid('2025-12-31 23:00:00', 999.00);              // last year

        $report = Report::year('EUR', 2026);

        $this->assertCount(2, $report['invoices']);
        $this->assertSame(['2026-0001', '100.00', '24.00', '124.00', 'EE', 'EE000000000'], [$report['invoices'][0]['number'], $report['invoices'][0]['net'],
            $report['invoices'][0]['tax'], $report['invoices'][0]['total'], $report['invoices'][0]['country'], $report['invoices'][0]['vat']]);
        $this->assertSame(['30.00', '7.20', '37.20'], [$report['invoices'][1]['net'], $report['invoices'][1]['tax'], $report['invoices'][1]['total']], '50 + 24% less the 24.80 refund');
        $this->assertSame([1, '30.00'], [$report['months'][2]['count'], $report['months'][2]['net']], 'March');
        $this->assertSame([2, '130.00', '31.20', '161.20'], array_values($report['totals']));
    }

    public function test_exports_and_a_name_that_looks_like_a_formula_stays_text(): void
    {
        $this->paid('2026-05-05 10:00:00', 10.00);
        $staff = Staff::factory()->withPermissions(['admin.reports.view'])->create();
        $this->actingAs(Staff::factory()->withPermissions(['admin.clients.viewAny'])->create())->get(IncomeReport::getUrl())->assertForbidden();
        $this->actingAs($staff)->get(IncomeReport::getUrl())->assertOk()->assertSee(__('By month'));

        $this->livewireAs($staff)->test(IncomeReport::class)->callAction('xlsx')->assertFileDownloaded('income-2026-EUR.xlsx');
        $csv = $this->livewireAs($staff)->test(IncomeReport::class)->callAction('csv');
        $csv->assertFileDownloaded('income-2026-EUR.csv');
    }

    public function test_the_xlsx_holds_the_formula_like_name_as_text(): void
    {
        $this->paid('2026-05-05 10:00:00', 10.00);
        $page = new IncomeReport;
        $page->mount();
        $method = new \ReflectionMethod($page, 'xlsx');
        $response = $method->invoke($page);
        $zip = new \ZipArchive;
        $zip->open($response->getFile()->getPathname());
        $sheets = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $sheets .= (string) $zip->getFromIndex($i);
        }
        $zip->close();

        $this->assertStringNotContainsString('<f>', $sheets, 'a formula cell');
        $this->assertStringContainsString('HYPERLINK', $sheets, 'the name, as text');
    }
}
