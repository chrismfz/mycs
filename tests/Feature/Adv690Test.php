<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Invoice\MergeInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Ops\RollbackReport;
use App\Services\Service\DeleteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #690 (mycs:rollback-report): what the MyCS did after the cut-over that the report misses or
 * shows wrongly. Each test is a scenario staff would carry back to WHMCS by the report.
 */
class Adv690Test extends TestCase
{
    use RefreshDatabase;

    private Carbon $cutover;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        $this->cutover = Carbon::parse('2026-11-14 22:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, list<list<string>>> */
    private function report(?Carbon $since = null): array
    {
        return collect((new RollbackReport($since ?? $this->cutover))->sections())->mapWithKeys(fn ($s) => [$s['key'] => $s['rows']])->all();
    }

    /** As the importer leaves it (same as RollbackReportTest::imported). */
    private function imported(array $service = []): array
    {
        Carbon::setTestNow('2026-11-14 21:00');
        Config::set('audit.console', false);
        $client = User::factory()->create(['created_at' => '2020-01-01'])->client();
        $client->forceFill(['created_at' => '2020-01-01'])->save();
        $product = $this->createProduct();
        $service = Service::factory()->create($service + ['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-12-01', 'currency_code' => 'USD', 'price' => 10.00, 'created_at' => '2021-01-01']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'created_at' => '2026-11-01', 'due_at' => '2026-11-20']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => 10, 'quantity' => 1, 'description' => 'Hosting', 'created_at' => '2026-11-01']);
        $domain = Domain::factory()->create(['client_id' => $client->id, 'sld' => 'example', 'fqdn' => 'example.test', 'expires_at' => '2027-01-01', 'created_at' => '2019-01-01']);
        ExtensionHelper::addPayment($invoice->id, null, 3.00, transactionId: 'WHMCS-OLD');

        Carbon::setTestNow('2026-11-15 10:00');
        Config::set('audit.console', true);

