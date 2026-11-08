<?php

namespace Tests\Feature;

use App\Jobs\Server\TerminateJob;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial verifier, sweep 6, cron-billing: a service the daily run bills for the first time is suspended for that
 * same invoice in the same run, and an end-of-period request on a yearly parent ends its monthly addon months early.
 */
class Sweep6CronBillingTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-20 03:00:00');
        config(['settings.cronjob_suspend_requires_invoice' => false]);
        $this->client = User::factory()->create()->client();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $expires, string $unit = 'month', ?Service $parent = null, array $attributes = []): Service
    {
        $product = $this->createProduct();
        Plan::query()->whereKey($product->plan->id)->update(['billing_unit' => $unit, 'billing_period' => 1]);
        if ($parent !== null) {
            $product->product->update(['name' => 'Extra IPv4', 'is_addon' => true]);
        }

        return Service::factory()->create([
            'client_id' => $this->client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'parent_service_id' => $parent?->id, 'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD',
            'price' => $parent ? 3.00 : 10.00, 'quantity' => 1, ...$attributes,
        ]);
    }

    private function cronOn(string $day): void
    {
        Carbon::setTestNow($day . ' 03:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function lineOpenedOn(Service $service, string $day): ?InvoiceItem
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereDate('created_at', $day)->first();
    }

    /** S6-CRON-BILLING-2 */
    public function test_a_service_unsuspended_by_staff_after_a_manual_suspension_is_not_billed_and_suspended_in_the_same_run(): void
    {
        $service = $this->service('2026-10-15');
        Carbon::setTestNow('2026-10-05 10:00:00');
        $service->forceFill(['status' => 'suspended', 'suspend_reason' => 'Abuse', 'dunning_suspended_at' => null])->save();

        foreach (['2026-10-08', '2026-10-15', '2026-10-25'] as $day) {
            $this->cronOn($day);
        }
        $this->assertSame(0, InvoiceItem::query()->where('reference_id', $service->id)->count(), 'not billed while suspended by hand (A2)');

        Carbon::setTestNow('2026-10-30 12:00:00');
        $service->refresh()->forceFill(['status' => 'active'])->save();

        $this->cronOn('2026-10-31');

        $service->refresh();
        $line = $this->lineOpenedOn($service, '2026-10-31');
        $this->assertNotNull($line, 'the 10-15 period is billed on 10-31');
        $this->assertSame(Invoice::STATUS_UNPAID, $line->invoice->status);
        $this->assertSame('active', $service->status,
            'suspended for non-payment by the very run that first issued its invoice: the customer never had a chance to pay it');
    }

    /** S6-CRON-BILLING-1 */
    public function test_a_monthly_addon_back_with_its_yearly_parent_is_not_billed_and_suspended_in_the_same_run(): void
    {
        $parent = $this->service('2026-10-01', 'year');
        $addon = $this->service('2026-10-01', 'month', $parent);

        $this->cronOn('2026-09-25');
        $this->cronOn('2026-10-10');
        $this->assertSame('suspended', $parent->fresh()->status);
        $this->assertSame('suspended', $addon->fresh()->status);
        foreach (['2026-10-25', '2026-11-01', '2026-11-12'] as $day) {
            $this->cronOn($day);
        }

        Carbon::setTestNow('2026-11-15 12:00:00');
        $open = Invoice::query()->where('status', Invoice::STATUS_UNPAID)->get();
        $this->assertCount(1, $open, 'parent and addon on one invoice');
        (new RecordPaymentService)->handle($open->first(), null, (string) $open->first()->remaining, transactionId: 's6-pay');

        $this->assertSame('active', $parent->fresh()->status);
        $addon->refresh();
        $this->assertSame('active', $addon->status, 'back with its parent');
        $this->assertSame('2026-11-01', $addon->expires_at->toDateString());

        $this->cronOn('2026-11-16');

        $addon->refresh();
        $line = $this->lineOpenedOn($addon, '2026-11-16');
        $this->assertNotNull($line, 'its Nov period is billed on 11-16');
        $this->assertSame('active', $addon->status,
            'suspended for non-payment by the very run that first issued its invoice, the day after its parent was paid');
    }

    /** S6-CRON-BILLING-3 */
    public function test_an_end_of_period_request_on_a_yearly_parent_does_not_end_its_monthly_addon_months_early(): void
    {
        Carbon::setTestNow('2026-11-20 03:00:00');
        $parent = $this->service('2027-09-01', 'year');
        $addon = $this->service('2026-12-01', 'month', $parent);

        ServiceCancellation::query()->create(['service_id' => $parent->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);

        foreach (['2026-11-24', '2026-12-01'] as $day) {
            $this->cronOn($day);
        }

        $this->assertSame('active', $parent->fresh()->status, 'the parent runs, paid until 2027-09-01');
        Queue::assertNotPushed(TerminateJob::class, fn (TerminateJob $job) => $job->service->id === $addon->id);
        $this->assertSame('active', $addon->fresh()->status,
            'the addon is ended on 2026-12-01, nine months before the parent the customer asked to close at its end');
    }
}
