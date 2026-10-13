<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Upgrades and the life of their service (audit sweep SVC-6, BIL-11, and R9 of #68).
 *  SVC-6: an upgrade invoice outlived its service (termination, the end of a requested period, staff cancelling it);
 *         paid later, it changed a dead service. Now the service ending cancels its pending upgrades, and an upgrade
 *         of a service that no longer runs is never applied.
 *  BIL-11: the cron cancelled upgrades of one-time plans (no due date: «null < now»), and could cancel one already
 *         paid. One upgrade per transaction.
 *  R9: a new recurring amount re-priced open lines without the payment locks, also on an invoice with a payment on it.
 */
class UpgradeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 0,
            'settings.credits_auto_use' => false]);
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

    /** A pending upgrade to another product, with its own open invoice. */
    private function upgrade(Service $service): ServiceUpgrade
    {
        $target = $this->createProduct(['stock' => 5]);
        $invoice = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-20']);
        $upgrade = ServiceUpgrade::query()->create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id,
            'invoice_id' => $invoice->id]);
        $invoice->items()->create(['reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 5]);

        return $upgrade;
    }

    public function test_a_paid_upgrade_of_a_cancelled_service_is_not_applied(): void
    {
        $service = $this->service();
        $upgrade = $this->upgrade($service);
        DB::table('services')->where('id', $service->id)->update(['status' => 'cancelled']);

        (new RecordPaymentService)->handle($upgrade->invoice, null, 5, transactionId: 'late-pay');

        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
        $this->assertSame($service->product_id, $service->fresh()->product_id, 'a dead service is not changed');
        $this->assertSame(5, (int) $service->product->fresh()->stock, 'no stock given back twice');
        $this->assertNull($upgrade->invoice->fresh()->apply_pending_since, 'nothing left to apply: staff refund or credit it');
    }

    public function test_an_upgrade_is_applied_once_whoever_calls_it_twice(): void
    {
        $service = $this->service();
        $upgrade = $this->upgrade($service);

        (new ServiceUpgradeService)->handle($upgrade);
        (new ServiceUpgradeService)->handle(ServiceUpgrade::find($upgrade->id)->forceFill(['status' => 'pending'])); // a stale copy

        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        $this->assertSame(6, (int) $service->product->fresh()->stock, 'old product: one back');
        $this->assertSame(4, (int) $upgrade->product->fresh()->stock, 'new product: one taken');
    }

    public function test_termination_cancels_a_pending_upgrade_and_its_invoice(): void
    {
        $service = $this->service(['status' => 'suspended', 'expires_at' => '2026-09-01', 'dunning_suspended_at' => '2026-09-05']);
        $upgrade = $this->upgrade($service);

        $this->artisan('app:cron-job');

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $upgrade->invoice->fresh()->status);
    }

    public function test_a_cancellation_at_the_end_of_the_period_cancels_its_pending_upgrade(): void
    {
        $service = $this->service(['expires_at' => '2026-10-20']);
        $upgrade = $this->upgrade($service);
        DB::table('service_cancellations')->insert(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'leaving',
            'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('app:cron-job');

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
    }

    public function test_the_cron_cancels_the_upgrade_of_a_service_staff_cancelled(): void
    {
        $service = $this->service();
        $upgrade = $this->upgrade($service);
        DB::table('services')->where('id', $service->id)->update(['status' => 'cancelled']);

        $this->artisan('app:cron-job');

        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $upgrade->invoice->fresh()->status);
    }

    public function test_the_cron_keeps_the_upgrade_of_a_service_without_a_due_date(): void
    {
        $service = $this->service(['expires_at' => null]);
        Plan::whereKey($service->plan_id)->update(['type' => 'one-time']);
        $upgrade = $this->upgrade($service);

        $this->artisan('app:cron-job');

        $this->assertSame(ServiceUpgrade::STATUS_PENDING, $upgrade->fresh()->status, 'no due date: never «expired»');
        $this->assertSame(Invoice::STATUS_UNPAID, $upgrade->invoice->fresh()->status);
    }

    public function test_the_cron_never_cancels_a_paid_upgrade(): void
    {
        $service = $this->service(['expires_at' => '2026-10-10']);
        $upgrade = $this->upgrade($service);
        DB::table('invoices')->where('id', $upgrade->invoice_id)->update(['status' => 'paid']); // paid, its application pending

        $this->artisan('app:cron-job');

        $this->assertNotSame(ServiceUpgrade::STATUS_CANCELLED, $upgrade->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $upgrade->invoice->fresh()->status);
    }

    public function test_a_new_recurring_amount_keeps_the_line_of_an_invoice_with_a_payment_on_it(): void
    {
        $service = $this->service(['expires_at' => '2026-10-25']);
        $renewal = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-25']);
        $line = $renewal->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => '2026-10-25', 'period_end' => '2026-11-25']);
        (new RecordPaymentService)->handle($renewal, null, 4, transactionId: 'part'); // part of it paid
        $upgrade = $this->upgrade($service);
        $upgrade->product->plans()->first()->prices()->update(['price' => 20]);

        (new ServiceUpgradeService)->handle($upgrade);

        $this->assertSame('10.00', number_format((float) $line->fresh()->price, 2, '.', ''), 'a payment on it: the line keeps its price');
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
    }

    public function test_a_new_recurring_amount_reprices_an_open_line_with_nothing_paid(): void
    {
        $service = $this->service(['expires_at' => '2026-10-25']);
        $renewal = Invoice::factory()->create(['user_id' => $service->user_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-25']);
        $line = $renewal->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => '2026-10-25', 'period_end' => '2026-11-25']);
        $upgrade = $this->upgrade($service);
        $upgrade->product->plans()->first()->prices()->update(['price' => 20]);

        (new ServiceUpgradeService)->handle($upgrade);

        $this->assertNotSame('10.00', number_format((float) $line->fresh()->price, 2, '.', ''), 'repriced to the new amount');
    }
}
