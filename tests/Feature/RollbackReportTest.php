<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use App\Services\Ops\RollbackReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The report of a return to WHMCS (cutover-runbook §3): what the MyCS did after the cut-over, and nothing of what came
 * from WHMCS.
 */
class RollbackReportTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $cutover;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->cutover = Carbon::parse('2026-11-14 22:00');
    }

    /** @return array<string, list<list<string>>> rows by part */
    private function report(): array
    {
        return collect((new RollbackReport($this->cutover))->sections())->mapWithKeys(fn ($s) => [$s['key'] => $s['rows']])->all();
    }

    /** As the importer leaves it: rows dated in WHMCS's past, no audits (audit.console off). */
    private function imported(): array
    {
        Carbon::setTestNow('2026-11-14 21:00');
        Config::set('audit.console', false);
        $client = User::factory()->create(['created_at' => '2020-01-01'])->client();
        $client->forceFill(['created_at' => '2020-01-01'])->save();
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-12-01', 'currency_code' => 'USD', 'price' => 10.00, 'created_at' => '2021-01-01']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'created_at' => '2026-11-01']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => 10, 'quantity' => 1, 'description' => 'Hosting', 'created_at' => '2026-11-01']);
        $domain = Domain::factory()->create(['client_id' => $client->id, 'sld' => 'example', 'fqdn' => 'example.test', 'expires_at' => '2027-01-01', 'created_at' => '2019-01-01']);
        ExtensionHelper::addPayment($invoice->id, null, 3.00, transactionId: 'WHMCS-OLD'); // paid in part in WHMCS

        // The MyCS opens: the web, the cron and the jobs are audited.
        Carbon::setTestNow('2026-11-15 10:00');
        Config::set('audit.console', true);

        return [$client, $service, $invoice, $domain];
    }

    public function test_what_the_mycs_did_is_listed_and_what_came_from_whmcs_is_not(): void
    {
        [$client, $service, $invoice, $domain] = $this->imported();

        ExtensionHelper::addPayment($invoice->id, null, 7.00, transactionId: 'pi_after');
        $service->update(['expires_at' => '2027-01-01']);
        $domain->update(['expires_at' => '2028-01-01']);
        $client->primaryContact?->update(['phone' => '+30.2100000000']);
        $new = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        InvoiceItem::factory()->create(['invoice_id' => $new->id, 'price' => 12, 'quantity' => 1, 'description' => 'Renewal']);
        $newcomer = User::factory()->create()->client();

        $report = $this->report();

        $payments = $report['payments'];
        $this->assertCount(1, $payments, 'only the one taken after the cut-over');
        $this->assertSame('pi_after', $payments[0][9]);
        $this->assertSame('WHMCS', $payments[0][3], 'on an invoice WHMCS has');

        $this->assertSame([(string) $new->id], array_column($report['invoices_new'], 0));
        $this->assertStringContainsString('Renewal', $report['invoices_new'][0][9]);

        $this->assertSame([(string) $service->id], array_column($report['services_changed'], 0));
        $this->assertStringContainsString('2027-01-01', $report['services_changed'][0][3]);
        $this->assertSame([(string) $domain->id], array_column($report['domains_changed'], 0));
        $this->assertStringContainsString('2028-01-01', $report['domains_changed'][0][3]);

        $this->assertContains((string) $newcomer->id, array_column($report['clients_new'], 0));
        $this->assertNotContains((string) $client->id, array_column($report['clients_new'], 0));
        $this->assertSame([], $report['services_new']);
        $this->assertSame([], $report['domains_new']);
    }

    public function test_nothing_after_the_cut_over_is_an_empty_report(): void
    {
        $this->imported();

        foreach ($this->report() as $key => $rows) {
            $this->assertSame([], $rows, $key);
        }
    }

    public function test_a_change_to_a_row_the_mycs_made_is_not_listed_twice(): void
    {
        [$client] = $this->imported();
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'pending', 'expires_at' => '2026-12-15', 'currency_code' => 'USD', 'price' => 5.00]);
        $service->update(['status' => 'active']);

        $report = $this->report();
        $this->assertSame([(string) $service->id], array_column($report['services_new'], 0));
        $this->assertSame('active', $report['services_new'][0][6], 'as it is now');
        $this->assertSame([], $report['services_changed']);
    }

    public function test_the_command_writes_one_csv_per_part(): void
    {
        [, , $invoice] = $this->imported();
        ExtensionHelper::addPayment($invoice->id, null, 2.00, transactionId: '=HYPERLINK("x")');
        $dir = sys_get_temp_dir() . '/rollback-' . getmypid() . '-' . uniqid();

        $this->artisan('mycs:rollback-report', ['--since' => '2026-11-14 22:00', '--csv' => $dir])
            ->expectsOutputToContain('Payments (1)')->assertExitCode(0);

        $csv = (string) file_get_contents("{$dir}/payments.csv");
        $this->assertStringContainsString("'=HYPERLINK", $csv, 'a formula is text in a spreadsheet');
        $this->assertStringContainsString(',2.00,', $csv);
        $this->assertFileExists("{$dir}/cards.csv");
        array_map('unlink', glob("{$dir}/*.csv") ?: []);
        rmdir($dir);
    }

    public function test_since_is_required(): void
    {
        $this->artisan('mycs:rollback-report')->expectsOutputToContain('--since')->assertExitCode(2);
    }
}
