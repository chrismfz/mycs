<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * When the cron suspends and terminates (audit sweep BIL-4, BIL-5).
 *  BIL-5: a service was suspended on its date alone, also with no invoice the customer could pay (its billing failed,
 *         a draft held it). WHMCS suspends on an overdue invoice.
 *  BIL-4: a service could be suspended and terminated in the same run (a hold that ended late, a date moved back, the
 *         cron down for days): no notice period at all before its data went.
 */
class SuspensionRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ], $attributes));
    }

    private function overdueInvoice(Service $service, string $due, string $status = 'pending'): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => $status, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);

        return $invoice;
    }

    public function test_a_service_with_an_overdue_invoice_is_suspended(): void
    {
        $service = $this->service(['expires_at' => '2026-10-15']);
        $this->overdueInvoice($service, '2026-10-15');

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
    }

    public function test_a_service_past_due_with_no_open_invoice_is_not_suspended(): void
    {
        $service = $this->service(['expires_at' => '2026-10-15']);
        $this->overdueInvoice($service, '2026-10-15', status: 'draft'); // staff's draft: the customer cannot pay it

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'never asked to pay, never suspended');
    }

    public function test_never_suspended_and_terminated_in_the_same_run(): void
    {
        // Overdue for a month, but a hold kept it running until yesterday.
        $service = $this->service(['expires_at' => '2026-09-20', 'suspend_hold_until' => '2026-10-19']);
        $this->overdueInvoice($service, '2026-09-20');

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'suspended today, not terminated');

        Carbon::setTestNow('2026-10-31 03:00:00'); // suspended 11 days
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);

        Carbon::setTestNow('2026-11-01 03:00:00'); // suspended 12 days (terminate 14 - suspend 2)
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
    }
}
