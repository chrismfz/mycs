<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * When the cron suspends and terminates (audit sweep BIL-4, BIL-5). By default as WHMCS: Suspend Days and Termination
 * Days after the service's next due date.
 *  BIL-5: a service was suspended on its date alone, also with no invoice the customer could pay (its billing failed,
 *         a draft held it). A setting («suspend only with an overdue invoice», off by default) asks for an overdue
 *         invoice issued Suspend Days ago, and reports the services past due with none.
 *  BIL-4: a service could be suspended and terminated in the same run (a hold that ended late, a date moved back, the
 *         cron down for days): no notice period at all before its data went. Now «minimum days suspended» (1 by
 *         default; 0 = as WHMCS).
 */
class SuspensionRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 0,
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
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

    private function overdueInvoice(Service $service, string $due, string $status = 'unpaid', ?string $issued = null): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => $status, 'currency_code' => 'USD', 'due_at' => $due,
            'created_at' => $issued ?? Carbon::parse($due)->subWeek()]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);

        return $invoice;
    }

    public function test_by_default_the_due_date_alone_suspends_as_whmcs(): void
    {
        $due = $this->service(['expires_at' => '2026-10-17']); // Suspend Days (2) passed
        $notYet = $this->service(['expires_at' => '2026-10-18']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $due->fresh()->status);
        $this->assertNotNull($due->fresh()->dunning_suspended_at);
        $this->assertSame(Service::STATUS_ACTIVE, $notYet->fresh()->status);
    }

    public function test_a_service_with_an_overdue_invoice_is_suspended(): void
    {
        config(['settings.cronjob_suspend_requires_invoice' => true]);
        $service = $this->service(['expires_at' => '2026-10-15']);
        $this->overdueInvoice($service, '2026-10-15');

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
    }

    public function test_a_service_past_due_with_no_open_invoice_is_not_suspended(): void
    {
        config(['settings.cronjob_suspend_requires_invoice' => true]);
        $service = $this->service(['expires_at' => '2026-10-15']);
        $this->overdueInvoice($service, '2026-10-15', status: 'draft'); // staff's draft: the customer cannot pay it

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'never asked to pay, never suspended');
        $notice = EmailLog::query()->where('subject', 'Cron Job: needs staff attention')->latest('id')->first();
        $this->assertNotNull($notice, 'staff are told');
        $this->assertStringContainsString("Service #{$service->id} is past due with no open invoice", $notice->body);
    }

    public function test_never_suspended_and_terminated_in_the_same_run(): void
    {
        // Overdue for a month, but a hold kept it running until yesterday.
        $service = $this->service(['expires_at' => '2026-09-20', 'suspend_hold_until' => '2026-10-19']);
        $this->overdueInvoice($service, '2026-09-20');

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'suspended today, not terminated');

        Carbon::setTestNow('2026-10-21 03:00:00'); // the next day: Termination Days are long past (as WHMCS)
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
    }

    public function test_a_renewal_billed_late_gets_suspend_days_from_its_issue(): void
    {
        config(['settings.cronjob_suspend_requires_invoice' => true]);
        $service = $this->service(['expires_at' => '2026-10-01']);
        $this->overdueInvoice($service, '2026-10-01', issued: '2026-10-19 10:00:00'); // due weeks ago, issued yesterday

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);

        Carbon::setTestNow('2026-10-22 03:00:00'); // issued more than Suspend Days ago
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
    }

    public function test_minimum_days_suspended_zero_terminates_in_the_same_run_as_whmcs(): void
    {
        config(['settings.cronjob_terminate_min_suspended_days' => 0]);
        $service = $this->service(['expires_at' => '2026-09-20', 'suspend_hold_until' => '2026-10-19']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
    }

    public function test_minimum_days_suspended_waits_as_set(): void
    {
        config(['settings.cronjob_terminate_min_suspended_days' => 3]);
        $service = $this->service(['status' => 'suspended', 'expires_at' => '2026-09-01', 'dunning_suspended_at' => '2026-10-18 03:00:00']);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'two days suspended');

        Carbon::setTestNow('2026-10-21 03:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
    }
}
