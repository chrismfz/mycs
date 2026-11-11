<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Sweep 8, cron-billing (adversarial verifier): the renewal rules on what the WHMCS importer brings (lines with no
 * period, paid ones never applied). Each test fails for the finding in its name.
 *
 * Since the importer gives the last paid line and the open renewal their period (WhmcsLinePeriods, owner 2026-11-11) the
 * tests of the period-less-line findings pass, with no billing rule changed; the others are marked incomplete, each with
 * its reason.
 */
class Sweep8CronBillingTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow('2026-09-20 10:00:00');
        $user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($user->client()->id, $product->product->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(): void
    {
        config([
            'settings.cronjob_invoice' => 7,
            'settings.credits_enabled' => false,
            'settings.cronjob_order_suspend' => 8,
            'settings.cronjob_order_terminate' => 60,
        ]);
    }

    private function cronOn(string $day): void
    {
        Carbon::setTestNow($day . ' 03:00:00');
        $this->settings();
        $this->artisan('app:cron-job');
    }

    /** @return list<string> the service's open invoices, as «id status period_start» */
    private function openInvoicesOf(int $service): array
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service)
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', Invoice::OPEN_STATUSES))->orderBy('id')->get()
            ->map(fn (InvoiceItem $line) => "#{$line->invoice_id} " . ($line->period_start?->toDateString() ?? 'no period'))->all();
    }

    /** S8-CRON-BILLING-1 */
    public function test_paying_the_first_ever_invoice_of_an_imported_service_its_unpaid_renewal_renews_it(): void
    {
        // In WHMCS since 2024 with no earlier Hosting line (made by staff, a free first order, an order invoice deleted).
        $this->whmcsService(31, '2026-09-10');
        $this->whmcsInvoice(501, 'Unpaid', [31 => 10.0], due: '2026-09-10');
        $this->importWhmcs();
        $this->settings();
        $this->imported(501);
        $this->assertSame('2026-09-10', Service::query()->findOrFail(31)->expires_at->toDateString());

        (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(501), null, '10.00', 'tr_501');

        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->findOrFail(501)->status);
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'the renewal it paid moves its date one month');

        $this->cronOn('2026-09-21');
        $this->assertSame([], $this->openInvoicesOf(31), 'the month just paid is not billed again');
    }

    /** S8-CRON-BILLING-2 */
    public function test_a_full_refund_of_an_imported_paid_invoice_does_not_stop_the_next_renewal(): void
    {
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(400, 'Paid', [31 => 10.0], due: '2024-01-01');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->importWhmcs();
        $this->settings();
        $this->imported(480);

        ExtensionHelper::refundLocally(InvoiceTransaction::query()->where('invoice_id', 480)->firstOrFail(), 10.00, notify: false);
        $this->assertSame(Invoice::STATUS_REFUNDED, Invoice::query()->findOrFail(480)->status);
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString());

        $this->cronOn('2026-10-04');
        $this->assertSame(Service::STATUS_ACTIVE, Service::query()->findOrFail(31)->status);
        $this->assertCount(1, $this->openInvoicesOf(31), 'its next period (from 2026-10-10) is billed: the refunded invoice paid a period already behind it');

        $this->cronOn('2026-10-19');
        $this->assertNotSame([], $this->openInvoicesOf(31), 'never suspended with nothing to pay');
    }

    /** S8-CRON-BILLING-3 */
    public function test_cancel_and_bill_again_on_the_imported_invoice_of_a_service_suspended_for_non_payment_bills_it_again(): void
    {
        $this->whmcs->exec("INSERT INTO tblhosting VALUES (42, {$this->whmcsClientId}, NULL, {$this->whmcsProductId}, '2024-01-01', 'example.test', 1, 10, 'Monthly',
            '2026-08-25', 'Suspended', '', '', '', '', '', '', 0, '0000-00-00')");
        $this->whmcsInvoice(342, 'Paid', [42 => 10.0], due: '2024-01-01');
        $this->whmcsInvoice(542, 'Unpaid', [42 => 10.0], due: '2026-08-25');
        $this->importWhmcs();
        $this->settings();
        $service = Service::query()->findOrFail(42);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->status);
        $this->assertNotNull($service->dunning_suspended_at, 'suspended for non-payment');

        // Staff: Cancel → «Bill them all again» (InvoiceResource, periods = keep).
        $invoice = $this->imported(542);
        $invoice->keep_periods_billable = true;
        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 're-issue']);
        $this->assertSame([], $this->openInvoicesOf(42));

        $this->cronOn('2026-09-21');
        $this->cronOn('2026-09-22');
        $this->assertCount(1, $this->openInvoicesOf(42), 'the period staff asked to bill again is billed, so the suspended customer has something to pay');
    }
}
