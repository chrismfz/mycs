<?php

namespace Tests\Feature;

use App\Events\ServiceCancellation\Created;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Listeners\CancellationCreatedListener;
use App\Livewire\Invoices\Show as InvoiceShow;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of Δ3a (feat/d3a-cancel-period, docs/billing-cursor.md §Δ3a), written without the author's context.
 * Each test's docblock: the finding, and «Was FAILING (confirmed defect); fixed» or «regression coverage». D3A-1..11 failed
 * on b6b5d24b and are fixed in this PR (findings table on the PR).
 */
class D3aAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private ?User $owner = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']]);
        config(['settings.cronjob_order_suspend' => 8, 'settings.cronjob_order_terminate' => 60, 'settings.cronjob_invoice' => 7,
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $values): void
    {
        foreach ($values as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();
        $this->owner = User::factory()->create();

        return Service::factory()->create($attributes + [
            'client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25',
        ]);
    }

    private function renewals(Service $service): Collection
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereNotNull('period_start')->orderBy('period_start')->get();
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->run();
    }

    private function openInvoice(Service $service, string $start, ?string $end = null, ?int $clientId = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $clientId ?? $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => $start, 'created_at' => Carbon::parse($start)->subWeek()]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => $start, 'period_end' => $end ?? Carbon::parse($start)->addMonth()->toDateString()]);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => Carbon::parse($start)->subWeek()]);

        return $invoice->fresh();
    }

    /** A terminated service with one invoice kept as a debt («keep overdue invoices of ended services» on). */
    private function keptDebt(): array
    {
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 0]);
        $service = $this->service(['expires_at' => '2026-08-01', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-08-09']);
        $debt = $this->openInvoice($service, '2026-08-01');
        $this->cron();
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $debt->fresh()->status, 'precondition: kept as a debt');

        return [$service, $debt->fresh()];
    }

    // ---------------------------------------------------------------- findings

    /**
     * D3A-1. «Keep overdue invoices of ended services» is applied when an end-of-period cancellation request is made
     * (CancellationCreatedListener), but not when the cron ends the service on its date (CronJob::endAsRequested still
     * cancels every open invoice, without CancelOpenInvoices::onServiceEnd). The debt kept at request time is cancelled
     * at the end: the setting has no effect on end-of-period requests, and the debt is silently given away.
     * Was FAILING (confirmed defect); fixed in this PR.
     */
    public function test_d3a1_end_of_period_request_keeps_the_overdue_debt_when_the_service_ends(): void
    {
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 0]);
        $service = $this->service(['expires_at' => '2026-10-10']);
        $overdue = $this->openInvoice($service, '2026-10-10');

        $cancellation = ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'moving']);
        (new CancellationCreatedListener)->handle(new Created($cancellation));
        $this->assertSame(Invoice::STATUS_UNPAID, $overdue->fresh()->status, 'kept at request time');

        $this->cron(); // cancellations_processed: the service ends on its date (2026-10-10, past)

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $overdue->fresh()->status, 'the debt kept at request time must stay a debt when the service ends');
    }

    /**
     * D3A-2. A kept debt cannot be paid by its customer: the client area refuses any invoice that bills an ended service
     * (Livewire Invoices\Show::processPayment, Invoice::billsEndedService), while the payment reminders keep asking for
     * it. The «debt» is collectable only by staff recording a payment by hand.
     * Was FAILING (confirmed defect); fixed in this PR.
     */
    public function test_d3a2_the_customer_can_pay_a_kept_debt_in_the_client_area(): void
    {
        [, $debt] = $this->keptDebt();

        $this->actingAs($this->owner);
        Livewire::test(InvoiceShow::class, ['invoice' => $debt])
            ->call('processPayment')
            // Blocked: «This invoice bills a service that has ended: please contact us.»
            ->assertNotDispatched('notify', fn (string $name, array $params) => str_contains((string) ($params[0]['message'] ?? ''), 'has ended'));
    }

    /**
     * D3A-3. Credit does not pay a kept debt: CreditAutoApply::eligible skips any invoice of an ended service, so a
     * customer with credit keeps both the credit and the debt (decision 5: credit pays what is open, oldest first).
     * Was FAILING (confirmed defect); fixed in this PR.
     */
    public function test_d3a3_credit_pays_a_kept_debt(): void
    {
        [$service, $debt] = $this->keptDebt();
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        (new CreditLedger)->add((int) $service->client_id, 'USD', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'goodwill');

        (new CreditAutoApply)->forCustomer((int) $service->client_id, 'USD');

        $this->assertSame(Invoice::STATUS_PAID, $debt->fresh()->status);
    }

    /**
     * D3A-4. The daily money check flags every kept debt as an anomaly: MoneyCheck «open_for_cancelled» («open, and every
     * service it bills has ended») was written for invoices left open by mistake; with the setting on it fires daily for
     * every debt kept on purpose.
     * Was FAILING (confirmed defect); fixed in this PR.
     */
    public function test_d3a4_money_check_does_not_flag_a_kept_debt(): void
    {
        [, $debt] = $this->keptDebt();

        $findings = collect((new MoneyCheck)->run(['open_for_cancelled']))->where('subject', "invoice #{$debt->id}");

        $this->assertSame([], $findings->values()->all(), 'a debt kept on purpose is not an inconsistency');
    }

    /**
     * D3A-5. A kept debt paid later is flagged by the money check as «paid_for_cancelled» («the payment bought nothing …
     * refund it or move it to credit»), and RenewServiceService mails staff to refund or credit it. Settling a debt is not
     * an error to undo.
     * Was FAILING (confirmed defect); fixed in this PR.
     */
    public function test_d3a5_money_check_does_not_flag_a_kept_debt_paid_later(): void
    {
        [, $debt] = $this->keptDebt();
        (new RecordPaymentService)->handle($debt, null, 10.0, transactionId: 'wire-debt');
        $this->assertSame(Invoice::STATUS_PAID, $debt->fresh()->status);

        $findings = collect((new MoneyCheck)->run(['paid_for_cancelled']))->where('subject', "invoice #{$debt->id}");

        $this->assertSame([], $findings->values()->all(), 'a settled debt is not money to give back');
    }

    /**
     * D3A-6. Deleting a cancelled renewal invoice (EditInvoice DeleteAction / API destroy: allowed, it has no payments)
     * deletes its forgone_at lines, and the cron bills the period given up on again the next day: the old behaviour
     * comes back through a «clean-up» of cancelled invoices.
     * Was FAILING (confirmed defect); fixed in this PR.
     */
    public function test_d3a6_deleting_a_cancelled_renewal_does_not_bill_its_period_again(): void
    {
        $service = $this->service();
        $this->cron();
        $invoice = $this->renewals($service)->sole()->invoice;
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        $this->assertNotNull($this->renewals($service)->sole()->forgone_at);

        try {
            $invoice->fresh()->delete(); // staff clean up cancelled invoices
        } catch (DisplayException) {
            // refused: it records the period given up on (the fix)
        }
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();

        $this->assertFalse($this->renewals($service)->contains(fn (InvoiceItem $line) => $line->period_start->toDateString() === '2026-10-25'
            && $line->invoice->status === Invoice::STATUS_UNPAID), 'the period given up on is billed again after its invoice was deleted');
    }

    /**
     * D3A-7. An open renewal without a period on its line (every unpaid invoice the WHMCS importer brings at cut-over;
     * an invoice staff made by hand) is not marked when staff cancel it (InvoiceObserver::renewalLines needs
     * period_start/period_end), so the cron bills the same period the next day: exactly the pre-Δ3 behaviour for every
     * imported open invoice cancelled after the cut-over.
     * Was FAILING (confirmed defect); fixed in this PR.
     */
    public function test_d3a7_cancelling_an_imported_renewal_without_a_period_does_not_bill_it_again(): void
    {
        $service = $this->service();
        $imported = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-10-25']);
        $imported->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Hosting (25/10/2026 - 24/11/2026)',
            'quantity' => 1, 'price' => 10]);
        $this->cron();
        $this->assertCount(0, $this->renewals($service), 'precondition: the open imported invoice stops the cron');

        $imported->fresh()->update(['status' => Invoice::STATUS_CANCELLED]); // staff: «not this period»
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();

        // The imported line now records the period given up on (marked); no open invoice bills it again.
        $this->assertCount(0, $this->renewals($service)->filter(fn (InvoiceItem $line) => $line->invoice->status === Invoice::STATUS_UNPAID),
            'the period of the cancelled (imported) renewal is billed again');
    }

    /**
     * D3A-8. A paid period waiting behind a period given up on: the cron only notices staff, the service is suspended
     * (nothing to pay, A2 bills nothing) and then terminated by the cron with the paid period never delivered; the money
     * stays on the paid invoice (MoneyCheck paid_for_cancelled). «No customer stuck suspended with nothing to pay» and no
     * termination of a service with a paid period ahead (endAsRequested refuses that case; the termination does not).
     * Was FAILING (confirmed defect); fixed in this PR.
     */
    public function test_d3a8_a_service_with_a_paid_period_behind_a_forgone_one_is_not_terminated(): void
    {
        $service = $this->service();
        $this->cron(); // A: 2026-10-25 .. 2026-11-25
        $a = $this->renewals($service)->sole()->invoice;
        $b = $this->openInvoice($service, '2026-11-25', '2026-12-25');
        (new RecordPaymentService)->handle($b, null, 10.0, transactionId: 'wire-b'); // paid ahead: waits behind A
        $this->assertSame(Invoice::STATUS_PAID, $b->fresh()->status);
        $a->fresh()->update(['status' => Invoice::STATUS_CANCELLED]); // staff give A up

        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->cron();
        Carbon::setTestNow('2026-12-26 10:00:00');
        $this->cron();

        $this->assertNotSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'terminated with a paid period never delivered');
    }

    /**
     * D3A-9. Π4.1 is not met in its most common case: a service long suspended for non-payment (A2: no later period is
     * billed while suspended) whose customer pays the one open invoice. stillOverdue() only keeps it suspended when
     * another unpaid invoice exists; there is none, so the payment unsuspends it, and the same night the cron bills the
     * next (already overdue) period and suspends it again: an unsuspend and a suspend (two mails, two server jobs).
     * Was FAILING (confirmed defect); fixed in this PR: it stays suspended and the cron bills its next period.
     */
    public function test_d3a9_a_payment_does_not_unsuspend_a_service_the_next_run_suspends_again(): void
    {
        $service = $this->service(['expires_at' => '2026-08-20', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-08-29']);
        $only = $this->openInvoice($service, '2026-08-20', '2026-09-20');

        (new RecordPaymentService)->handle($only, null, 10.0, transactionId: 'wire-1');
        $afterPayment = $service->fresh()->status;
        $this->cron();
        $afterCron = $service->fresh()->status;

        $this->assertFalse($afterPayment === Service::STATUS_ACTIVE && $afterCron === Service::STATUS_SUSPENDED,
            "unsuspended by the payment ({$afterPayment}), suspended again by the next run ({$afterCron}), paid until " . $service->fresh()->expires_at->toDateString());
    }

    /**
     * D3A-10. Reopening an invoice cancelled as a consequence (here a cancellation request, later withdrawn) after the
     * cron already billed that period again leaves two open invoices for the same period: the customer is asked twice.
     * Not new in Δ3a (it only stops this for invoices cancelled as such), but Δ3a's «reopen» rule makes reopening the
     * documented remedy. Was FAILING (confirmed defect, pre-existing); fixed in this PR.
     */
    public function test_d3a10_reopening_after_the_period_was_billed_again_does_not_bill_it_twice(): void
    {
        $service = $this->service();
        $this->cron();
        $a = $this->renewals($service)->sole()->invoice;
        $cancellation = ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'maybe']);
        (new CancellationCreatedListener)->handle(new Created($cancellation));
        $this->assertSame(Invoice::STATUS_CANCELLED, $a->fresh()->status);
        $cancellation->delete(); // withdrawn

        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();
        $this->assertCount(2, $this->renewals($service), 'precondition: billed again (not forgone)');

        try {
            $a->fresh()->update(['status' => Invoice::STATUS_UNPAID]); // staff reopen the old one
        } catch (DisplayException) {
            // refused: fine
        }

        $open = $this->renewals($service)->filter(fn (InvoiceItem $line) => $line->period_start->toDateString() === '2026-10-25'
            && in_array($line->invoice->fresh()->status, Invoice::OPEN_STATUSES, true));
        $this->assertLessThanOrEqual(1, $open->count(), 'two open invoices for the period 2026-10-25');
    }

    /**
     * D3A-11. The migration's backfill copies invoice_items.updated_at into forgone_at: a line whose updated_at is NULL
     * (rows written with the query builder: imports, data fixes) stays unmarked, and its period is billed again.
     * Was FAILING (confirmed defect); fixed in this PR.
     */
    public function test_d3a11_backfill_marks_lines_without_updated_at(): void
    {
        $service = $this->service();
        $cancelled = $this->openInvoice($service, '2026-10-25');
        DB::table('invoices')->where('id', $cancelled->id)->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => null]);
        DB::table('invoice_items')->where('invoice_id', $cancelled->id)->update(['updated_at' => null]);

        $this->rerunForgoneMigration();

        $this->assertNotNull(DB::table('invoice_items')->where('invoice_id', $cancelled->id)->value('forgone_at'));
    }

    // ---------------------------------------------------------------- regression coverage

    /**
     * A cancellation request withdrawn: its cancellation did not give the period up, and the cron bills it again.
     * Regression coverage.
     */
    public function test_r1_a_withdrawn_cancellation_request_is_billed_again(): void
    {
        $service = $this->service();
        $this->cron();
        $cancellation = ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'maybe']);
        (new CancellationCreatedListener)->handle(new Created($cancellation));
        $this->assertNull($this->renewals($service)->sole()->forgone_at);
        $cancellation->delete();

        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();

        $this->assertSame('2026-10-25', $this->renewals($service)->last()->period_start->toDateString());
        $this->assertSame(Invoice::STATUS_UNPAID, $this->renewals($service)->last()->invoice->status);
    }

    /**
     * Staff cancel an invoice billing two services: both periods are given up on, and neither is billed again.
     * Regression coverage.
     */
    public function test_r2_a_shared_invoice_cancelled_by_staff_gives_up_both_periods(): void
    {
        $one = $this->service();
        $two = $this->service(['client_id' => $one->client_id]);
        $invoice = $this->openInvoice($one, '2026-10-25');
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $two->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => '2026-10-25', 'period_end' => '2026-11-25']);

        $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED]);
        $this->cron();

        $this->assertCount(1, $this->renewals($one));
        $this->assertCount(1, $this->renewals($two));
        $this->assertNotNull($this->renewals($one)->sole()->forgone_at);
        $this->assertNotNull($this->renewals($two)->sole()->forgone_at);
    }

    /**
     * A partial refund leaves the invoice paid: nothing is given up on, the period stays paid.
     * Regression coverage.
     */
    public function test_r3_a_partial_refund_does_not_give_the_period_up(): void
    {
        $service = $this->service();
        $this->cron();
        $invoice = $this->renewals($service)->sole()->invoice;
        (new RecordPaymentService)->handle($invoice, null, 10.0, transactionId: 'wire-p');
        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 4.0);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertNull($this->renewals($service)->sole()->forgone_at);
        $this->assertSame('2026-11-25', $service->fresh()->expires_at->toDateString());
    }

    /**
     * Cancelled, reopened as a draft (cleared), cancelled again (marked again); reopened and paid through MarkInvoicePaid's
     * reopen (cancelled -> unpaid -> paid): cleared and renewed.
     * Regression coverage.
     */
    public function test_r4_cancel_reopen_cycles_keep_the_mark_in_step(): void
    {
        $service = $this->service();
        $this->cron();
        $invoice = $this->renewals($service)->sole()->invoice;

        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        $invoice->fresh()->update(['status' => Invoice::STATUS_DRAFT]);
        $this->assertNull($this->renewals($service)->sole()->forgone_at);
        $invoice->fresh()->update(['status' => Invoice::STATUS_CANCELLED]);
        $this->assertNotNull($this->renewals($service)->sole()->forgone_at);

        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, 10.0, 'wire-r4', reopen: true);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertNull($this->renewals($service)->sole()->forgone_at);
        $this->assertSame('2026-11-25', $service->fresh()->expires_at->toDateString());
    }

    /**
     * The migration's backfill: cancelled by staff (no reason, or their own) and refunded are marked; cancelled by the
     * app with one of its reasons, in English or Greek, is not; open invoices and lines without a period are not.
     * Regression coverage.
     */
    public function test_r5_migration_backfill(): void
    {
        $service = $this->service();
        $make = function (string $status, ?string $reason, bool $period = true) use ($service): int {
            $invoice = $this->openInvoice($service, '2026-10-25');
            if (!$period) {
                DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['period_start' => null, 'period_end' => null]);
            }
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status, 'cancellation_reason' => $reason]);

            return $invoice->id;
        };
        $staffNull = $make(Invoice::STATUS_CANCELLED, null);
        $staffOwn = $make(Invoice::STATUS_CANCELLED, 'Duplicate, issued again by hand');
        $refunded = $make(Invoice::STATUS_REFUNDED, null);
        $appEn = $make(Invoice::STATUS_CANCELLED, trans('invoices.cancellation_reasons.cycle_changed', [], 'en'));
        $appEl = $make(Invoice::STATUS_CANCELLED, trans('invoices.cancellation_reasons.service_terminated', [], 'el'));
        $open = $make(Invoice::STATUS_UNPAID, null);
        $noPeriod = $make(Invoice::STATUS_CANCELLED, null, period: false);

        $this->rerunForgoneMigration();

        $marked = fn (int $id) => DB::table('invoice_items')->where('invoice_id', $id)->value('forgone_at') !== null;
        $this->assertTrue($marked($staffNull));
        $this->assertTrue($marked($staffOwn));
        $this->assertTrue($marked($refunded));
        $this->assertFalse($marked($appEn));
        $this->assertFalse($marked($appEl));
        $this->assertFalse($marked($open));
        $this->assertFalse($marked($noPeriod));
    }

    /**
     * Suspended: a real hole before a paid period is billed (A2's exception), and nothing past it.
     * Regression coverage.
     */
    public function test_r6_a_suspended_service_is_billed_for_a_hole_before_a_paid_period(): void
    {
        $service = $this->service(['expires_at' => '2026-09-01', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-09-10']);
        $ahead = $this->openInvoice($service, '2026-10-01', '2026-11-01');
        (new RecordPaymentService)->handle($ahead, null, 10.0, transactionId: 'wire-ahead');

        $this->cron();

        $lines = $this->renewals($service);
        $this->assertCount(2, $lines);
        $this->assertSame('2026-09-01', $lines->first()->period_start->toDateString());
        $this->assertSame('2026-10-01', $lines->first()->period_end->toDateString());
        $this->assertSame(Invoice::STATUS_UNPAID, $lines->first()->invoice->status);
    }

    /**
     * The notice's remedy: staff move the date over the period given up on, and the paid period behind it is picked up
     * (ServiceObserver chains the paid periods from the new date).
     * Regression coverage.
     */
    public function test_r7_moving_the_date_over_the_gap_delivers_the_paid_period(): void
    {
        $service = $this->service();
        $this->cron();
        $a = $this->renewals($service)->sole()->invoice;
        $b = $this->openInvoice($service, '2026-11-25', '2026-12-25');
        (new RecordPaymentService)->handle($b, null, 10.0, transactionId: 'wire-b');
        $a->fresh()->update(['status' => Invoice::STATUS_CANCELLED]);

        $service->fresh()->applyStaffChanges(['expires_at' => '2026-11-25']);

        $this->assertSame('2026-12-25', $service->fresh()->expires_at->toDateString());
    }

    /**
     * «Kept when overdue more than N days»: exactly N days overdue is cancelled, N+1 kept.
     * Regression coverage.
     */
    public function test_r8_keep_overdue_days_boundary(): void
    {
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 30]);
        $service = $this->service(['expires_at' => '2026-08-01', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-08-09']);
        $exactly = $this->openInvoice($service, '2026-09-20'); // 30 days before 2026-10-20
        $more = $this->openInvoice($service, '2026-09-19');   // 31 days

        $this->cron();

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $exactly->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $more->fresh()->status);
    }

    /**
     * A kept debt paid later does not revive the terminated service, also when paid by staff.
     * Regression coverage.
     */
    public function test_r9_a_kept_debt_paid_by_staff_does_not_revive(): void
    {
        [$service, $debt] = $this->keptDebt();

        (new RecordPaymentService)->recordByStaff($debt, null, 10.0, 'wire-staff');

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame('2026-08-01', $service->fresh()->expires_at->toDateString());
    }

    /**
     * The money check stays clean after a renewal given up on and a refunded one.
     * Regression coverage.
     */
    public function test_r10_money_check_clean_after_cancel_and_refund(): void
    {
        $one = $this->service();
        $two = $this->service();
        $this->cron();
        $this->renewals($one)->sole()->invoice->update(['status' => Invoice::STATUS_CANCELLED]);
        $paid = $this->renewals($two)->sole()->invoice;
        (new RecordPaymentService)->handle($paid, null, 10.0, transactionId: 'wire-r10');
        ExtensionHelper::refundLocally($paid->transactions()->sole(), 10.0);
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();

        $this->assertSame([], (new MoneyCheck)->run());
    }

    private function rerunForgoneMigration(): void
    {
        $migration = require database_path('migrations/2026_11_06_000000_add_forgone_at_to_invoice_items.php');
        $migration->down();
        $migration->up();
    }
}
