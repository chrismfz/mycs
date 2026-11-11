<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use App\Services\Service\AppliedOpenInvoices;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Adversarial review of #805 (money check: «cancel the invoice and …», the form's warning, the notice of a paid invoice
 * that applies nothing). Written without the author's reasoning. Each test fails on the PR's head for the reason its
 * docblock gives, and says what should happen, preferring «tell staff» or «cut the case» to new automation (KISS).
 * Fixtures are invented. Suspend Days 8, invoices 7 days before, today 2026-11-10 unless a test says otherwise.
 */
class Rev805Test extends TestCase
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
            'settings.cronjob_payment_reminder' => 6, 'settings.cronjob_overdue_reminders' => '1,3',
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_invoice' => 7,
            'settings.credits_auto_use' => false, 'settings.credits_enabled' => false]);
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

    /**
     * The renewal 2026-11-15..2026-12-15 paid (10.00) and applied on 2026-11-10, its payment deleted the same day.
     *
     * @return array{Service, Invoice}
     */
    private function renewedThenPaymentDeleted(): array
    {
        $service = $this->service('2026-11-15');
        $invoice = $this->renewal($service, '2026-11-15');
        $payment = (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-1');
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());
        (new RecordPaymentService)->deleteTransaction($payment);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        return [$service->fresh(), $invoice->fresh()];
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'All ' . uniqid(), 'permissions' => ['*']])->id]);
    }

    /** The invoice list's «Cancel», with the form's «Its periods» choice (auto is its default). */
    private function cancel(Invoice $invoice, string $periods = 'auto'): void
    {
        $this->livewireAs($this->staff())->test(ListInvoices::class)
            ->callTableAction('cancel', $invoice, data: ['cancellation_reason' => 'not collected', 'periods' => $periods])->assertHasNoTableActionErrors();
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    private function cronUntil(string $day): void
    {
        while (now()->toDateString() < $day) {
            Carbon::setTestNow(now()->addDay());
            $this->artisan('app:cron-job')->assertExitCode(0);
        }
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
     * REV-805-1 (high; the harm is on main too, the PR's warning and notice both miss it). An invoice the WHMCS importer
     * brought paid, its line with no period (most of them: WhmcsLinePeriods writes one only on an exact match). Its
     * payment is deleted (or charged back): InvoiceObserver::markAppliedBeforeMyCs marks the line applied, still with no
     * period. `service_unbilled` says nothing of the service running to 2026-10-15 on it (periodNotPaid wants a
     * period_end, runsPastUnpaid a line not applied), so staff move the date back on their own, 2026-09-15; the form does
     * not warn (AppliedOpenInvoices wants a period_end). The customer pays the 10.00 on 2026-09-22: the line is applied,
     * nothing is renewed, and tellPaidForAPeriodTakenBack returns on `period_end === null`. The cron bills 2026-09-15..10-15
     * again on 09-23 (a second 10.00) and suspends the service on 09-24, two days after the payment; its mail lists the
     * new invoice as an ordinary unpaid one.
     *
     * Should: staff are told when a line applied before with no period is paid again and its service runs no further than
     * it did when the payment was taken back (or simply: every time such a line is paid again; they are few).
     */
    public function test_an_imported_line_without_period_paid_after_the_date_was_moved_back_tells_staff(): void
    {
        Carbon::setTestNow('2026-09-20 10:00:00');
        $this->plan->update(['name' => 'Monthly']);
        $this->whmcs($this->user->client()->id, $this->product->id);
        $this->whmcsService(31, '2026-10-15'); // its date moved by hand in WHMCS: no exact match, no period written
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->importWhmcs();
        $this->settings();
        $invoice = $this->importedWithoutPeriod(480);
        $service = Service::query()->findOrFail(31);

        (new RecordPaymentService)->deleteTransaction(InvoiceTransaction::query()->where('invoice_id', 480)->sole());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->items->first()->applied_at, 'marked applied when it was reopened');
        $service->applyStaffChanges(['expires_at' => '2026-09-15']);

        Carbon::setTestNow('2026-09-22 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '10.00', 'bank-2');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $given = $service->fresh()->expires_at->toDateString() > '2026-09-15';
        $told = collect($this->staffMails())->contains(fn (string $mail) => str_contains($mail, '#480'))
            || collect($this->moneyCheck())->contains(fn (string $finding) => str_contains($finding, 'service #31') || str_contains($finding, 'invoice #480'));

        $this->assertTrue($given || $told, '10.00 paid on invoice #480: service #31 still ends 2026-09-15, no mail to staff and nothing in the money check '
            . '(the cron bills the month again and suspends it on 2026-09-24)');
    }

    /**
     * REV-805-2 (medium). An upgrade on the same product (another plan of it; an options-only upgrade is the same shape),
     * paid 8.00 and completed, its payment deleted. The rule advises «cancel the invoice and change the package back».
     * Staff put the plan back and leave the invoice open (the half-step the PR is about): AppliedOpenInvoices warns only
     * when product_id changes, so the form says nothing; `service_unbilled` goes on saying «runs on Test Product … change
     * the package back» although it was (it compares the product only). The customer pays the 8.00: the upgrade is
     * completed and not applied again, and tellPaidForAPackageTakenBack returns on `product_id === upgrade.product_id`.
     * 8.00 taken, the service on the old plan at 10.00, no mail, and the money check is clean from then on.
     *
     * Should: compare what the upgrade gave (plan and product; for options, tell whenever a completed upgrade's invoice is
     * paid again), in the warning, the notice and upgradeNotPaid alike.
     */
    public function test_an_upgrade_on_the_same_product_paid_after_its_plan_was_changed_back_tells_staff(): void
    {
        $service = $this->service('2026-12-15');
        $plus = Plan::factory()->create(['priceable_id' => $this->product->id, 'priceable_type' => Product::class, 'name' => 'Monthly Plus',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::query()->create(['plan_id' => $plus->id, 'currency_code' => 'USD', 'price' => 20.00]);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'plan_id' => $plus->id, 'product_id' => $this->product->id,
            'status' => ServiceUpgrade::STATUS_PENDING, 'invoice_id' => $invoice->id]);
        $invoice->items()->create(['reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 8]);
        $payment = (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '8.00', 'bank-1');
        $this->assertSame([ServiceUpgrade::STATUS_COMPLETED, $plus->id], [$upgrade->fresh()->status, (int) $service->fresh()->plan_id]);
        (new RecordPaymentService)->deleteTransaction($payment);
        $this->assertStringContainsString('cancel the invoice and change the package back', implode(' ', $this->moneyCheck()));

        $warned = AppliedOpenInvoices::warnings($service->fresh()->forceFill(['plan_id' => $this->plan->id]), $service->expires_at, (int) $service->product_id) !== [];
        $service->fresh()->applyStaffChanges(['plan_id' => $this->plan->id, 'price' => '10.00']);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '8.00', 'bank-2');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $applied = (int) $service->fresh()->plan_id === (int) $plus->id;
        $told = collect($this->staffMails())->contains(fn (string $mail) => str_contains($mail, "#{$invoice->id}"))
            || $this->moneyCheck() !== [];

        $this->assertSame([true, true], [$warned, $applied || $told],
            '[the form warned of the half-step, the 8.00 paid for the upgrade was applied or told]: the service is on the old plan at 10.00');
    }

    /**
     * REV-805-3 (medium). The half-step, and nobody acts: the date moved back to 2026-11-15 with invoice #A open. The cron
     * suspends the service on 2026-11-24 and terminates it (Terminate Days 20) on 2026-12-06; the invoice stays unpaid and
     * payable (the money check says «open, and every service it bills has ended» until it is paid). The customer pays the
     * 10.00 on 2026-12-20, for 2026-11-15..12-15 of a service that ran nine days of it. tellPaidForAPeriodTakenBack
     * returns on `isEnded()`, and the «Payment for an ended service: needs staff attention» mail of the same method is
     * sent only for a line not applied yet: no mail at all, and the money check is clean afterwards.
     *
     * Should: the ended service's own notice («Refund the payment or move it to credit») also for a line applied before,
     * when its invoice is paid again.
     */
    public function test_an_applied_invoice_paid_after_its_service_was_terminated_tells_staff(): void
    {
        config(['settings.cronjob_order_terminate' => 20]);
        [$service, $invoice] = $this->renewedThenPaymentDeleted();
        $service->applyStaffChanges(['expires_at' => '2026-11-15']);

        $this->cronUntil('2026-12-20');
        $this->assertSame([Service::STATUS_TERMINATED, Invoice::STATUS_UNPAID], [$service->fresh()->status, $invoice->fresh()->status],
            'terminated for non-payment, its invoice still open and payable');
        $before = count($this->staffMails());

        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '10.00', 'bank-2');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $after = array_slice($this->staffMails(), $before);
        $this->assertTrue($after !== [] || $this->moneyCheck() !== [],
            "10.00 paid on invoice #{$invoice->id} for 2026-11-15..2026-12-15 of service #{$service->id}, terminated on 2026-12-06: no mail to staff, nothing in the money check");
    }

    /**
     * REV-805-4 (medium; the owner's call of 2026-11-12 was «staff are told, nothing is given»: this is about how long).
     * The scenario of Sweep10StaffFreedomTest::test_the_cron_does_not_suspend_a_service_whose_period_was_paid_again, whose
     * name no longer matches what it asserts. Date back to 2026-11-15, the open invoice paid (10.00) on 2026-11-12: one
     * mail to staff at 2026-11-12 10:00 («Paid invoice applied nothing»). From then on nothing: `service_unbilled` and
     * the whole money check are empty every day, the cron suspends the paying customer's service on 2026-11-24 (its mail
     * lists it under «Suspended for non-payment», unpaid invoices «—») and nothing is billed again (followed to 2026-12-28).
     * One missed mail and the customer who paid is suspended with nothing to pay.
     *
     * Should: the daily money check carries it while it lasts (a paid, applied line whose period ends after its running
     * service's due date, the invoice paid after the line was applied), as it carries the unpaid half; no new automation.
     */
    public function test_the_money_check_keeps_telling_of_a_service_paid_for_a_period_it_does_not_hold(): void
    {
        [$service, $invoice] = $this->renewedThenPaymentDeleted();
        $service->applyStaffChanges(['expires_at' => '2026-11-15']);
        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '10.00', 'bank-2');
        $this->assertSame([Invoice::STATUS_PAID, '2026-11-15'], [$invoice->fresh()->status, $service->fresh()->expires_at->toDateString()]);

        $this->cronUntil('2026-11-23');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'the day before the cron suspends it');

        $this->assertNotSame([], array_values(array_filter($this->moneyCheck(), fn (string $finding) => str_contains($finding, "service #{$service->id}") || str_contains($finding, "invoice #{$invoice->id}"))),
            "on 2026-11-23 service #{$service->id} is paid to 2026-12-15 on invoice #{$invoice->id} and due 2026-11-15: the money check says nothing, and the cron suspends it tomorrow");
    }

    /**
     * REV-805-5 (medium). The notice is repeated on every cron run while the invoice's processing keeps failing. An
     * invoice of two services: #1 (paid, applied, payment deleted, date moved back) and #2, which has no plan (its line
     * throws «has no plan», so the invoice keeps apply_pending_since). Paid 20.00 on 2026-11-12: the notice for #1 is
     * sent, and the cron's `paid_applied` replay processes line 1 again every run: it is still applied before the
     * invoice became paid (applied_at is not touched on this path), so the same mail goes out each time, 24 a day until
     * someone fixes service #2. Any other failure of the same invoice does it too (a server job not queued,
     * AfterCommit::takeNotQueued). Sweep10UnbilledAdviceTest's «the cron's replay: not told twice» calls
     * ProcessPaidInvoiceService on an invoice whose marker is already cleared, which the cron never replays.
     *
     * Should: told once per payment (for instance the line's applied_at moved to now once told, as paidAgain does).
     */
    public function test_the_notice_is_sent_once_when_the_cron_replays_an_invoice_that_keeps_failing(): void
    {
        $service = $this->service('2026-11-15');
        $other = $this->service('2026-11-15');
        $invoice = $this->renewal($service, '2026-11-15');
        $invoice->items()->create(['reference_id' => $other->id, 'reference_type' => Service::class, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10,
            'period_start' => '2026-11-15', 'period_end' => '2026-12-15']);
        DB::table('services')->where('id', $other->id)->update(['plan_id' => null]);
        $payment = (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '20.00', 'bank-1');
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());
        (new RecordPaymentService)->deleteTransaction($payment);
        $service->fresh()->applyStaffChanges(['expires_at' => '2026-11-15']);

        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '20.00', 'bank-2');
        $this->assertNotNull($invoice->fresh()->apply_pending_since, 'its second line failed: the cron applies it again');
        for ($run = 0; $run < 3; $run++) {
            Carbon::setTestNow(now()->addHour());
            $this->artisan('app:cron-job')->assertExitCode(0);
        }

        $this->assertCount(1, array_filter($this->staffMails(), fn (string $mail) => str_contains($mail, 'Paid invoice applied nothing')),
            'one payment, three cron runs: the same «Paid invoice applied nothing» mail each run');
    }

    /**
     * REV-805-6 (medium). The new advice followed to the letter, with the cancel form as it opens («Its periods»: auto).
     * The period 2026-11-15..12-15 has been reached by the cron (7 days before), so «auto» gives it up: the line is
     * forgone, the cron never bills it again. With the date back on 2026-11-15 the customer (whose payment bounced) has
     * nothing to pay: no invoice, no reminder; the cron suspends the service on 2026-11-24, its mail lists unpaid
     * invoices «—», and nothing is billed after. Meanwhile `service_unbilled` says, of the cancellation it advised,
     * «its period from 2026-11-15 was given up …: re-issue it, or move the due date». Only «keep billable» does what
     * «move the due date back» means (billed again once, on the next run, due 2026-11-15); the advice and the docs do
     * not say so. The same in either order of the two steps.
     *
     * Should: the advice names the choice («cancel it, keeping its period billable, and move the due date back to …»), or
     * the cancel form defaults to it for an applied line. Words, not logic.
     */
    public function test_the_advice_followed_with_the_cancel_forms_default_leaves_the_customer_something_to_pay(): void
    {
        [$service, $invoice] = $this->renewedThenPaymentDeleted();
        $this->assertStringContainsString('collect it, or cancel the invoice and move the due date back to 2026-11-15', implode(' ', $this->moneyCheck()));

        $this->cancel($invoice); // the form's default
        $service->fresh()->applyStaffChanges(['expires_at' => '2026-11-15']);
        $this->cronUntil('2026-11-24');

        $open = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereHas('invoice', fn ($invoices) => $invoices->where('status', Invoice::STATUS_UNPAID))->count();
        $this->assertSame([1, Service::STATUS_SUSPENDED], [$open, $service->fresh()->status],
            '[open invoices billing 2026-11-15..2026-12-15, its status on 2026-11-24]: suspended for non-payment with nothing the customer can pay');
    }

    /**
     * REV-805-7 (medium). The advice names the start of the unpaid line also when a later period is paid. A (11-15..12-15)
     * and B (12-15..01-15) both paid, 10.00 each; A's payment is charged back on 2026-12-10, five days before its period
     * ends: «runs to 2027-01-15 on invoice #A … cancel the invoice and move the due date back to 2026-11-15». Followed
     * with the form's default: A is given up, the date is 25 days in the past, and the cron suspends the service on its
     * next run (2026-12-11) while B's 10.00 for 12-15..01-15 is paid and can never count (a gap given up lies before
     * it). The cron then mails every run «a paid period waits behind a period given up on …: reopen that invoice, or
     * move the service's date over the gap»: the opposite of what the money check advised the day before.
     *
     * Should: with a later paid period the rule says «collect it» and the consequence only (or «…keeping its period
     * billable»), never a date before a period that is paid.
     */
    public function test_the_advice_does_not_cost_a_later_paid_period(): void
    {
        $service = $this->service('2026-11-15');
        $a = $this->renewal($service, '2026-11-15');
        $payment = (new RecordPaymentService)->recordByStaff($a, null, '10.00', 'bank-1');
        Carbon::setTestNow('2026-12-09 10:00:00');
        $b = $this->renewal($service, '2026-12-15');
        (new RecordPaymentService)->recordByStaff($b, null, '10.00', 'bank-2');
        $this->assertSame('2027-01-15', $service->fresh()->expires_at->toDateString());
        Carbon::setTestNow('2026-12-10 10:00:00');
        (new RecordPaymentService)->deleteTransaction($payment);
        $this->assertStringContainsString("runs to 2027-01-15 on invoice #{$a->id}, which is unpaid again since 2026-12-10 (owes 10.00 USD): "
            . 'collect it, or cancel the invoice and move the due date back to 2026-11-15', implode(' ', $this->moneyCheck()));

        $this->cancel($a->fresh());
        $service->fresh()->applyStaffChanges(['expires_at' => '2026-11-15']);
        $this->cronUntil('2026-12-11');

        $reopen = collect($this->staffMails())->contains(fn (string $mail) => str_contains($mail, 'a paid period waits behind a period given up on'));
        $this->assertSame([Service::STATUS_ACTIVE, false], [$service->fresh()->status, $reopen],
            "[service on 2026-12-11, the cron asks to reopen the invoice the money check said to cancel]: invoice #{$b->id} (10.00 for 2026-12-15..2027-01-15) is paid");
    }

    /**
     * REV-805-8 (low). Both steps done as advised, and done well («keep billable»): the cron bills 2026-11-15..12-15
     * again, the customer pays it, the service runs to 2026-12-15. The money check now reports the cancelled invoice
     * under `cancelled_applied` («cancelled, with a line that renewed its service») and goes on doing so every week,
     * for good: nothing staff can do clears the line's applied_at short of reopening the invoice. Each service handled
     * as `service_unbilled` advises leaves one such finding (the PR's tests read `service_unbilled` alone after the fix).
     *
     * Should: `cancelled_applied` does not count a line whose service no longer holds its period on it (the date was
     * moved back to its start or before, or another paid line covers the period).
     */
    public function test_the_money_check_is_clean_after_its_own_advice_was_followed_and_the_period_paid_again(): void
    {
        [$service, $invoice] = $this->renewedThenPaymentDeleted();
        $this->cancel($invoice, 'keep');
        $service->fresh()->applyStaffChanges(['expires_at' => '2026-11-15']);
        $this->cronUntil('2026-11-11');
        $again = Invoice::query()->where('status', Invoice::STATUS_UNPAID)->sole();
        (new RecordPaymentService)->recordByStaff($again, null, '10.00', 'bank-2');
        $this->assertSame([Invoice::STATUS_PAID, '2026-12-15', Service::STATUS_ACTIVE],
            [$again->fresh()->status, $service->fresh()->expires_at->toDateString(), $service->fresh()->status]);

        $this->assertSame([], $this->moneyCheck(), 'billed again and paid, everything as it should be');
    }

    /**
     * REV-805-9 (low; the form is as on main, the advice is the PR's). «Cancel the invoice and change the package back»,
     * done on the service form by choosing the old product and plan: the recurring price stays the upgrade's (20.00, the
     * price field is staff's own and nothing recalculates it), so the next renewal bills 20.00 for the 10.00 package. The
     * PR's own Sweep10StaffFreedomTest passes `price` by hand; Sweep10UnbilledAdviceTest's form test does not look.
     *
     * Should: the advice says so («…and change the package and its price back»); words only.
     */
    public function test_the_package_changed_back_on_the_form_as_advised_bills_the_old_packages_price(): void
    {
        $service = $this->service('2026-12-15');
        $big = Product::factory()->create(['name' => 'Big']);
        $bigPlan = Plan::factory()->create(['priceable_id' => $big->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::query()->create(['plan_id' => $bigPlan->id, 'currency_code' => 'USD', 'price' => 20.00]);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'plan_id' => $bigPlan->id, 'product_id' => $big->id,
            'status' => ServiceUpgrade::STATUS_PENDING, 'invoice_id' => $invoice->id]);
        $invoice->items()->create(['reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 8]);
        $payment = (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '8.00', 'bank-1');
        $this->assertSame('20.00', number_format((float) $service->fresh()->price, 2, '.', ''));
        (new RecordPaymentService)->deleteTransaction($payment);

        $this->cancel($invoice->fresh());
        $this->livewireAs($this->staff())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['product_id' => $this->product->id, 'plan_id' => $this->plan->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($this->product->id, (int) $service->fresh()->product_id);
        $this->cronUntil('2026-12-08');

        $next = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->whereDate('period_start', '2026-12-15')->sole();
        $this->assertSame('10.00', number_format((float) $next->price, 2, '.', ''), 'the renewal of 2026-12-15 on the old package, after its upgrade was taken back as advised');
    }

    /**
     * REV-805-10 (low). The notice says «the service's due date was moved back since» of a service nobody touched. Two
     * open renewals, A (11-15..12-15) and B (12-15..01-15); the customer pays B first: it is applied and waits behind A
     * (the date stays 2026-11-15, by design). Staff delete B's payment and record it again (a wrong reference): the line
     * was applied before this payment and the service ends before its period does, so the mail goes out, with the advice
     * «Set its due date to the end of that period» (2027-01-15, over the unpaid A).
     *
     * Should: not told while a live unpaid period lies between the service's date and the line (the ordinary «paid ahead
     * of an unpaid period»).
     */
    public function test_a_period_paid_ahead_of_an_unpaid_one_and_paid_again_is_not_told_as_taken_back(): void
    {
        $service = $this->service('2026-11-15');
        $this->renewal($service, '2026-11-15');
        $b = $this->renewal($service, '2026-12-15');
        $payment = (new RecordPaymentService)->recordByStaff($b, null, '10.00', 'bank-1');
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString(), 'B waits behind the unpaid A');
        Carbon::setTestNow('2026-11-10 11:00:00');
        (new RecordPaymentService)->deleteTransaction($payment);
        Carbon::setTestNow('2026-11-10 12:00:00');
        (new RecordPaymentService)->recordByStaff($b->fresh(), null, '10.00', 'bank-1b');

        $this->assertSame([], array_values(array_filter($this->staffMails(), fn (string $mail) => str_contains($mail, 'Paid invoice applied nothing'))));
    }
}
