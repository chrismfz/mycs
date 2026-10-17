<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Enums\InvoiceTransactionStatus;
use App\Events\ServiceCancellation\Created;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\UnsuspendJob;
use App\Listeners\CancellationCreatedListener;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\CreditAutoApply;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyCheck;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Δ3a (decisions of 2026-10-15, docs/sweeps/billing.md):
 *  1. A renewal cancelled (or refunded) is a period not billed: its lines are marked (forgone_at) and the cron does not
 *     bill it again the next day; the service falls to the suspension and termination rules. Reopened, it is billed by
 *     that invoice again. A change of cycle bills the periods on its own invoice: not marked.
 *  2. A refunded period is not billed again either.
 *  4. Ending a service cancels its open invoices (as WHMCS today); with «keep overdue invoices of ended services» on,
 *     those overdue more than the days set stay unpaid, a debt.
 *  Π4.1: a payment does not unsuspend a service still past Suspend Days (it would be suspended again on the next run).
 */
class ForgonePeriodTest extends TestCase
{
    use RefreshDatabase;

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

        return Service::factory()->create($attributes + [
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
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
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    public function test_a_cancelled_renewal_is_not_billed_again(): void
    {
        $service = $this->service();
        $this->cron();
        $invoice = $this->renewals($service)->sole()->invoice;

        $invoice->update(['status' => Invoice::STATUS_CANCELLED]); // staff: «not this period»
        $this->assertNotNull($this->renewals($service)->sole()->forgone_at);

        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();
        Carbon::setTestNow('2026-10-26 10:00:00');
        $this->cron();

        $this->assertCount(1, $this->renewals($service), 'no new invoice for the period given up on');
        $this->assertSame(0, Invoice::query()->whereIn('status', Invoice::OPEN_STATUSES)->count());
    }

    public function test_the_service_falls_to_the_suspension_rule(): void
    {
        $service = $this->service();
        $this->cron();
        $this->renewals($service)->sole()->invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        Carbon::setTestNow('2026-11-03 10:00:00'); // Suspend Days (8) past 2026-10-25
        $this->cron();

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
        $this->assertCount(1, $this->renewals($service), 'suspended: not billed (A2)');
    }

    public function test_reopening_the_invoice_bills_the_period_again(): void
    {
        $service = $this->service();
        $this->cron();
        $invoice = $this->renewals($service)->sole()->invoice;
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        $invoice->fresh()->update(['status' => Invoice::STATUS_UNPAID]); // staff reopen it
        $this->assertNull($this->renewals($service)->sole()->forgone_at);

        (new RecordPaymentService)->handle($invoice->fresh(), null, 10.0, transactionId: 'wire-1');

        $this->assertSame('2026-11-25', $service->fresh()->expires_at->toDateString());
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();
        $this->assertCount(1, $this->renewals($service), 'paid through 2026-11-25: nothing to bill yet');
    }

    public function test_nothing_after_a_period_given_up_on_is_billed(): void
    {
        // Code review S2: within Suspend Days long enough for the next lead time, the cron billed the period after the
        // one given up on, and its payment renewed nothing (the gap stays behind it). Nothing is billed after the gap.
        config(['settings.cronjob_order_suspend' => 40]);
        $service = $this->service();
        $this->cron();
        $this->renewals($service)->sole()->invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        Carbon::setTestNow('2026-11-19 10:00:00'); // 7 days before 2026-11-25
        $this->cron();

        $this->assertCount(1, $this->renewals($service));
    }

    public function test_nothing_is_billed_after_the_gap_with_suspend_only_with_an_overdue_invoice(): void
    {
        // Code review S2: with «suspend only with an overdue invoice» the service is not suspended (no open invoice: staff
        // are told daily), and it is not billed past the gap either.
        config(['settings.cronjob_suspend_requires_invoice' => true]);
        $service = $this->service();
        $this->cron();
        $this->renewals($service)->sole()->invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        Carbon::setTestNow('2026-11-19 10:00:00');
        $this->cron();

        $this->assertCount(1, $this->renewals($service));
        $this->assertTrue(EmailLog::query()->where('body', 'like', "%Service #{$service->id} is past due with no open invoice%")->exists());
    }

    public function test_staff_may_cancel_to_bill_the_period_again(): void
    {
        // Code review S3: the Cancel action's «Bill its period again» re-issues it.
        $service = $this->service();
        $this->cron();
        $invoice = $this->renewals($service)->sole()->invoice;
        $invoice->keep_periods_billable = true; // what the action sets from its toggle
        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'wrong price']);

        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();

        $this->assertCount(2, $this->renewals($service));
        $this->assertSame(Invoice::STATUS_UNPAID, $this->renewals($service)->last()->invoice->status);
    }

