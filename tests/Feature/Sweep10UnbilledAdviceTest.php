<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Models\EmailLog;
use App\Models\Invoice;
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
 * The fix of sweep 10's S10-STAFF-FREEDOM-1/2 (owner 2026-11-12): the money check advises both steps («cancel the
 * invoice and …»), the service form warns of the half-step, and a paid invoice that applies nothing tells staff.
 * Nothing is refused, moved or applied by itself. Fixtures are invented.
 */
class Sweep10UnbilledAdviceTest extends TestCase
{
    use RefreshDatabase;

    private const RENEWAL_WARNING = 'Invoice #:id is still open and was already applied: paying it will not renew the service again. Cancel it (or collect it and move the date forward).';

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

    /** @return list<string> the staff notices of a paid invoice that applied nothing */
    private function notices(): array
    {
        return EmailLog::query()->where('template_key', 'system')->orderBy('id')->get()
            ->filter(fn (EmailLog $mail) => str_contains((string) $mail->body, 'was paid but nothing was applied'))
            ->map(fn (EmailLog $mail) => (string) $mail->subject)->values()->all();
    }

    // The advice, and that it is true: what cancelling does and does not do.

    public function test_the_advice_for_a_period_not_paid_is_to_cancel_the_invoice_and_move_the_date_back(): void
    {
        [$service, $invoice] = $this->renewedThenPaymentDeleted();

        $this->assertSame(["runs to 2026-12-15 on invoice #{$invoice->id}, which is unpaid again since 2026-11-10 (owes 10.00 USD): "
            . 'collect it, or cancel the invoice and move the due date back to 2026-11-15'], $this->told($service));

        // Cancelling alone moves no date: the service still runs to the period's end, and the rule says the second step.
        $this->staffCancelInvoice($invoice, 'not collected');
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());
        $this->assertSame(["runs to 2026-12-15 on invoice #{$invoice->id}, which is cancelled: nothing pays for the period from 2026-11-15: "
            . 'bill it again, or move the due date back'], $this->told($service));

