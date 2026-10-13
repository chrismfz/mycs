<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial re-review of 10fe0cb (suspension and termination the WHMCS way, the stricter rules as settings).
 * Default settings (as WHMCS) unless a test says otherwise.
 */
class SuspensionRulesReReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        Mail::fake();
        config([
            'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 7,
            'settings.cronjob_suspend_requires_invoice' => false, 'settings.cronjob_terminate_min_suspended_days' => 1,
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ], $attributes));
    }

    private function invoice(Service $service, ?string $due, string $status = 'unpaid'): Invoice
    {
        $issued = $due !== null && Carbon::parse($due)->isPast() ? Carbon::parse($due) : now();
        $invoice = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => $status, 'currency_code' => 'USD', 'due_at' => $due, 'created_at' => $issued]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Line', 'quantity' => 1, 'price' => 10]);

        return $invoice;
    }

    private function processing(Invoice $invoice): void
    {
        DB::table('invoice_transactions')->insert(['invoice_id' => $invoice->id, 'amount' => 10, 'status' => InvoiceTransactionStatus::Processing->value,
            'transaction_id' => 'sepa_' . $invoice->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function cron(?string $now = null): void
    {
        if ($now !== null) {
            Carbon::setTestNow($now);
        }
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function noticesFor(Service $service): int
    {
        return EmailLog::where('subject', 'like', '%needs staff attention%')->where('body', 'like', "%#{$service->id}%")->count();
    }

    /**
     * R1. The suspension step never suspends while a SEPA payment is processing (PAY-9), but the termination step
     * does not look: a service suspended earlier, whose customer has since paid by SEPA, is terminated on Termination
     * Day and the invoice the money is landing on is cancelled (CancelOpenInvoices keeps open only Succeeded money).
     */
    public function test_a_processing_payment_keeps_a_suspended_service_from_termination(): void
    {
        $service = $this->service(['status' => 'suspended', 'expires_at' => '2026-10-01 00:00:00', 'dunning_suspended_at' => '2026-10-03 03:00:00']);
        $invoice = $this->invoice($service, '2026-10-01');
        $this->processing($invoice);

        $this->cron();

        $this->assertSame('suspended', $service->fresh()->status, 'the money is on its way: not terminated');
        $this->assertSame('unpaid', $invoice->fresh()->status, 'the invoice the SEPA payment lands on stays open');
    }

    /**
     * R2. Suspend Days is a plain number setting with no minimum (no min_value, cast with (int)): a negative value
     * (typo, «-2») moves the cutoff into the future and suspends services that are not yet due.
     */
    public function test_a_negative_suspend_days_never_suspends_a_service_before_its_due_date(): void
    {
        config(['settings.cronjob_order_suspend' => '-3']);
        $service = $this->service(['expires_at' => '2026-10-22 00:00:00']); // due in two days

        $this->cron();

        $this->assertSame('active', $service->fresh()->status, 'not due yet');
    }

    /**
     * R3. suspendable() now requires whereHas('plan'). plan_id is nullable (nullOnDelete when staff delete a plan; the
     * WHMCS importer leaves it null when no plan matches the cycle). Such a service was suspended by date before this
     * commit; now it is never suspended and never reported, in either mode: a silent free service.
     */
    public function test_a_service_without_a_plan_is_suspended_or_reported(): void
    {
        $service = $this->service(['expires_at' => '2026-09-01 00:00:00']);
        $this->invoice($service, '2026-09-01');
        DB::table('services')->where('id', $service->id)->update(['plan_id' => null]); // its plan deleted

        $this->cron();

        $this->assertTrue($service->fresh()->status === 'suspended' || $this->noticesFor($service) > 0,
            'seven weeks past due, invoice open: neither suspended nor reported to staff');
    }

    /**
     * R4. A hold (suspend_hold_until, «Do Not Suspend Until») stops the cron's suspension but not its termination: staff
     * who give a suspended customer until the end of the month see the service terminated (and its invoices cancelled)
     * the next night.
     */
    public function test_a_hold_keeps_a_suspended_service_from_automatic_termination(): void
    {
        $service = $this->service(['status' => 'suspended', 'expires_at' => '2026-10-01 00:00:00', 'dunning_suspended_at' => '2026-10-03 03:00:00',
            'suspend_hold_until' => '2026-10-31']);
        $this->invoice($service, '2026-10-01');

        $this->cron();

        $this->assertSame('suspended', $service->fresh()->status, 'on hold until 2026-10-31');
    }
}