    public function test_the_cancel_action_offers_to_bill_the_period_again(): void
    {
        $service = $this->service();
        $this->cron();
        $invoice = $this->renewals($service)->sole()->invoice;
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Inv' . uniqid(), 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(ListInvoices::class)
            ->callTableAction('cancel', $invoice, data: ['cancellation_reason' => 'wrong price', 'periods' => 'keep'])->assertHasNoTableActionErrors();

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertNull($this->renewals($service)->sole()->forgone_at);
    }

    public function test_a_change_of_cycle_bills_the_periods_again(): void
    {
        $product = Product::factory()->create(['name' => 'Hosting']);
        $plans = collect(['month' => 10.00, 'year' => 100.00])->map(function ($price, $unit) use ($product) {
            $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => ucfirst($unit),
                'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
            Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

            return $plan;
        });
        $service = $this->service(['product_id' => $product->id, 'plan_id' => $plans['month']->id]);
        $this->cron();
        $this->assertSame(Invoice::STATUS_UNPAID, $this->renewals($service)->sole()->invoice->status);

        ChangeCycle::apply($service->fresh(), $plans['year'], ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);
        $this->assertNull($this->renewals($service)->sole()->forgone_at, 'cancelled to be billed on the new cycle');

        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();

        $lines = $this->renewals($service);
        $this->assertCount(2, $lines);
        $this->assertSame('2026-10-25', $lines->last()->period_start->toDateString());
        $this->assertSame('2027-10-25', $lines->last()->period_end->toDateString(), 'billed again, on the yearly plan');
    }

    public function test_a_refunded_period_is_not_billed_again(): void
    {
        $service = $this->service();
        $this->cron();
        $invoice = $this->renewals($service)->sole()->invoice;
        (new RecordPaymentService)->handle($invoice, null, 10.0, transactionId: 'wire-r');
        ExtensionHelper::refundLocally($invoice->transactions()->sole(), 10.0);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->assertNotNull($this->renewals($service)->sole()->forgone_at);

        // Staff take the refunded period back: its date goes back, and the cron still does not bill it.
        $service->fresh()->forceFill(['expires_at' => '2026-10-25'])->save();
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();

        $this->assertCount(1, $this->renewals($service));
    }

    public function test_termination_cancels_the_overdue_invoice_by_default(): void
    {
        $service = $this->service(['expires_at' => '2026-08-01', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-08-09']);
        $overdue = $this->openInvoice($service, '2026-08-01');

        $this->cron();

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $overdue->fresh()->status);
    }

    public function test_with_keep_overdue_the_debt_stays_on_termination(): void
    {
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 30]);
        $service = $this->service(['expires_at' => '2026-08-01', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-08-09']);
        $old = $this->openInvoice($service, '2026-08-01');
        $recent = $this->openInvoice($service, '2026-10-01'); // overdue 19 days: not more than 30

        $this->cron();

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $old->fresh()->status, 'a debt: kept');
        $this->assertSame(Invoice::STATUS_CANCELLED, $recent->fresh()->status);
    }

