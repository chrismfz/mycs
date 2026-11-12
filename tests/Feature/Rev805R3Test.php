<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
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
 * Third adversarial pass on #805 (head a8e7d1df): the rule moved to Service::applyStaffChanges (staff moving the due
 * date back un-apply the service's applied lines on invoices unpaid at that moment). Written without the author's
 * reasoning. Each test fails on that head for the reason its docblock gives and says what should happen, preferring
 * «tell staff» or «cut the case» to new automation (KISS). Fixtures are invented. Suspend Days 8, invoices 7 days before.
 */
class Rev805R3Test extends TestCase
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
            'settings.credits_auto_use' => false, 'settings.credits_enabled' => false]);
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
     * REV-805-R3-1 (high: the harm of S10-STAFF-FREEDOM-1 itself, in the other order of the same two steps; as on main).
     * The rule un-applies a line only when the date is moved back while its invoice is ALREADY unpaid. Staff told of a
     * bounced transfer do the two things by hand, in either order: here the service first (due date back to 2026-11-15,
     * the invoice still paid: «a date set while it is paid is staff's», nothing touched), then the invoice (the payment
     * deleted). The line stays applied on an unpaid invoice with the date at its start. `service_unbilled` says nothing
     * (the service no longer holds the period), no warning anywhere. The customer pays the 10.00 on 2026-11-12: a line
     * applied before, nothing renewed, no mail, the money check clean; the cron suspends the service (as followed in the
     * first pass: on 2026-11-24, nothing billed after).
     *
     * Should: the same un-apply when an invoice becomes unpaid again (payment deleted, edited down, charged back) and its
     * service's date is already before the line's period end; or, with no new logic, a daily `service_unbilled` finding
     * for an applied line on an unpaid invoice whose service's date is before its period end («paying it renews nothing:
     * save the date again, or cancel it»).
     */
    public function test_the_date_moved_back_before_the_payment_is_deleted_leaves_an_invoice_that_renews_or_tells(): void
    {
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $this->plan->id, 'product_id' => $this->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-11-15', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1]);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-15']);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10,
            'period_start' => '2026-11-15', 'period_end' => '2026-12-15']);
        $payment = (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '10.00', 'bank-1');
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());

        // The same two steps as the pinned scenario, the service first.
        $service->fresh()->applyStaffChanges(['expires_at' => '2026-11-15']);
        (new RecordPaymentService)->deleteTransaction($payment->fresh());
        $this->assertSame([Invoice::STATUS_UNPAID, '2026-11-15'], [$invoice->fresh()->status, $service->fresh()->expires_at->toDateString()]);
        $toldBefore = collect($this->moneyCheck())->contains(fn (string $finding) => str_contains($finding, "service #{$service->id}") || str_contains($finding, "invoice #{$invoice->id}"));

        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '10.00', 'bank-2');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $given = $service->fresh()->expires_at->toDateString() === '2026-12-15';
        $told = $toldBefore || collect($this->staffMails())->contains(fn (string $mail) => str_contains($mail, "#{$invoice->id}"))
            || collect($this->moneyCheck())->contains(fn (string $finding) => str_contains($finding, "service #{$service->id}") || str_contains($finding, "invoice #{$invoice->id}"));
        $this->assertTrue($given || $told, "10.00 paid on invoice #{$invoice->id} for 2026-11-15..2026-12-15: service #{$service->id} is due "
            . $service->fresh()->expires_at->toDateString() . ', and nothing told staff before or after the payment');
    }

    /**
     * REV-805-R3-2 (medium: time given that nobody paid for; introduced by this rework). A line with no period is
     * un-applied by ANY move back of the date, however small, and when paid it takes a whole new cycle from the date as
     * it then is (RenewServiceService: «the first whole period no live invoice covers»). A line with a period gets its
     * own end at most; a line without one gets a cycle.
     *
     * Imported: service #31 due 2026-10-15, invoices #470 and #480 paid in WHMCS (10.00 each, no period). The payment of
     * #480 is deleted on 2026-09-20 (marked applied). On 09-25 staff move the date back five days, to 2026-10-10 (an
     * alignment, or five days taken for the trouble). The customer pays the 10.00: the service is due 2026-11-10. For
     * the one month's money it had 09-15..10-15 and now 26 days more; on main the date stays 2026-10-10.
     *
     * Should: a line with no period is un-applied only when the date goes back a whole cycle or more (the case the rule
     * is for); a smaller move leaves it applied, and staff are told on that save that its open invoice renews nothing.
     */
    public function test_a_small_move_back_does_not_give_a_whole_cycle_to_a_line_without_period(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');
        $this->plan->update(['name' => 'Monthly']);
        $this->whmcs($this->user->client()->id, $this->product->id);
        $this->whmcsService(31, '2026-10-15'); // its date moved by hand in WHMCS: no exact match, no period written
        $this->whmcsInvoice(470, 'Paid', [31 => 10.0], due: '2026-08-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->importWhmcs();
        $this->settings();
        $invoice = $this->importedWithoutPeriod(480);
        $service = Service::query()->findOrFail(31);
        (new RecordPaymentService)->deleteTransaction(InvoiceTransaction::query()->where('invoice_id', 480)->sole());
        $this->assertNotNull($invoice->fresh()->items->first()->applied_at, 'marked applied when it was reopened');

        Carbon::setTestNow('2026-09-25 10:00:00');
        $service->applyStaffChanges(['expires_at' => '2026-10-10']);
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '10.00', 'bank-2');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $this->assertLessThanOrEqual('2026-10-15', $service->fresh()->expires_at->toDateString(),
            'one month paid (10.00), the service was due 2026-10-15 before five days were taken back: it is now due ' . $service->fresh()->expires_at->toDateString());
    }
}
