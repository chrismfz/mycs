<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\UnsuspendJob;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\ProcessPaidInvoiceService;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use App\Services\Money\UnbilledServices;
use App\Services\Service\AppliedOpenInvoices;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Tests\TestCase;

/**
 * The fix of sweep 10's S10-STAFF-FREEDOM-1/2 (owner 2026-11-12). A renewal: a period taken back by staff (the payment
 * deleted, the due date moved back into the period) is given again when its invoice is paid. An upgrade: the advice
 * names both steps, the service form warns of the half-step, and its invoice paid after the package was changed back
 * tells staff, once; no package is changed by itself. Fixtures are invented.
 */
class Sweep10UnbilledAdviceTest extends TestCase
{
    use RefreshDatabase;

    private const UPGRADE_WARNING = 'Upgrade invoice #:id is still open and its upgrade was already applied: paying it will not change the package again. Cancel it (or collect it and change the package again).';

    private User $user;

    private Plan $plan;

    private Product $product;

    private Product $big;

    private Plan $bigPlan;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Cache::flush();
        Carbon::setTestNow('2026-11-10 10:00:00');
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_invoice' => 7,
            'settings.credits_auto_use' => false]);
        $this->user = User::factory()->create();
        $created = $this->createProduct();
        Price::query()->where('plan_id', $created->plan->id)->update(['price' => 10.00]);
        $this->plan = $created->plan;
        $this->product = $created->product;
        $this->big = Product::factory()->create(['name' => 'Big']);
        $this->bigPlan = Plan::factory()->create(['priceable_id' => $this->big->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::query()->create(['plan_id' => $this->bigPlan->id, 'currency_code' => 'USD', 'price' => 20.00]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $due): Service
    {
        return Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $this->plan->id, 'product_id' => $this->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => $due, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1]);
    }

    private function renewal(Service $service, string $from): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $from]);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10,
            'period_start' => $from, 'period_end' => Carbon::parse($from)->addMonthNoOverflow()->toDateString()]);

        return $invoice->fresh();
    }

    /**
     * The renewal 2026-11-15..2026-12-15 paid and applied, then its payment deleted: open again, the service runs to 12-15.
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

    /**
     * An upgrade to Big paid and completed, then its payment deleted: its invoice open again, the service on Big.
     *
     * @return array{Service, Invoice, ServiceUpgrade}
     */
    private function upgradedThenPaymentDeleted(): array
    {
        $service = $this->service('2026-12-15');
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'plan_id' => $this->bigPlan->id, 'product_id' => $this->big->id,
            'status' => ServiceUpgrade::STATUS_PENDING, 'invoice_id' => $invoice->id]);
        $invoice->items()->create(['reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 8]);
        $payment = (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '8.00', 'bank-1');
        $this->assertSame([ServiceUpgrade::STATUS_COMPLETED, $this->big->id], [$upgrade->fresh()->status, (int) $service->fresh()->product_id]);
        (new RecordPaymentService)->deleteTransaction($payment);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        return [$service->fresh(), $invoice->fresh(), $upgrade->fresh()];
    }

    /** @return list<string> */
    private function told(Service $service): array
    {
        return array_values(array_column(array_filter((new UnbilledServices)->find(), fn (array $finding) => $finding['service'] === $service->id), 'detail'));
    }

    private function page(Service $service): Testable
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All' . uniqid(), 'permissions' => ['*']])->id]);

        return $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()]);
    }

    /** @return list<string> the subjects of the system mails to staff */
    private function staffMails(): array
    {
        return EmailLog::query()->where('template_key', 'system')->orderBy('id')->pluck('subject')->all();
    }

    private function cronUntil(string $day): void
    {
        while (now()->toDateString() < $day) {
            Carbon::setTestNow(now()->addDay());
            $this->artisan('app:cron-job')->assertExitCode(0);
        }
    }

    private function lines(Service $service): int
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->count();
    }

    // A renewal: the period is given again.

    public function test_a_period_taken_back_is_given_again_when_its_invoice_is_paid(): void
    {
        [$service, $invoice] = $this->renewedThenPaymentDeleted();
        $this->assertSame(["runs to 2026-12-15 on invoice #{$invoice->id}, which is unpaid again since 2026-11-10 (owes 10.00 USD): "
            . 'collect it, or move the due date back to 2026-11-15'], $this->told($service));
        $service->applyStaffChanges(['expires_at' => '2026-11-15']);
        $this->assertSame([], $this->told($service));

        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-2');

        $this->assertSame([Invoice::STATUS_PAID, '2026-12-15', Service::STATUS_ACTIVE, 1],
            [$invoice->fresh()->status, $service->fresh()->expires_at->toDateString(), $service->fresh()->status, $this->lines($service)]);
        $this->assertSame([], (new MoneyCheck)->run());
        $this->assertSame([], $this->staffMails());

        $this->cronUntil('2026-11-26');
        $this->assertSame([Service::STATUS_ACTIVE, '2026-12-15', 1], [$service->fresh()->status, $service->fresh()->expires_at->toDateString(), $this->lines($service)],
            'fourteen days later: not suspended, nothing billed again before its time');
    }

    public function test_a_date_moved_back_part_of_the_way_goes_to_the_periods_end(): void
    {
        [$service, $invoice] = $this->renewedThenPaymentDeleted();
        $service->applyStaffChanges(['expires_at' => '2026-11-25']);

        Carbon::setTestNow('2026-11-10 10:05:00');
        (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-2');

        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());
        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_a_service_suspended_for_non_payment_meanwhile_is_back_with_its_period(): void
    {
        [$service, $invoice] = $this->renewedThenPaymentDeleted();
        $service->applyStaffChanges(['expires_at' => '2026-11-15']);
        $this->cronUntil('2026-11-25');
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'suspended on 2026-11-24, its invoice open');

        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '10.00', 'bank-2');

        $this->assertSame([Service::STATUS_ACTIVE, '2026-12-15'], [$service->fresh()->status, $service->fresh()->expires_at->toDateString()]);
        Queue::assertPushed(UnsuspendJob::class, fn (UnsuspendJob $job) => $job->service->is($service));
        $this->cronUntil('2026-12-05');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
    }

    public function test_the_same_payment_processed_again_gives_nothing_more_and_keeps_staffs_later_correction(): void
    {
        [$service, $invoice] = $this->renewedThenPaymentDeleted();
        $service->applyStaffChanges(['expires_at' => '2026-11-15']);
        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-2');
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());

        // A second webhook, with nothing pending.
        (new ProcessPaidInvoiceService)->handle($invoice->fresh());
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());

        // Staff move the date back again, the invoice paid: the cron's replay of that same payment (its marker still
        // set, as when another line of it keeps failing) never overrides them.
        Carbon::setTestNow('2026-11-13 10:00:00');
        $service->fresh()->applyStaffChanges(['expires_at' => '2026-11-20']);
        Invoice::query()->whereKey($invoice->id)->update(['apply_pending_since' => '2026-11-12 10:00:00']);
        (new ProcessPaidInvoiceService)->handle($invoice->fresh());
        $this->assertSame('2026-11-20', $service->fresh()->expires_at->toDateString());
        $this->assertSame(1, $this->lines($service));
    }

    public function test_a_period_taken_back_after_a_chargeback_is_given_again_too(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $service = $this->service('2026-11-15');
        $invoice = $this->renewal($service, '2026-11-15');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, transactionId: 'pi_renewal', currency: 'usd');
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->assertTrue(ExtensionHelper::chargebackAtGateway('Stripe', 'pi_renewal', 'dp_1', 10.00, 'usd'));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $service->fresh()->applyStaffChanges(['expires_at' => '2026-11-15']);

        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '10.00', 'bank-2');

        $this->assertSame([Invoice::STATUS_PAID, '2026-12-15'], [$invoice->fresh()->status, $service->fresh()->expires_at->toDateString()]);
    }

    public function test_an_addon_billed_with_it_gets_its_period_again_too(): void
    {
        $service = $this->service('2026-11-15');
        $extra = $this->createProduct();
        $extra->product->update(['name' => 'Extra IPv4', 'is_addon' => true]);
        $addon = Service::factory()->create(['client_id' => $service->client_id, 'plan_id' => $extra->plan->id, 'product_id' => $extra->product->id,
            'parent_service_id' => $service->id, 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-11-15', 'currency_code' => 'USD', 'price' => 3.00, 'quantity' => 1]);
        $invoice = $this->renewal($service, '2026-11-15');
        $invoice->items()->create(['reference_id' => $addon->id, 'reference_type' => Service::class, 'description' => 'Renewal', 'quantity' => 1, 'price' => 3,
            'period_start' => '2026-11-15', 'period_end' => '2026-12-15']);
        $payment = (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '13.00', 'bank-1');
        $this->assertSame(['2026-12-15', '2026-12-15'], [$service->fresh()->expires_at->toDateString(), $addon->fresh()->expires_at->toDateString()]);
        (new RecordPaymentService)->deleteTransaction($payment);
        $service->fresh()->applyStaffChanges(['expires_at' => '2026-11-15']);
        $addon->fresh()->applyStaffChanges(['expires_at' => '2026-11-15']);

        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '13.00', 'bank-2');

        $this->assertSame(['2026-12-15', '2026-12-15'], [$service->fresh()->expires_at->toDateString(), $addon->fresh()->expires_at->toDateString()]);
        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_an_ordinary_paid_again_changes_nothing(): void
    {
        // Its service still holds the period.
        [$service, $invoice] = $this->renewedThenPaymentDeleted();
        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-2');
        $this->assertSame([Invoice::STATUS_PAID, '2026-12-15'], [$invoice->fresh()->status, $service->fresh()->expires_at->toDateString()]);

        // Its service runs further (staff gave it more).
        [$later, $first] = $this->renewedThenPaymentDeleted();
        $later->applyStaffChanges(['expires_at' => '2027-01-20']);
        (new RecordPaymentService)->recordByStaff($first, null, '10.00', 'bank-3');
        $this->assertSame('2027-01-20', $later->fresh()->expires_at->toDateString());

        $this->assertSame([], $this->staffMails());
        Queue::assertNotPushed(UnsuspendJob::class);
    }

    public function test_a_line_whose_period_starts_after_the_due_date_waits_as_before(): void
    {
        // B paid ahead of the unpaid A, its payment deleted and recorded again: B still waits behind A.
        $service = $this->service('2026-11-15');
        $a = $this->renewal($service, '2026-11-15');
        $b = $this->renewal($service, '2026-12-15');
        $payment = (new RecordPaymentService)->recordByStaff($b, null, '10.00', 'bank-1');
        Carbon::setTestNow('2026-11-10 11:00:00');
        (new RecordPaymentService)->deleteTransaction($payment);
        Carbon::setTestNow('2026-11-10 12:00:00');
        (new RecordPaymentService)->recordByStaff($b->fresh(), null, '10.00', 'bank-1b');

        $this->assertSame(['2026-11-15', Invoice::STATUS_UNPAID, []], [$service->fresh()->expires_at->toDateString(), $a->fresh()->status, $this->staffMails()]);
    }

    /**
     * REV-805-7 with the advice as it reads now («move the due date back to 2026-11-15», no cancellation): A unpaid again
     * with the later B paid. The date back on A's start, 25 days overdue, the cron suspends it (A is not paid); once A
     * is paid the service is back and runs over both paid periods.
     */
    public function test_a_later_paid_period_chains_when_the_period_before_it_is_paid_again(): void
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
        $this->assertSame(["runs to 2027-01-15 on invoice #{$a->id}, which is unpaid again since 2026-12-10 (owes 10.00 USD): "
            . 'collect it, or move the due date back to 2026-11-15'], $this->told($service));

        $service->fresh()->applyStaffChanges(['expires_at' => '2026-11-15']);
        $this->cronUntil('2026-12-11');
        $this->assertSame([Service::STATUS_SUSPENDED, '2026-11-15'], [$service->fresh()->status, $service->fresh()->expires_at->toDateString()],
            'A is 26 days overdue: suspended until it is paid');

        (new RecordPaymentService)->recordByStaff($a->fresh(), null, '10.00', 'bank-3');

        $this->assertSame([Service::STATUS_ACTIVE, '2027-01-15', 2], [$service->fresh()->status, $service->fresh()->expires_at->toDateString(), $this->lines($service)]);
        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_an_ended_service_is_not_revived_and_staff_are_told_once(): void
    {
        [$service, $invoice] = $this->renewedThenPaymentDeleted();
        $service->applyStaffChanges(['expires_at' => '2026-11-15']);
        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_CANCELLED]);

        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '10.00', 'bank-2');
        Invoice::query()->whereKey($invoice->id)->update(['apply_pending_since' => '2026-11-12 10:00:00']);
        (new ProcessPaidInvoiceService)->handle($invoice->fresh());

        $this->assertSame([Service::STATUS_CANCELLED, '2026-11-15'], [$service->fresh()->status, $service->fresh()->expires_at->toDateString()]);
        $this->assertSame(['Payment for an ended service: needs staff attention'], $this->staffMails());
    }

    // An upgrade: advice, warning, notice. No package is changed by itself.

    public function test_the_advice_for_an_upgrade_not_paid_names_both_steps_and_the_price(): void
    {
        [$service, $invoice] = $this->upgradedThenPaymentDeleted();

        $this->assertSame(["runs on Big since 2026-11-10 by upgrade invoice #{$invoice->id}, which is unpaid again (owes 8.00 USD): "
            . 'collect it, or cancel the invoice and change the package back (product, plan and price)'], $this->told($service));

        // Cancelling alone changes no package: the upgrade stays completed and the service on Big.
        $this->staffCancelInvoice($invoice, 'not collected');
        $this->assertSame($this->big->id, (int) $service->fresh()->product_id);
        $this->assertSame([], $this->told($service), 'a cancelled upgrade invoice is staff\'s own doing');
        $this->assertSame([], AppliedOpenInvoices::warnings($service->fresh()->forceFill(['product_id' => $this->product->id, 'plan_id' => $this->plan->id])));
    }

    public function test_the_advice_stops_once_the_plan_was_put_back(): void
    {
        [$service] = $this->upgradedThenPaymentDeleted();
        $other = Plan::factory()->create(['priceable_id' => $this->big->id, 'priceable_type' => Product::class, 'name' => 'Monthly Lite',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::query()->create(['plan_id' => $other->id, 'currency_code' => 'USD', 'price' => 12.00]);

        $service->applyStaffChanges(['plan_id' => $other->id, 'price' => '12.00']);

        $this->assertSame([], $this->told($service), 'no longer on what the upgrade gave it');
    }

    public function test_the_form_warns_when_the_package_is_changed_back_with_the_upgrade_invoice_left_open(): void
    {
        [$service, $invoice] = $this->upgradedThenPaymentDeleted();

        $this->page($service)->fillForm(['product_id' => $this->product->id, 'plan_id' => $this->plan->id])->call('save')->assertHasNoFormErrors()
            ->assertNotified(__('An applied invoice is still open'));

        // Saved all the same: a warning, nothing refused or changed.
        $this->assertSame($this->product->id, (int) $service->fresh()->product_id);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame([__(self::UPGRADE_WARNING, ['id' => $invoice->id])], AppliedOpenInvoices::warnings($service->fresh()));
    }

    public function test_the_form_does_not_warn_on_an_ordinary_save(): void
    {
        // No upgrade at all.
        $plain = $this->service('2026-12-15');
        $this->page($plain)->fillForm(['product_id' => $this->big->id, 'plan_id' => $this->bigPlan->id])->call('save')->assertHasNoFormErrors()
            ->assertNotNotified(__('An applied invoice is still open'));
        $this->assertSame($this->big->id, (int) $plain->fresh()->product_id);

        // Its upgrade's invoice open, the service still on what it gave: another field saved.
        [$service, $invoice] = $this->upgradedThenPaymentDeleted();
        $this->page($service)->fillForm(['notes' => 'called the customer'])->call('save')->assertHasNoFormErrors()
            ->assertNotNotified(__('An applied invoice is still open'));

        // Its upgrade's invoice paid: changed back for another reason.
        (new RecordPaymentService)->recordByStaff($invoice, null, '8.00', 'bank-4');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->page($service->fresh())->fillForm(['product_id' => $this->product->id, 'plan_id' => $this->plan->id])->call('save')->assertHasNoFormErrors()
            ->assertNotNotified(__('An applied invoice is still open'));
        $this->assertSame([], $this->staffMails(), 'paid again while still on Big: an ordinary replay');
    }

    public function test_staff_are_told_once_when_an_upgrade_invoice_is_paid_after_the_package_was_changed_back(): void
    {
        [$service, $invoice, $upgrade] = $this->upgradedThenPaymentDeleted();
        $service->applyStaffChanges(['product_id' => $this->product->id, 'plan_id' => $this->plan->id, 'price' => '10.00']);

        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '8.00', 'bank-2');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame($this->product->id, (int) $service->fresh()->product_id, 'told, never applied by itself');
        $this->assertSame(['Paid upgrade not applied: needs staff attention'], $this->staffMails());
        $body = (string) EmailLog::query()->where('subject', 'like', '%Paid upgrade not applied%')->value('body');
        $this->assertStringContainsString("Invoice #{$invoice->id} was paid but nothing was applied", $body);
        $this->assertStringContainsString("upgrade #{$upgrade->id} of service #{$service->id} to Big / Monthly", $body);

        // The cron's replay of the same payment while the invoice's processing keeps failing (its marker still set).
        Invoice::query()->whereKey($invoice->id)->update(['apply_pending_since' => '2026-11-12 10:00:00']);
        (new ProcessPaidInvoiceService)->handle($invoice->fresh());
        (new ProcessPaidInvoiceService)->handle($invoice->fresh());
        $this->assertCount(1, $this->staffMails());
    }

    public function test_an_ordinary_upgrade_payment_tells_nobody(): void
    {
        // A normal upgrade, a replay of its processing, and its invoice paid again while still on the new package.
        [$upgraded, $upgradeInvoice] = $this->upgradedThenPaymentDeleted();
        (new RecordPaymentService)->recordByStaff($upgradeInvoice, null, '8.00', 'bank-7');
        (new ProcessPaidInvoiceService)->handle($upgradeInvoice->fresh());
        $this->assertSame($this->big->id, (int) $upgraded->fresh()->product_id);

        $this->assertSame([], $this->staffMails());
        $this->assertSame([], (new MoneyCheck)->run(['service_unbilled']));
    }
}
