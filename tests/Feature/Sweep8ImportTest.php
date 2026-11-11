<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Sweep 8, import (adversarial verifier). S8-IMPORT-1: the unpaid first order of a pro rata product comes with a
 * period-less line; no pre-dump refusal takes it while it is young; paid after the cut-over, the service gets a whole
 * plan period instead of the pro rata one WHMCS billed (to the product's day).
 *
 * Since the importer gives the last paid line and the open renewal their period (WhmcsLinePeriods, owner 2026-11-11) the
 * tests of the period-less-line findings pass, with no billing rule changed; the others are marked incomplete, each with
 * its reason.
 */
class Sweep8ImportTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A monthly product (30.00) with pro rata day 1, «charge next month» after the 20th; a WHMCS order of $regdate whose
     * first invoice ($amount, WHMCS's pro rata price) is still Unpaid at the dump (2026-11-10). Imported, checked against
     * the importer's own refusals, then paid in full here.
     *
     * @return string the day the service is paid until after the payment
     */
    private function paidUntilAfterPayingTheImportedOrder(string $status, string $regdate, string $nextdue, float $amount, string $today = '2026-11-10'): string
    {
        Carbon::setTestNow($today . ' 10:00:00');
        $user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $product->product->update(['prorata_day' => 1, 'prorata_next_after_day' => 20]);
        $this->whmcs($user->client()->id, $product->product->id);
        $this->whmcs->exec("INSERT INTO tblhosting VALUES (31, {$this->whmcsClientId}, NULL, {$this->whmcsProductId}, '{$regdate}', 'example.test', 1, 30, 'Monthly',
            '{$nextdue}', '{$status}', '', '', '', '', '', '', 0, '0000-00-00')");
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (502, {$this->whmcsClientId}, '', '{$regdate}', '{$regdate}', '0000-00-00 00:00:00', 'Unpaid', {$amount}, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems (invoiceid, userid, type, relid, description, amount, taxed, duedate)
            VALUES (502, {$this->whmcsClientId}, 'Hosting', 31, 'Hosting', {$amount}, 0, '{$regdate}')");

        // Nothing stops the import or names the order to staff: a young first order is the customer's to pay.
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $this->assertSame([], (new ReflectionMethod($command, 'abandonedOrders'))->invoke($command), 'not refused as an abandoned order');
        $this->assertSame([], (new ReflectionMethod($command, 'staleRenewals'))->invoke($command), 'not refused as a stale renewal');

        $this->importWhmcs();
        $invoice = $this->imported(502);
        $service = Service::query()->findOrFail(31);
        $this->assertSame(1, (int) $service->product->prorata_day, 'the service is on the pro rata product');

        (new RecordPaymentService)->recordByStaff($invoice, null, $amount, 'sweep8-import-1');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->refresh()->status);

        return (string) $service->refresh()->expires_at?->toDateString();
    }

    /** WHMCS left nextduedate on the order's day: 23.00 buys 08/11 to 01/12, here it buys to 08/12 (and leaves day 1). */
    public function test_s8_import_1_pending_prorata_order_due_on_regdate_is_paid_until_the_prorata_day(): void
    {
        $this->markTestIncomplete('S8-IMPORT-1: an UNPAID first order of a pro rata product. The importer writes a period on the last PAID line and on the open renewal of a LIVE service only (owner 2026-11-11); a Pending service is not live, so its line comes as before and the payment still gives a whole plan period.');

        $this->assertSame('2026-12-01', $this->paidUntilAfterPayingTheImportedOrder('Pending', '2026-11-08', '2026-11-08', 23.0));
    }

    /** The same order, set up before payment (Active in WHMCS). */
    public function test_s8_import_1_active_prorata_order_due_on_regdate_is_paid_until_the_prorata_day(): void
    {
        $this->markTestIncomplete("S8-IMPORT-1: an UNPAID first order of a pro rata product, Active, due on the day it was made. Its line is the open renewal by the rule (due on the due date) and takes one whole cycle from it, as the payment gave it before: 2026-12-08, not the product's day. The service is named in the import's output (no paid line). Not covered by the design of 2026-11-11.");

        $this->assertSame('2026-12-01', $this->paidUntilAfterPayingTheImportedOrder('Active', '2026-11-08', '2026-11-08', 23.0));
    }

    /** WHMCS put nextduedate on the pro rata day from the start (docs/importer.md): 23.00 buys to 01/12, here to 01/01. */
    public function test_s8_import_1_pending_prorata_order_due_on_the_prorata_day_gets_no_free_month(): void
    {
        $this->markTestIncomplete('S8-IMPORT-1: an UNPAID first order of a pro rata product. The importer writes a period on the last PAID line and on the open renewal of a LIVE service only (owner 2026-11-11); a Pending service is not live, so its line comes as before and the payment still gives a whole plan period.');

        $this->assertSame('2026-12-01', $this->paidUntilAfterPayingTheImportedOrder('Pending', '2026-11-08', '2026-12-01', 23.0));
    }

    public function test_s8_import_1_active_prorata_order_due_on_the_prorata_day_gets_no_free_month(): void
    {
        $this->markTestIncomplete("S8-IMPORT-1: an UNPAID first order of a pro rata product, Active, its due date already on the product's day. Its line is not due on the due date, so it comes with no period as before and the payment gives a whole month from 2026-12-01. The service is named in the import's output (no paid line). Not covered by the design of 2026-11-11.");

        $this->assertSame('2026-12-01', $this->paidUntilAfterPayingTheImportedOrder('Active', '2026-11-08', '2026-12-01', 23.0));
    }

    /**
     * An order placed after the «charge next month» day (25/10 > day 20), dump three days later: WHMCS billed 25/10 to
     * 01/12 (about 37.00). Here the customer is paid until 25/11 only: days paid for are lost.
     */
    public function test_s8_import_1_prorata_order_after_the_charge_next_month_day_keeps_the_month_it_paid_for(): void
    {
        $this->markTestIncomplete('S8-IMPORT-1: an UNPAID first order of a pro rata product. The importer writes a period on the last PAID line and on the open renewal of a LIVE service only (owner 2026-11-11); a Pending service is not live, so its line comes as before and the payment still gives a whole plan period.');

        $this->assertSame('2026-12-01', $this->paidUntilAfterPayingTheImportedOrder('Pending', '2026-10-25', '2026-10-25', 37.0, '2026-10-28'));
    }
}
