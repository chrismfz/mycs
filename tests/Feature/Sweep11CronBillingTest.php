<?php

namespace Tests\Feature;

use App\Jobs\Server\TerminateJob;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 11, cron-billing (adversarial verifier). S11-CRON-BILLING-1: a cancellation request already carried out stays
 * on its service. When staff bring the service back (status active, a new due date: the service page, the API), the
 * cron still reads the old request: it never bills the service, and on the new due date ends it again and queues a
 * second TerminateJob (end of period), or suspends it as unpaid with nothing to pay (immediate). Invented rows, no
 * network.
 */
class Sweep11CronBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow('2026-10-20 03:00:00');
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => false, 'settings.cronjob_order_suspend' => 8,
            'settings.cronjob_order_terminate' => 60]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $expires, string $status = 'active'): Service
    {
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);

        return Service::factory()->create([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ]);
    }

    private function cronOn(string $day): void
    {
        Carbon::setTestNow($day . ' 03:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    public function test_a_service_brought_back_after_its_end_of_period_cancellation_is_billed_and_not_ended_again(): void
    {
        $service = $this->service('2026-10-20');
        ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);

        // The request is carried out on its day, as asked.
        $this->cronOn('2026-10-20');
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status, 'ended as the customer asked');
        Queue::assertPushed(TerminateJob::class, 1);

        // A month later the customer comes back: staff set it running again with a new due date.
        Carbon::setTestNow('2026-11-20 10:00:00');
        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-12-20 00:00:00']);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);

        // Seven days before the new due date: a renewal invoice, as for any running service.
        $this->cronOn('2026-12-14');
        $billed = Invoice::query()->whereHas('items', fn ($line) => $line->where('reference_type', Service::class)->where('reference_id', $service->id))->count();

        // On the new due date the old request (carried out two months ago) must not end it again.
        $this->cronOn('2026-12-20');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'the request was carried out on 10-20: the service staff brought back keeps running');
        Queue::assertPushed(TerminateJob::class, 1); // only the first one: no second delete of the rebuilt account
        $this->assertSame(1, $billed, 'and its renewal was billed 7 days before the new due date');
    }

    public function test_a_service_brought_back_after_an_immediate_cancellation_is_billed_and_not_suspended_with_nothing_to_pay(): void
    {
        // Ended at once when the customer asked (the listener's work, done): cancelled, its request still on it.
        $service = $this->service('2026-10-25', Service::STATUS_CANCELLED);
        ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'immediate', 'reason' => 'Leaving']);

        Carbon::setTestNow('2026-11-20 10:00:00');
        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-12-20 00:00:00']);

        $this->cronOn('2026-12-14');
        $this->cronOn('2026-12-20');
        $this->cronOn('2026-12-29');

        $billed = Invoice::query()->whereHas('items', fn ($line) => $line->where('reference_type', Service::class)->where('reference_id', $service->id))->count();
        $this->assertSame([1, Service::STATUS_ACTIVE], [$billed, $billed === 0 ? $service->fresh()->status : Service::STATUS_ACTIVE],
            'a running service is billed before it is due; never suspended as unpaid with no invoice to pay');
    }
}
