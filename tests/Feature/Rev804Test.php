<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\UnbilledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Adversarial review of #804 (no period on a line of another customer's invoice). The cut leaves the service with lines
 * that have no period, on invoices of a customer who is not its own: what the rest of the app then does with those
 * invoices (cancel, refund, pay), and with a suspended service, is what these tests ask. Invented rows, no network.
 * Each test fails on the PR's head for the reason its docblock gives.
 */
class Rev804Test extends TestCase
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

    /** @return list<string> the open invoices of the service's own customer that bill it, as «period_start» */
    private function ownOpen(int $service): array
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service)
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', Invoice::OPEN_STATUSES)->where('client_id', $this->owner))->orderBy('id')->get()
            ->map(fn (InvoiceItem $line) => $line->period_start?->toDateString() ?? 'no period')->all();
    }

    /** @return array{0: string|null, 1: string|null, 2: string|null} period and forgone mark of an invoice's only line */
    private function line(int $invoice): array
    {
        $line = InvoiceItem::query()->where('invoice_id', $invoice)->sole();

        return [$line->period_start?->toDateString(), $line->period_end?->toDateString(), $line->forgone_at?->toDateString()];
    }

    /**
     * REV-804-1 (high). The runbook step this PR adds tells staff, for a service named «a line on another customer's
     * invoice», to «cancel the open invoice left with the first customer, so the same period is not paid twice». A service
     * moved after its last payment has BOTH lines with the first customer (the PR's own «both lines» shape), so after the
     * cut it has no period line at all. Staff work the import's list on the day of the cut-over, before the first nightly
     * run: «Cancel» (its default, «as usual») on the first customer's invoice #530 then takes the service for one with no
     * periods and gives up ITS next period (InvoiceObserver::givePeriodsUp, the loose branch: 2026-12-01..2027-01-01 is
     * written on the first customer's cancelled line, forgone). The cron never bills the service's own customer for it:
     * nothing to pay, suspended on 2026-12-12, terminated 60 days after the due date.
     *
     * What should happen: cancelling another customer's leftover invoice gives up nothing of a service that is not that
     * customer's (leave it: the cron bills the owner from the due date, as the PR and its runbook say), or the runbook
     * says how to do it safely (delete it, or cancel with «bill them all again»; both leave the owner billed).
     */
    public function test_cancelling_the_first_customers_open_invoice_as_the_runbook_says_does_not_stop_the_owner_being_billed(): void
    {
        Carbon::setTestNow('2026-11-26 10:00:00'); // the day of the cut-over: imported, the nightly run still to come
        $this->whmcsService(31, '2026-12-01');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-11-01', date: '2026-10-25');
        $this->whmcsInvoice(530, 'Unpaid', [31 => 10.0], due: '2026-12-01', date: '2026-11-17');
        $this->leftWithFirst(480);
        $this->leftWithFirst(530);
        $this->importWhmcs();
        $this->assertStringContainsString("a line on another customer's invoice: #31", $this->importSaid());
        $this->settings();

        // The runbook's step, with the form's default («as usual»: keep_periods_billable null).
        Invoice::query()->findOrFail(530)->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'left with the first customer']);

        $this->cronOn('2026-11-27');
        $this->assertSame(['2026-12-01'], $this->ownOpen(31), "the service's own customer is billed 2026-12-01..2027-01-01 (10.00); the first customer's cancelled line reads "
            . json_encode($this->line(530)));
    }

    /**
     * REV-804-2 (medium). The paid-line variant: the last paid line (#480, 2026-11-01..12-01, 10.00) is on the first
     * customer's invoice and now has no period, so the service has no period line. A full refund of that invoice (the
     * first customer's money, the first customer's invoice) gives up the NEXT period of a service that is another
     * customer's: 2026-12-01..2027-01-01 is written on the refunded line, forgone, and the cron never bills the owner for
     * it (suspended 2026-12-12 with nothing to pay). On main before this PR the line had its period and the refund gave up
     * nothing to come (#788's fix of exactly this); the cut brings the old guess back, now across two customers.
     *
     * What should happen: a refund of an invoice of another customer gives up nothing of this service (leave it); the
     * owner is billed from the due date.
     */
    public function test_a_refund_of_the_first_customers_paid_invoice_does_not_give_up_the_owners_next_period(): void
    {
        $this->whmcsService(31, '2026-12-01');
        $this->whmcsInvoice(440, 'Paid', [31 => 10.0], due: '2026-10-01', date: '2026-09-25');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-11-01', date: '2026-10-25');
        $this->leftWithFirst(480);
        $this->importWhmcs();
        $this->settings();

        ExtensionHelper::refundLocally(InvoiceTransaction::query()->where('invoice_id', 480)->sole(), 10.00, notify: false);
        $this->assertSame(Invoice::STATUS_REFUNDED, Invoice::query()->findOrFail(480)->status);

        $this->cronOn('2026-11-25');
        $this->assertSame(['2026-12-01'], $this->ownOpen(31), "the service's own customer is billed from 2026-12-01; the first customer's refunded line reads "
            . json_encode($this->line(480)));
    }

    /**
     * REV-804-3 (medium). The PR, its docs and the runbook say of a named service: «the cron bills its own customer from
     * its due date». Not a suspended one: WHMCS suspended the service for the unpaid renewal of 2026-11-01, the service
     * then moved and that invoice (#530, 10.00) stayed with the first customer. The cron does not bill a suspended service
     * again (decision A2), so the owner has nothing to pay, the service is terminated 60 days after 2026-11-01, and the
     * money check's service_unbilled (active services only) says nothing. The import names it under the new reason, but the
     * runbook's text for that reason tells staff the cron will bill it.
     *
     * What should happen: the owner has an invoice to pay for the period it is suspended for, or staff are told that this
     * one is not billed (the runbook says so for a suspended service: bill it by hand; or the money check names it).
     */
    public function test_a_suspended_service_whose_overdue_renewal_stayed_with_the_first_customer_gives_its_owner_something_to_pay(): void
    {
        $this->markTestIncomplete('known, older than #804 (2026-11-11): a suspended service whose overdue renewal stayed with another customer is named by the import and billed by hand');
        $this->whmcsService(31, '2026-11-01', status: 'Suspended');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-10-01', date: '2026-09-25');
        $this->whmcsInvoice(530, 'Unpaid', [31 => 10.0], due: '2026-11-01', date: '2026-10-25');
        $this->leftWithFirst(530);
        $this->importWhmcs();
        $this->assertStringContainsString("a line on another customer's invoice: #31", $this->importSaid());

        $this->cronOn('2026-11-21');
        $told = array_values(array_filter((new UnbilledServices)->find(), fn (array $found) => $found['service'] === 31));
        $this->cronOn('2027-01-05');

        $service = Service::query()->findOrFail(31);
        $this->assertTrue($this->ownOpen(31) !== [] || $told !== [], "the owner is billed for 2026-11-01..12-01, or the money check names the service; it is {$service->status}"
            . ' with no invoice of its own customer, and service_unbilled said ' . json_encode($told));
    }

    /**
     * REV-804-4 (low; older than #788, kept alive by the cut). The first customer's open invoice #530 (10.00, the renewal
     * from 2026-12-01) comes without a period, still payable and still charged to the first customer's saved method on its
     * due date. Paid before the cron bills the owner, it takes the service's next period: the owner's service runs to
     * 2027-01-01 on the first customer's money and the owner is not billed for that month. (Paid after the cron billed
     * the owner, it takes 2027-01-01..02-01 instead: one period billed to two customers, 20.00 for it.) The only guard is
     * the runbook's «cancel it», which is REV-804-1.
     *
     * What should happen: a payment of an invoice that is not the service's customer's renews nothing of the service
     * (as an ended service's: not applied, staff told), so the owner is still billed for 2026-12-01.
     */
    public function test_the_first_customers_payment_of_the_leftover_invoice_does_not_renew_the_owners_service(): void
    {
        $this->markTestIncomplete('known, older than #804 (2026-11-11): the leftover invoice is deleted by staff per the runbook');
        $this->whmcsService(31, '2026-12-01');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-11-01', date: '2026-10-25');
        $this->whmcsInvoice(530, 'Unpaid', [31 => 10.0], due: '2026-12-01', date: '2026-11-17');
        $this->leftWithFirst(530);
        $this->importWhmcs();
        $this->settings();

        (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(530), null, '10.00', 'tr_530_first');

        $this->assertSame('2026-12-01', Service::query()->findOrFail(31)->expires_at->toDateString(), "the first customer's 10.00 renewed another customer's service");
        $this->cronOn('2026-11-25');
        $this->assertSame(['2026-12-01'], $this->ownOpen(31));
    }

    /**
     * REV-804-5 (low). The PR says a service whose open renewal is on another customer's invoice is named under «a line
     * on another customer's invoice». With two unpaid lines left with the first customer (the renewals of 2026-11-01 and
     * 2026-12-01: it stopped paying, then the service moved) the service is named only under «more than one unpaid line»,
     * whose runbook advice («whichever is paid here takes the first unpaid month») is the wrong one: both invoices are
     * another customer's, and the cron bills the owner a third time for 2026-12-01.
     *
     * What should happen: the count of unpaid lines and the other-customer check both name it (name it under the new
     * reason too, with its lines), so staff read the advice that fits.
     */
    public function test_two_unpaid_lines_left_with_the_first_customer_are_named_as_another_customers(): void
    {
        $this->whmcsService(31, '2026-12-01');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-11-01', date: '2026-10-25');
        $this->whmcsInvoice(529, 'Unpaid', [31 => 10.0], due: '2026-11-01', date: '2026-10-17');
        $this->whmcsInvoice(530, 'Unpaid', [31 => 10.0], due: '2026-12-01', date: '2026-11-17');
        $this->leftWithFirst(529);
        $this->leftWithFirst(530);
        $this->importWhmcs();
        $said = $this->importSaid();

        $this->assertSame([null, null, null], $this->line(530));
        $this->assertStringContainsString("a line on another customer's invoice: #31", $said, $said);
    }
}