        return [$client, $service, $invoice, $domain];
    }

    private function whmcsPayment(Invoice $invoice): InvoiceTransaction
    {
        return InvoiceTransaction::query()->where('invoice_id', $invoice->id)->where('transaction_id', 'WHMCS-OLD')->firstOrFail();
    }

    /** ADV-690-1: staff delete a payment WHMCS has (Transactions → Delete): WHMCS still counts it as paid. */
    public function test_a_whmcs_payment_deleted_in_the_mycs_is_listed(): void
    {
        [, , $invoice] = $this->imported();

        (new RecordPaymentService)->deleteTransaction($this->whmcsPayment($invoice));
        $this->assertNull(InvoiceTransaction::query()->where('transaction_id', 'WHMCS-OLD')->first());

        $report = $this->report();
        $all = json_encode($report);
        $this->assertStringContainsString('WHMCS-OLD', $all, 'the deleted WHMCS payment appears somewhere in the report');
    }

    /** ADV-690-2: a refund «by hand» (no gateway) of a payment WHMCS has: no GatewayRefund row, so no refund listed. */
    public function test_a_manual_refund_of_a_whmcs_payment_is_listed(): void
    {
        [, , $invoice] = $this->imported();

        ExtensionHelper::refundLocally($this->whmcsPayment($invoice), 2.00, notify: false);
        $this->assertSame('2.00', (string) $this->whmcsPayment($invoice)->refunded_amount);

        $report = $this->report();
        $this->assertNotSame([], $report['refunds'], 'the 2.00 given back is a refund to record on the WHMCS payment');
    }

    /** ADV-690-3: a dispute merely opened (nothing taken back) is listed as a refund of the whole amount. */
    public function test_a_dispute_only_opened_is_not_a_refund_to_carry_back(): void
    {
        [, , $invoice] = $this->imported();

        ExtensionHelper::chargebackOpened('stripe', 'WHMCS-OLD', 'dp_1', 3.00, 'USD');
        $this->assertSame('0.00', (string) $this->whmcsPayment($invoice)->refunded_amount, 'no money left');

        $refunds = $this->report()['refunds'];
        $this->assertSame([], $refunds, 'a refund row of 3.00 for money that never left: staff would RefundInvoicePayment it in WHMCS');
    }

    /** ADV-690-4: a WHMCS service with regdate 0000-00-00 (created_at NULL after the import) renewed or suspended here. */
    public function test_a_change_to_an_imported_service_without_a_created_at_is_listed(): void
    {
        [, $service] = $this->imported();
        DB::table('services')->where('id', $service->id)->update(['created_at' => null]); // as validateDate leaves 0000-00-00

        $service->refresh()->update(['expires_at' => '2027-12-01']);

        $report = $this->report();
        $this->assertSame([(string) $service->id], array_column($report['services_changed'], 0), 'its next due date moved: else WHMCS bills it again');
    }

    /** ADV-690-5: staff move a WHMCS domain to another client: written with a query, no audit. */
    public function test_a_domain_moved_to_another_client_is_listed(): void
    {
        [, , , $domain] = $this->imported();
        $to = User::factory()->create(['created_at' => '2020-01-01'])->client();
        $to->forceFill(['created_at' => '2020-01-01'])->save();
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);

        (new MoveToClient)->domain($domain, $to, $staff);
        $this->assertSame($to->id, $domain->fresh()->client_id);

        $this->assertSame([(string) $domain->id], array_column($this->report()['domains_changed'], 0), 'WHMCS still has it on the first client');
    }

    /** ADV-690-6: a WHMCS invoice merged into one the MyCS issued: the target takes the oldest date and stops being «new». */
    public function test_an_invoice_the_mycs_issued_stays_new_after_a_merge(): void
    {
        [$client, , $whmcs] = $this->imported();
        (new RecordPaymentService)->deleteTransaction($this->whmcsPayment($whmcs)); // merge refuses invoices with payments
        $mycs = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-11-25']);
        InvoiceItem::factory()->create(['invoice_id' => $mycs->id, 'price' => 12, 'quantity' => 1, 'description' => 'Renewal']);

        (new MergeInvoices)->handle($mycs->id, [$whmcs->id]);

        $report = $this->report();
        $this->assertContains((string) $mycs->id, array_column($report['invoices_new'], 0), 'WHMCS has no invoice #' . $mycs->id . ': it is created there first');
    }

    /** ADV-690-7: an --since with its zone («00:00 +02:00», Athens) compared as a wall-clock string with UTC rows. */
    public function test_since_with_a_time_zone_is_the_same_moment(): void
    {
        [, , $invoice] = $this->imported();
        Carbon::setTestNow('2026-11-14 23:00'); // UTC: an hour after the cut-over (22:00 UTC = 00:00 Athens)
        ExtensionHelper::addPayment($invoice->id, null, 4.00, transactionId: 'pi_late_night');
        Carbon::setTestNow('2026-11-15 10:00');

        $rows = $this->report(Carbon::parse('2026-11-15 00:00 +02:00'))['payments'];
        $this->assertContains('pi_late_night', array_column($rows, 9));
    }

    /** ADV-690-8: the lines of a new invoice are cut at 160 characters (and so in the CSV a script reads). */
    public function test_every_line_of_a_new_invoice_is_in_the_report(): void
    {
        [$client] = $this->imported();
        $new = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        foreach (range(1, 6) as $n) {
            InvoiceItem::factory()->create(['invoice_id' => $new->id, 'price' => 10, 'quantity' => 1,
                'description' => "Hosting plan Business {$n} (example{$n}.test) 15/11/2026 - 14/11/2027"]);
        }

        $lines = $this->report()['invoices_new'][0][9];
        $this->assertStringContainsString('example6.test', $lines, 'the sixth line is re-entered in WHMCS too');
    }

    /** ADV-690-9: a cell «-1+cmd|…» (a ticket's subject, by mail) is a formula in a spreadsheet: only «-digit» was let through. */
    public function test_a_formula_starting_with_minus_and_a_digit_is_text_in_the_csv(): void
    {
        [$client] = $this->imported();
        Ticket::factory()->create(['client_id' => $client->id, 'subject' => '-1+cmd|\' /C calc\'!A0']);
        $dir = sys_get_temp_dir() . '/adv690-' . getmypid() . '-' . uniqid();

        $this->artisan('mycs:rollback-report', ['--since' => '2026-11-14 22:00', '--csv' => $dir])->assertExitCode(0);

        $csv = (string) file_get_contents("{$dir}/tickets_new.csv");
        array_map('unlink', glob("{$dir}/*.csv") ?: []);
        rmdir($dir);
        $this->assertDoesNotMatchRegularExpression('/(^|,)"?-1\+cmd/m', $csv, 'quoted as text, as = + @ are');
    }

    /** ADV-690-10: a WHMCS service deleted here (no server action) and a line removed from a WHMCS invoice: the audits are
     *  dropped because changed() only keeps ids of rows that still exist. */
    public function test_whmcs_rows_deleted_in_the_mycs_are_listed(): void
    {
        [, $service, $invoice] = $this->imported();
        Config::set('audit.console', false); // as the importer: no «created» audit (it would be the MyCS's own line)
        $line = InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => 5, 'quantity' => 1, 'description' => 'Setup', 'created_at' => '2026-11-01']);
        Config::set('audit.console', true);

        (new DeleteService)->handle($service->fresh());
        $line->delete();
        $this->assertNull(Service::query()->find($service->id));

        $report = $this->report();
        $this->assertContains((string) $service->id, array_column($report['services_changed'], 0), 'WHMCS still bills the service');
        $this->assertContains((string) $line->id, array_column($report['invoice_lines_changed'], 0), 'WHMCS still has the line');
    }
}