        // Both steps done: nothing warns on the form (the invoice is cancelled), and the period-not-paid finding stops.
        $this->page($service->fresh())->fillForm(['expires_at' => '2026-11-15'])->call('save')->assertHasNoFormErrors()
            ->assertNotNotified(__('An applied invoice is still open'));
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString());
        $this->assertSame([], array_filter((new UnbilledServices)->find(), fn (array $finding) => $finding['kind'] === UnbilledServices::PERIOD_NOT_PAID));
    }

    public function test_the_advice_for_an_upgrade_not_paid_is_to_cancel_the_invoice_and_change_the_package_back(): void
    {
        [$service, $invoice] = $this->upgradedThenPaymentDeleted();

        $this->assertSame(["runs on Big since 2026-11-10 by upgrade invoice #{$invoice->id}, which is unpaid again (owes 8.00 USD): "
            . 'collect it, or cancel the invoice and change the package back'], $this->told($service));

        // Cancelling alone changes no package: the upgrade stays completed and the service on Big.
        $this->staffCancelInvoice($invoice, 'not collected');
        $this->assertSame($this->big->id, (int) $service->fresh()->product_id);
        $this->assertSame([], $this->told($service), 'a cancelled upgrade invoice is staff\'s own doing');
        $this->assertSame([], AppliedOpenInvoices::warnings($service->fresh()->forceFill(['product_id' => $this->product->id]), null, (int) $this->big->id));
    }

    // The warning on the service form.

    public function test_the_form_warns_when_the_date_is_moved_back_with_the_applied_invoice_left_open(): void
    {
        [$service, $invoice] = $this->renewedThenPaymentDeleted();

        $this->page($service)->fillForm(['expires_at' => '2026-11-15'])->call('save')->assertHasNoFormErrors()
            ->assertNotified(__('An applied invoice is still open'));

        // Saved all the same: a warning, nothing refused or changed.
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame([__(self::RENEWAL_WARNING, ['id' => $invoice->id])], AppliedOpenInvoices::warnings($service->fresh(), '2026-12-15', (int) $service->product_id));
    }

    public function test_the_form_warns_when_the_package_is_changed_back_with_the_upgrade_invoice_left_open(): void
    {
        [$service, $invoice] = $this->upgradedThenPaymentDeleted();

        $this->page($service)->fillForm(['product_id' => $this->product->id, 'plan_id' => $this->plan->id])->call('save')->assertHasNoFormErrors()
            ->assertNotified(__('An applied invoice is still open'));

        $this->assertSame($this->product->id, (int) $service->fresh()->product_id);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame([__(self::UPGRADE_WARNING, ['id' => $invoice->id])],
            AppliedOpenInvoices::warnings($service->fresh(), $service->expires_at, (int) $this->big->id));
    }

    public function test_the_form_does_not_warn_on_an_ordinary_change_of_date(): void
    {
        // No such invoice: an open renewal not applied yet (the ordinary customer who has not paid).
        $plain = $this->service('2026-11-15');
        $this->renewal($plain, '2026-11-15');
        $this->page($plain)->fillForm(['expires_at' => '2026-11-01'])->call('save')->assertHasNoFormErrors()
            ->assertNotNotified(__('An applied invoice is still open'));
        $this->assertSame('2026-11-01', $plain->fresh()->expires_at->toDateString());

        // Its invoice paid: the period is paid for, staff move the date for their own reasons.
        $paid = $this->service('2026-11-15');
        (new RecordPaymentService)->recordByStaff($this->renewal($paid, '2026-11-15'), null, '10.00', 'bank-3');
        $this->assertSame('2026-12-15', $paid->fresh()->expires_at->toDateString());
        $this->page($paid->fresh())->fillForm(['expires_at' => '2026-11-15'])->call('save')->assertHasNoFormErrors()
            ->assertNotNotified(__('An applied invoice is still open'));

        // The date moved forward, or back but not before the applied period's end, with the applied invoice open.
        [$service, $invoice] = $this->renewedThenPaymentDeleted();
        $this->page($service)->fillForm(['expires_at' => '2026-12-20'])->call('save')->assertHasNoFormErrors()
            ->assertNotNotified(__('An applied invoice is still open'));
        $this->page($service->fresh())->fillForm(['expires_at' => '2026-12-15'])->call('save')->assertHasNoFormErrors()
            ->assertNotNotified(__('An applied invoice is still open'));
        // Another field saved with the date as it is.
        $this->page($service->fresh())->fillForm(['notes' => 'called the customer'])->call('save')->assertHasNoFormErrors()
            ->assertNotNotified(__('An applied invoice is still open'));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    public function test_the_form_does_not_warn_on_an_ordinary_change_of_package(): void
    {
        // No upgrade at all.
        $plain = $this->service('2026-12-15');
        $this->page($plain)->fillForm(['product_id' => $this->big->id, 'plan_id' => $this->bigPlan->id])->call('save')->assertHasNoFormErrors()
            ->assertNotNotified(__('An applied invoice is still open'));
        $this->assertSame($this->big->id, (int) $plain->fresh()->product_id);

        // Its upgrade's invoice paid: changed back for another reason.
        [$service, $invoice] = $this->upgradedThenPaymentDeleted();
        (new RecordPaymentService)->recordByStaff($invoice, null, '8.00', 'bank-4');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->page($service->fresh())->fillForm(['product_id' => $this->product->id, 'plan_id' => $this->plan->id])->call('save')->assertHasNoFormErrors()
            ->assertNotNotified(__('An applied invoice is still open'));
        $this->assertSame([], $this->notices(), 'paid again while still on Big: an ordinary replay');
    }

    // The payment that applies nothing.

    public function test_staff_are_told_when_an_invoice_is_paid_for_a_period_taken_back(): void
    {
        [$service, $invoice] = $this->renewedThenPaymentDeleted();
        $service->applyStaffChanges(['expires_at' => '2026-11-15']);

        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-2');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString(), 'told, never moved by itself');
        $this->assertSame(['Paid invoice applied nothing: needs staff attention'], $this->notices());
        $body = (string) EmailLog::query()->where('subject', 'like', '%Paid invoice applied nothing%')->value('body');
        $this->assertStringContainsString("Invoice #{$invoice->id} was paid but nothing was applied", $body);
        $this->assertStringContainsString("service #{$service->id} the period 2026-11-15 to 2026-12-15", $body);

        // The same payment processed again (the cron's replay): not told twice.
        (new ProcessPaidInvoiceService)->handle($invoice->fresh());
        $this->assertCount(1, $this->notices());
    }

    public function test_staff_are_told_when_an_upgrade_invoice_is_paid_after_the_package_was_changed_back(): void
    {
        [$service, $invoice, $upgrade] = $this->upgradedThenPaymentDeleted();
        $service->applyStaffChanges(['product_id' => $this->product->id, 'plan_id' => $this->plan->id, 'price' => '10.00']);

        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '8.00', 'bank-2');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame($this->product->id, (int) $service->fresh()->product_id, 'told, never applied by itself');
        $this->assertSame(['Paid upgrade not applied: needs staff attention'], $this->notices());
        $body = (string) EmailLog::query()->where('subject', 'like', '%Paid upgrade not applied%')->value('body');
        $this->assertStringContainsString("Invoice #{$invoice->id} was paid but nothing was applied", $body);
        $this->assertStringContainsString("upgrade #{$upgrade->id} of service #{$service->id} to Big", $body);

        (new ProcessPaidInvoiceService)->handle($invoice->fresh());
        $this->assertCount(1, $this->notices());
    }

    public function test_an_ordinary_payment_tells_nobody(): void
    {
        // An applied line paid again while its service still holds the period: the normal «paid again».
        [$service, $invoice] = $this->renewedThenPaymentDeleted();
        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-2');
        $this->assertSame([Invoice::STATUS_PAID, '2026-12-15'], [$invoice->fresh()->status, $service->fresh()->expires_at->toDateString()]);

        // The same, its service renewed further since.
        [$later, $first] = $this->renewedThenPaymentDeleted();
        $later->applyStaffChanges(['expires_at' => '2027-01-15']);
        (new RecordPaymentService)->recordByStaff($first, null, '10.00', 'bank-5');

        // A normal renewal, and a replay of its processing.
        $plain = $this->service('2026-11-15');
        $renewal = $this->renewal($plain, '2026-11-15');
        (new RecordPaymentService)->recordByStaff($renewal, null, '10.00', 'bank-6');
        (new ProcessPaidInvoiceService)->handle($renewal->fresh());
        $this->assertSame('2026-12-15', $plain->fresh()->expires_at->toDateString());

        // A normal upgrade, a replay of its processing, and its invoice paid again while still on the new package.
        [$upgraded, $upgradeInvoice] = $this->upgradedThenPaymentDeleted();
        (new RecordPaymentService)->recordByStaff($upgradeInvoice, null, '8.00', 'bank-7');
        (new ProcessPaidInvoiceService)->handle($upgradeInvoice->fresh());
        $this->assertSame($this->big->id, (int) $upgraded->fresh()->product_id);

        $this->assertSame([], $this->notices());
        $this->assertSame([], collect((new MoneyCheck)->run(['service_unbilled']))->all());
    }
}
