<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\EditPayment;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Second adversarial pass on #805, after its rework (head e4608c4e): «a period taken back is given again when its
 * invoice is paid again» (RenewServiceService: lostItsPeriod && paidAgainSince). Written without the author's reasoning.
 * Each test fails on that head for the reason its docblock gives and says what should happen, preferring «tell staff»
 * or «cut the case» to new automation (KISS). Fixtures are invented. Suspend Days 8, invoices 7 days before.
 */
class Rev805R2Test extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private User $user;

    private Plan $plan;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Cache::flush();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->settings();
        $this->user = User::factory()->create();
        $created = $this->createProduct();
        Price::query()->where('plan_id', $created->plan->id)->update(['price' => 10.00]);
        $this->plan = $created->plan;
        $this->product = $created->product;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(): void
    {
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_invoice' => 7,
            'settings.credits_auto_use' => false, 'settings.credits_enabled' => true]);
    }

    private function service(string $due): Service
    {
        return Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $this->plan->id, 'product_id' => $this->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => $due, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1]);
    }

    /** An open renewal invoice of the service for the month from $from, due then. */
    private function renewal(Service $service, string $from): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $from]);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10,
            'period_start' => $from, 'period_end' => Carbon::parse($from)->addMonthNoOverflow()->toDateString()]);

        return $invoice->fresh();
    }

    /** @return list<string> the system mails to staff, «subject: body» */
    private function staffMails(): array
    {
        return EmailLog::query()->where('template_key', 'system')->orderBy('id')->get()
            ->map(fn (EmailLog $mail) => $mail->subject . ': ' . preg_replace('/\s+/', ' ', strip_tags((string) $mail->body)))->all();
    }

    /** @return list<string> the money check's findings, «rule subject: detail» */
    private function moneyCheck(): array
    {
        return array_map(fn (array $finding) => "{$finding['rule']} {$finding['subject']}: {$finding['detail']}", (new MoneyCheck)->run());
    }

    /**
     * REV-805-R2-1 (high: days given that were taken back on purpose, and nobody is told). The rule reads «the due date
     * lies inside the line's period» as «it was moved back after the payment was lost». It is also where staff put a date
     * on purpose while the invoice was PAID all along: a goodwill or credit correction (half the month given back as
     * credit, the date moved back to match), an addon aligned to its parent by hand («days lost ok», owner 2026-11-07).
     * Then staff correct the payment's amount (EditPayment: one action; it deletes and records again in one transaction,
     * the invoice is never seen unpaid). The invoice's apply_pending_since is new, the line is older, the date is inside
     * the period: the period is «given again».
     *
     * The renewal 2026-11-15..12-15 paid 10.00 on 11-10. On 11-11 staff move the date back to 2026-12-01. On 11-12 they
     * correct the payment to 12.00 (2.00 becomes credit): the service is due 2026-12-15 again, no mail, nothing in the
     * money check. On main and on the PR's first head the date stays 2026-12-01 («a replay never overrides staff
     * corrections»).
     *
     * Should: given again only when the invoice was really left unpaid with the date moved back meanwhile (the date
     * changed after the money was taken back: e.g. the journal's last reversal is older than the service's date change),
     * else nothing moves; or staff are told that the date moved and from what.
     */
    public function test_a_date_staff_moved_back_while_the_invoice_was_paid_stays_when_the_payment_is_corrected(): void
    {
        $service = $this->service('2026-11-15');
        $invoice = $this->renewal($service, '2026-11-15');
        $payment = (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-1');
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());

        Carbon::setTestNow('2026-11-11 10:00:00');
        $service->fresh()->applyStaffChanges(['expires_at' => '2026-12-01']);
        $this->assertSame([Invoice::STATUS_PAID, '2026-12-01'], [$invoice->fresh()->status, $service->fresh()->expires_at->toDateString()]);

        Carbon::setTestNow('2026-11-12 10:00:00');
        (new EditPayment)->handle($payment->fresh(), ['amount' => '12.00']);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $told = collect($this->staffMails())->contains(fn (string $mail) => str_contains($mail, "service #{$service->id}"))
            || collect($this->moneyCheck())->contains(fn (string $finding) => str_contains($finding, "service #{$service->id}"));
        $this->assertTrue($service->fresh()->expires_at->toDateString() === '2026-12-01' || $told,
            'staff set 2026-12-01 while invoice #' . $invoice->id . ' was paid; a correction of its payment moved the date to '
            . $service->fresh()->expires_at->toDateString() . ', and nobody was told');
    }

    /**
     * REV-805-R2-2 (high, the same root, at the cut-over). A WHMCS invoice imported paid, its line with its period
     * (WhmcsLinePeriods: 2026-09-10..10-10, applied as of its WHMCS payment), the service due 2026-10-10. After the
     * cut-over staff align its date by hand to 2026-10-01 (as the owner did for addons: «days lost ok»). Later a
     * correction common in the first days: the imported payment deleted and recorded again (its gateway or reference).
     * The line's applied_at is the WHMCS payment's day, the invoice becomes paid now, the date is inside the period: the
     * service is due 2026-10-10 again and the alignment is gone, silently. (With the date untouched the same correction
     * changes nothing: that held.)
     *
     * Should: as REV-805-R2-1.
     */
    public function test_an_imported_service_aligned_by_hand_keeps_its_date_when_its_whmcs_payment_is_recorded_again(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');
        $this->plan->update(['name' => 'Monthly']);
        $this->whmcs($this->user->client()->id, $this->product->id);
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->importWhmcs();
        $this->settings();
        $invoice = $this->imported(480);
        $this->assertNotNull($invoice->items->first()->period_end, 'imported with its period, applied');
        $service = Service::query()->findOrFail(31);
        $service->applyStaffChanges(['expires_at' => '2026-10-01']);

        Carbon::setTestNow('2026-09-21 10:00:00');
        (new RecordPaymentService)->deleteTransaction(InvoiceTransaction::query()->where('invoice_id', 480)->sole());
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '10.00', 'tr_480_corrected');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $told = collect($this->staffMails())->contains(fn (string $mail) => str_contains($mail, 'service #31'))
            || collect($this->moneyCheck())->contains(fn (string $finding) => str_contains($finding, 'service #31'));
        $this->assertTrue($service->fresh()->expires_at->toDateString() === '2026-10-01' || $told,
            'aligned by hand to 2026-10-01; the same 10.00 recorded again moved it to ' . $service->fresh()->expires_at->toDateString() . ', and nobody was told');
    }

    /**
     * REV-805-R2-3 (medium; silent on main, told on the PR's first head, silent again after the rework). The rule gives
     * the period only when the date is inside it (`period_start <= due`). The payment of 2026-11-15..12-15 deleted,
     * staff move the date back to 2026-11-14, one day before the start the money check names (a slip, or the day they
     * think the service was last paid to), and leave the invoice open. The customer pays the 10.00 on 11-12: nothing is
     * given (a line «paid ahead of an unpaid period», though no line bills 11-14..11-15), no mail, nothing in the money
     * check. The cron then bills the one day 2026-11-14..11-15 (0.33) and suspends the service for it (by 2026-11-26)
     * while the 10.00 for the month is paid.
     *
     * Should: the first head's notice, kept for the case the rule does not give (applied, paid again, the service's date
     * before the period's end and nothing given): one mail to staff.
     */
    public function test_a_date_moved_back_to_before_the_periods_start_is_given_or_told_when_the_invoice_is_paid(): void
    {
        $this->markTestIncomplete('REV-805-R2-3, open for the owner (2026-11-12): the line, un-applied by the date move, is applied as «paid ahead of '
            . 'an unpaid period» (the day 11-14..11-15 nothing bills yet): nothing given until the cron bills that day and it is paid; no mail');
        $service = $this->service('2026-11-15');
        $invoice = $this->renewal($service, '2026-11-15');
        $payment = (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-1');
        (new RecordPaymentService)->deleteTransaction($payment);
        $service->fresh()->applyStaffChanges(['expires_at' => '2026-11-14']);

        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '10.00', 'bank-2');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $given = $service->fresh()->expires_at->toDateString() === '2026-12-15';
        $told = collect($this->staffMails())->contains(fn (string $mail) => str_contains($mail, "#{$invoice->id}"))
            || collect($this->moneyCheck())->contains(fn (string $finding) => str_contains($finding, "service #{$service->id}") || str_contains($finding, "invoice #{$invoice->id}"));
        $this->assertTrue($given || $told, "10.00 paid on invoice #{$invoice->id} for 2026-11-15..2026-12-15: service #{$service->id} is due "
            . $service->fresh()->expires_at->toDateString() . ', no mail, nothing in the money check');
    }

    /**
     * REV-805-R2-4 (medium/low, introduced by the rework's ServiceUpgrade::heldBy). An upgrade to Big / Monthly paid 8.00
     * and completed, its payment deleted: `service_unbilled` says «runs on Big … by upgrade invoice #N, which is unpaid
     * again (owes 8.00)». Staff then change the service's plan on its form to Big / Yearly (another cycle of the same
     * product; «Change cycle» refuses while the upgrade invoice is open, the form does not). The service is still on Big
     * and the 8.00 is still owed, but heldBy compares the plan too: the finding is gone from the money check for good.
     * (When the invoice is paid, staff get «Paid upgrade not applied … Change the package by hand, or refund the
     * payment», for a customer who has the package.)
     *
     * Should: «still on what it gave» is the product (and the options it set); a plan of that product is its cycle.
     */
    public function test_an_unpaid_upgrade_is_still_reported_after_the_services_cycle_changed_on_the_same_product(): void
    {
        $service = $this->service('2026-12-15');
        $big = Product::factory()->create(['name' => 'Big']);
        $monthly = Plan::factory()->create(['priceable_id' => $big->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::query()->create(['plan_id' => $monthly->id, 'currency_code' => 'USD', 'price' => 20.00]);
        $yearly = Plan::factory()->create(['priceable_id' => $big->id, 'priceable_type' => Product::class, 'name' => 'Yearly',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::query()->create(['plan_id' => $yearly->id, 'currency_code' => 'USD', 'price' => 200.00]);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'plan_id' => $monthly->id, 'product_id' => $big->id,
            'status' => ServiceUpgrade::STATUS_PENDING, 'invoice_id' => $invoice->id]);
        $invoice->items()->create(['reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 8]);
        $payment = (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '8.00', 'bank-1');
        (new RecordPaymentService)->deleteTransaction($payment);
        $this->assertStringContainsString("by upgrade invoice #{$invoice->id}, which is unpaid again (owes 8.00 USD)", implode(' ', $this->moneyCheck()));

        $service->fresh()->applyStaffChanges(['plan_id' => $yearly->id, 'price' => '200.00']);
        $this->assertSame([$big->id, $yearly->id, Invoice::STATUS_UNPAID], [(int) $service->fresh()->product_id, (int) $service->fresh()->plan_id, $invoice->fresh()->status]);

        $this->assertStringContainsString("by upgrade invoice #{$invoice->id}, which is unpaid again (owes 8.00 USD)", implode(' ', $this->moneyCheck()),
            'still on Big by an upgrade whose 8.00 is owed: the money check stopped saying so when its cycle changed');
        $this->assertSame(1, InvoiceItem::query()->where('invoice_id', $invoice->id)->count());
    }
}