    public function test_a_kept_debt_paid_later_does_not_revive_the_service(): void
    {
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 0]);
        $service = $this->service(['expires_at' => '2026-08-01', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-08-09']);
        $debt = $this->openInvoice($service, '2026-08-01');
        $this->cron();

        (new RecordPaymentService)->handle($debt->fresh(), null, 10.0, transactionId: 'wire-d');

        $this->assertSame(Invoice::STATUS_PAID, $debt->fresh()->status);
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame('2026-08-01', $service->fresh()->expires_at->toDateString());
    }

    public function test_a_cancellation_request_keeps_the_overdue_debt_and_cancels_the_rest(): void
    {
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 0]);
        $service = $this->service(['expires_at' => '2026-10-10']);
        $overdue = $this->openInvoice($service, '2026-10-10');
        $next = $this->openInvoice($service, '2026-11-10');

        $cancellation = ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'moving']);
        (new CancellationCreatedListener)->handle(new Created($cancellation)); // queued: run here

        $this->assertSame(Invoice::STATUS_UNPAID, $overdue->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $next->fresh()->status);
    }

    public function test_a_payment_does_not_unsuspend_a_service_still_overdue(): void
    {
        $product = $this->createProduct();
        $service = $this->service(['product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'expires_at' => '2026-08-20', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-08-29']);
        $first = $this->openInvoice($service, '2026-08-20', '2026-09-20');
        $second = $this->openInvoice($service, '2026-09-20', '2026-10-20');

        (new RecordPaymentService)->handle($first, null, 10.0, transactionId: 'wire-1');

        $this->assertSame('2026-09-20', $service->fresh()->expires_at->toDateString());
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'still past Suspend Days: stays suspended');
        $this->assertNotNull($service->fresh()->dunning_suspended_at);
        Queue::assertNotPushed(UnsuspendJob::class);
        $this->assertTrue(EmailLog::query()->where('subject', 'like', '%still overdue%')->exists(), 'staff are told');

        (new RecordPaymentService)->handle($second, null, 10.0, transactionId: 'wire-2');

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'all paid: active again');
    }

    public function test_a_payment_within_suspend_days_unsuspends(): void
    {
        // Paid to a date the cron would not suspend on (fewer than Suspend Days ago): unsuspended, as before.
        $service = $this->service(['expires_at' => '2026-09-15', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-09-24']);
        $first = $this->openInvoice($service, '2026-09-15', '2026-10-15');
        $this->openInvoice($service, '2026-10-15', '2026-11-15');

        (new RecordPaymentService)->handle($first, null, 10.0, transactionId: 'wire-1');

        $this->assertSame('2026-10-15', $service->fresh()->expires_at->toDateString());
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
    }

    public function test_staff_can_still_unsuspend_by_hand(): void
    {
        $service = $this->service(['expires_at' => '2026-08-20', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-08-29']);

        $service->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
    }

    public function test_a_kept_debt_is_payable_and_not_an_anomaly(): void
    {
        // Code review B1: the client area refused it, credit skipped it, and the money check reported it.
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 0]);
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $service = $this->service(['expires_at' => '2026-08-01', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-08-09']);
        $debt = $this->openInvoice($service, '2026-08-01');
        $this->cron();

        $this->assertNotNull($debt->fresh()->kept_as_debt_at);
        $this->assertFalse($debt->fresh()->billsEndedService(), 'payable from the client area');
        $this->assertSame([], array_filter((new MoneyCheck)->run(), fn ($finding) => str_contains(json_encode($finding), 'open_for_cancelled')));

        (new CreditLedger)->add($service->client_id, 'USD', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'credit');
        (new CreditAutoApply)->sweep(fn () => null);

        $this->assertSame(Invoice::STATUS_PAID, $debt->fresh()->status, 'credit pays a kept debt');
        $this->assertSame([], array_filter((new MoneyCheck)->run(), fn ($finding) => str_contains(json_encode($finding), 'paid_for_cancelled')));
    }

    public function test_an_end_of_period_request_ended_by_the_cron_keeps_the_debt(): void
    {
        // Code review S1: the cron's cancellations_processed cancelled the debt the request had kept.
        config(['settings.cronjob_end_keeps_overdue' => true, 'settings.cronjob_end_keeps_overdue_days' => 0]);
        $service = $this->service(['expires_at' => '2026-10-10']);
        $overdue = $this->openInvoice($service, '2026-10-10');
        ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'moving']);

        $this->cron();

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $overdue->fresh()->status);
    }

    public function test_a_payment_on_its_way_on_the_other_invoice_lets_it_unsuspend(): void
    {
        // Code review S4: the cron does not suspend while a SEPA debit is processing; neither does the payment keep it.
        $service = $this->service(['expires_at' => '2026-08-20', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-08-29']);
        $first = $this->openInvoice($service, '2026-08-20', '2026-09-20');
        $second = $this->openInvoice($service, '2026-09-20', '2026-10-20');
        $second->transactions()->create(['amount' => 10, 'status' => InvoiceTransactionStatus::Processing->value, 'transaction_id' => 'sepa-2']);

        (new RecordPaymentService)->handle($first, null, 10.0, transactionId: 'wire-1');

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
    }

    private function openInvoice(Service $service, string $start, ?string $end = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => $start, 'created_at' => Carbon::parse($start)->subWeek()]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => $start, 'period_end' => $end ?? Carbon::parse($start)->addMonth()->toDateString()]);
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => Carbon::parse($start)->subWeek()]);

        return $invoice->fresh();
    }
}
