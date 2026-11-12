<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use App\Services\Money\UnbilledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 10, adversarial verifier, «staff-freedom». The money check's own advice for a period or a package whose payment
 * was taken back («collect it, or move the due date back», «collect it, or change the package back»), followed by
 * staff, and then the invoice, still open, is paid. Each test fails for the reason its candidate reports.
 */
class Sweep10StaffFreedomTest extends TestCase
{
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
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.cronjob_payment_reminder' => 6, 'settings.cronjob_overdue_reminders' => '1,3',
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_invoice' => 7,
            'settings.credits_auto_use' => false]);
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

    /** @return list<string> */
    private function kinds(Service $service): array
    {
        return array_values(array_column(array_filter((new UnbilledServices)->find(), fn (array $finding) => $finding['service'] === $service->id), 'kind'));
    }

    /** The renewal paid and applied, its payment deleted, and the due date moved back as the money check advises. */
    private function paidDeletedAndMovedBack(): array
    {
        $service = $this->service('2026-11-15');
        $invoice = $this->renewal($service, '2026-11-15');
        $payment = (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-1');
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());
        (new RecordPaymentService)->deleteTransaction($payment);
        $this->assertSame([UnbilledServices::PERIOD_NOT_PAID], $this->kinds($service));
        $this->assertStringContainsString('collect it, or move the due date back to 2026-11-15',
            (string) collect((new MoneyCheck)->run(['service_unbilled']))->pluck('detail')->implode(' '));

        $service->fresh()->applyStaffChanges(['expires_at' => '2026-11-15']);
        $this->assertSame([], $this->kinds($service), 'moved back: «an ordinary service with an open invoice due on its date»');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        return [$service->fresh(), $invoice->fresh()];
    }

    /** S10-STAFF-FREEDOM-1: the open invoice is then paid, and its period is not given again. */
    public function test_a_period_taken_back_by_staff_is_given_again_when_its_open_invoice_is_paid(): void
    {
        [$service, $invoice] = $this->paidDeletedAndMovedBack();
        $mailsBefore = EmailLog::query()->where('template_key', 'system')->count();

        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-2');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $given = $service->fresh()->expires_at->toDateString() === '2026-12-15';
        $told = EmailLog::query()->where('template_key', 'system')->count() > $mailsBefore
            || collect((new MoneyCheck)->run())->contains(fn (array $finding) => $finding['subject'] === "service #{$service->id}");
        $this->assertTrue($given || $told, '10.00 paid for 2026-11-15..2026-12-15: the service still ends 2026-11-15 ('
            . $service->fresh()->expires_at->toDateString() . '), and neither a notice nor the money check tells staff');
    }

    /** S10-STAFF-FREEDOM-1, what follows: the cron suspends the paid service, and nothing bills or tells of it after. */
    public function test_the_cron_does_not_suspend_a_service_whose_period_was_paid_again(): void
    {
        [$service, $invoice] = $this->paidDeletedAndMovedBack();
        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice, null, '10.00', 'bank-2');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        while (now()->toDateString() < '2026-11-30') {
            Carbon::setTestNow(now()->addDay());
            $this->artisan('app:cron-job')->assertExitCode(0);
        }

        $service = $service->fresh();
        $lines = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)->count();
        $this->assertSame([Service::STATUS_ACTIVE, 1], [$service->status, $lines],
            "paid until 2026-12-15 on invoice #{$invoice->id}, yet on 2026-11-30 it is {$service->status} (due {$service->expires_at->toDateString()}), with {$lines} line(s) billing it");
    }

    /** S10-STAFF-FREEDOM-2: the upgrade's payment deleted, the package changed back as advised, the open invoice then paid. */
    public function test_an_upgrade_invoice_paid_after_the_package_was_changed_back_is_applied_or_told(): void
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
        $this->assertSame([ServiceUpgrade::STATUS_COMPLETED, $big->id], [$upgrade->fresh()->status, (int) $service->fresh()->product_id]);

        (new RecordPaymentService)->deleteTransaction($payment);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertStringContainsString('collect it, or cancel the invoice and change the package back (product, plan and price)',
            (string) collect((new MoneyCheck)->run(['service_unbilled']))->pluck('detail')->implode(' '));

        // Staff change the package back, on the service's form.
        $service->fresh()->applyStaffChanges(['product_id' => $this->product->id, 'plan_id' => $this->plan->id, 'price' => '10.00']);
        $this->assertSame([], $this->kinds($service), 'the finding stops');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'the upgrade invoice stays open and payable');
        $mailsBefore = EmailLog::query()->where('template_key', 'system')->count();

        Carbon::setTestNow('2026-11-12 10:00:00');
        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '8.00', 'bank-2');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $applied = (int) $service->fresh()->product_id === (int) $big->id;
        $told = EmailLog::query()->where('template_key', 'system')->count() > $mailsBefore
            || collect((new MoneyCheck)->run())->isNotEmpty();
        $this->assertTrue($applied || $told, '8.00 paid for the upgrade: the service runs on the old package, and neither a notice nor the money check tells staff');
    }
}
