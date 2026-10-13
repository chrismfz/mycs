<?php

namespace Tests\Feature;

use App\Events\Service\Updated;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * As WHMCS (docs/importer.md, «Status υπηρεσιών»): a service the cron gives up on for non-payment, or staff terminate,
 * is «terminated»; one the customer asked to end, or an unpaid order given up on, is «cancelled». Both are ended.
 */
class ServiceStatusTerminatedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00:00');
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_terminate_min_suspended_days' => 1]);
        $this->user = User::factory()->create();
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 10.00]);

        return Service::factory()->create(array_merge([
            'user_id' => $this->user->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-10-15', 'currency_code' => 'USD', 'price' => 10.00,
        ], $attributes));
    }

    public function test_the_cron_terminates_an_overdue_service_it_does_not_cancel_it(): void
    {
        Queue::fake();
        $service = $this->service(['expires_at' => '2026-09-20', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-09-23']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertTrue($service->fresh()->isEnded());
        Queue::assertPushed(TerminateJob::class, fn ($job) => $job->service->id === $service->id);
    }

    public function test_a_cancellation_request_cancels_it(): void
    {
        $service = $this->service();

        ServiceCancellation::create(['service_id' => $service->id, 'type' => 'immediate', 'reason' => 'Not needed']);

        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);
    }

    public function test_staff_terminate_marks_a_running_service_terminated_and_leaves_others_as_they_are(): void
    {
        $active = $this->service();
        $suspended = $this->service(['status' => Service::STATUS_SUSPENDED]);
        $pending = $this->service(['status' => Service::STATUS_PENDING]);
        $cancelled = $this->service(['status' => Service::STATUS_CANCELLED]);

        foreach ([$active, $suspended, $pending, $cancelled] as $service) {
            $service->markTerminated();
        }

        $this->assertSame(Service::STATUS_TERMINATED, $active->fresh()->status);
        $this->assertSame(Service::STATUS_TERMINATED, $suspended->fresh()->status);
        $this->assertSame(Service::STATUS_PENDING, $pending->fresh()->status);
        $this->assertSame(Service::STATUS_CANCELLED, $cancelled->fresh()->status, 'how it ended stays as it was');
    }

    public function test_staff_terminate_cancels_its_open_invoice_as_the_cron_does(): void
    {
        $service = $this->service();
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => '2026-10-15', 'period_end' => '2026-11-15']);

        $this->assertSame([], $service->markTerminated());

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status, 'not left payable for a service that is gone');
    }

    public function test_a_payment_never_revives_a_terminated_service(): void
    {
        $service = $this->service(['status' => Service::STATUS_TERMINATED]);
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => '2026-10-15', 'period_end' => '2026-11-15']);

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10.00, null, 'pi_t1', currency: 'usd');

        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
        $this->assertSame('2026-10-15', $service->fresh()->expires_at->toDateString(), 'no period bought');
    }

    public function test_terminating_is_an_end_for_the_gateways_too(): void
    {
        $service = $this->service();
        $service->update(['status' => Service::STATUS_TERMINATED]);

        $event = new Updated($service, ['status' => Service::STATUS_TERMINATED], ['status' => Service::STATUS_ACTIVE]);

        $this->assertTrue($event->endedBySave(), 'Stripe and PayPal cancel the subscription of an ended service');
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $service = $this->service();

        try {
            $service->update(['status' => 'fraud']);
            $this->fail('fraud is not a service status (yet)');
        } catch (\LogicException) {
        }

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
        $this->assertSame(['pending', 'active', 'suspended', 'terminated', 'cancelled'], array_keys(Service::statusOptions()));
        $this->assertSame('Terminated', Service::statusLabel(Service::STATUS_TERMINATED));
    }
}
