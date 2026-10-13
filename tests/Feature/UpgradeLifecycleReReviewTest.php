<?php

namespace Tests\Feature;

use App\Events\ServiceCancellation\Created;
use App\Listeners\CancellationCreatedListener;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Re-review of PR #71 after «fix(upgrades): reviews of #71».
 */
class UpgradeLifecycleReReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        Mail::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 0,
            'settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct(['stock' => 5]);

        return Service::factory()->create(array_merge([
            'user_id' => User::factory()->create()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-11-15',
        ], $attributes));
    }

    /**
     * R1. CancellationCreatedListener reads the service's open invoices ($open) before it takes the service's lock. The
     * cron's billRenewal holds that lock while it creates a renewal invoice; the listener then waits, ends the service,
     * and cancels only the stale $open: the new renewal invoice stays open, payable, for a cancelled service (nothing
     * else ever cancels it; paid, RenewServiceService only logs «not revived»). Read $open after phase 1.
     */
    public function test_r1_an_invoice_created_while_the_listener_waits_for_the_lock_is_cancelled_too(): void
    {
        $service = $this->service();
        $cancellation = ServiceCancellation::withoutEvents(fn () => ServiceCancellation::query()->create([
            'service_id' => $service->id, 'type' => 'immediate', 'reason' => 'leaving']));
        $late = null;
        Service::updating(function (Service $changed) use ($service, &$late) {
            if ($late === null && $changed->id === $service->id && $changed->status === Service::STATUS_CANCELLED) {
                // billRenewal committed just before the listener got the lock (after it read $open)
                $late = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-11-15']);
                $late->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
                    'quantity' => 1, 'price' => 10, 'period_start' => '2026-11-15', 'period_end' => '2026-12-15']);
            }
        });

        (new CancellationCreatedListener)->handle(new Created($cancellation));

        $this->assertNotNull($late);
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $late->fresh()->status, 'a renewal of a cancelled service left payable');
    }

    /** Holds: a payment between the listener's two phases on the upgrade invoice is refused and staff are mailed. */
    public function test_holds_a_payment_between_the_listeners_phases_is_refused_and_mailed(): void
    {
        $service = $this->service();
        $target = $this->createProduct(['stock' => 5]);
        $invoice = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-20']);
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id,
            'invoice_id' => $invoice->id]);
        $invoice->items()->create(['reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 5]);
        $cancellation = ServiceCancellation::withoutEvents(fn () => ServiceCancellation::query()->create([
            'service_id' => $service->id, 'type' => 'immediate', 'reason' => 'leaving']));
        $paid = false;
        DB::listen(function ($query) use (&$paid, $invoice) {
            // first statement of phase 2 (the open invoices' cancellation): the customer pays the upgrade right then
            if (!$paid && preg_match('/update [`"]services[`"]/', $query->sql)) {
                $paid = true;
                DB::afterCommit(fn () => (new RecordPaymentService)->handle($invoice->id, null, 5, transactionId: 'between'));
            }
        });

        (new CancellationCreatedListener)->handle(new Created($cancellation));

        $this->assertTrue($paid);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
        $this->assertSame($service->product_id, $service->fresh()->product_id);
        $this->assertSame(6, (int) $service->product->fresh()->stock, 'once, by the cancellation');
        $this->assertSame(5, (int) $target->product->fresh()->stock);
        $this->assertTrue(EmailLog::query()->where('subject', 'Paid upgrade not applied: needs staff attention')->exists());
    }

    /** Probe: an upgrade invoice kept open (gateway money) of a cancelled service: a notice on every run. */
    public function test_probe_kept_open_upgrade_notices_repeat_every_run(): void
    {
        $service = $this->service(['status' => 'cancelled']);
        $target = $this->createProduct(['stock' => 5]);
        $invoice = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-20']);
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id,
            'invoice_id' => $invoice->id]);
        $invoice->items()->create(['reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 5]);
        (new RecordPaymentService)->handle($invoice, null, 2, transactionId: 'part-gw');

        $this->artisan('app:cron-job');
        Carbon::setTestNow('2026-10-21 03:00:00');
        $this->artisan('app:cron-job');

        $this->assertSame(ServiceUpgrade::STATUS_PENDING, $upgrade->fresh()->status);
        $this->assertSame(2, EmailLog::query()->where('subject', 'like', '%attention%')->where('body', 'like', "%#{$invoice->id}%")->count());
    }
}
