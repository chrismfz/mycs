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
 * same invoice in the same run, and an end-of-period request on a parent ends its addon before it. The addon fixtures
 * were moved to their parent's cycle after #765 (an addon on another cycle no longer exists). Since 2026-11-10 the fix is
 * a hold on the way back from the suspension (ServiceObserver, Sweep7SuspendHoldTest), not a rule on the invoice's date.
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

    /**
     * S6-CRON-BILLING-1. Adapted after #765 (an addon always has its parent's cycle): the yearly parent with a monthly
     * addon is now a monthly parent with a monthly addon, the finding's own second variant: the parent pays late, is
     * kept suspended (still overdue) and billed its next period; the addon, suspended with it, is not billed meanwhile.
     */
    public function test_an_addon_back_with_its_parent_is_not_billed_and_suspended_in_the_same_run(): void
    {
        $parent = $this->service('2026-10-01', 'month');
        $addon = $this->service('2026-10-01', 'month', $parent);

        $this->cronOn('2026-09-25');
        $this->cronOn('2026-10-10');
        $this->assertSame('suspended', $parent->fresh()->status);
        $this->assertSame('suspended', $addon->fresh()->status);
        foreach (['2026-10-25', '2026-11-01', '2026-11-12'] as $day) {
            $this->cronOn($day);
        }

        // Paid late: a period behind still, so both stay suspended and the next run bills what is owed.
        Carbon::setTestNow('2026-11-15 12:00:00');
        $open = Invoice::query()->where('status', Invoice::STATUS_UNPAID)->get();
        $this->assertCount(1, $open, 'parent and addon on one invoice');
        (new RecordPaymentService)->handle($open->first(), null, (string) $open->first()->remaining, transactionId: 's6-pay');
        $this->assertSame('suspended', $parent->fresh()->status, 'paid until 11-01, past Suspend Days: kept suspended');

        $this->cronOn('2026-11-16');
        Carbon::setTestNow('2026-11-17 12:00:00');
        foreach (Invoice::query()->where('status', Invoice::STATUS_UNPAID)->get() as $i => $invoice) {
            (new RecordPaymentService)->handle($invoice, null, (string) $invoice->remaining, transactionId: "s6-pay-{$i}");
        }
        $this->assertSame('active', $parent->fresh()->status);
        $this->assertSame('active', $addon->fresh()->status, 'back with its parent');

        // Whatever the run of the next day bills the addon for the first time, it does not suspend it for it.
        $this->cronOn('2026-11-18');

        $addon->refresh();
        $this->assertSame('active', $parent->fresh()->status);
        $this->assertSame('active', $addon->status,
            'suspended for non-payment by the very run that first issued its invoice, the day after its parent was paid');
        $this->assertNotNull($this->lineOpenedOn($addon, '2026-11-18'), 'its November period is billed on 11-18, for the first time');

        // Back on 11-17 past its due date: held through 11-25 (today + Suspend Days, ServiceObserver). Unpaid, suspended
        // the day after, not later.
        $this->assertSame('2026-11-25', $addon->suspend_hold_until?->toDateString());
        $this->assertNull($parent->fresh()->suspend_hold_until, 'the parent was paid: no hold of its own');
        $this->cronOn('2026-11-25');
        $this->assertSame('active', $addon->fresh()->status, 'the last day of its hold');
        $this->cronOn('2026-11-26');
        $this->assertSame('suspended', $addon->fresh()->status);
    }

    /** Ordinary dunning: a renewal issued 7 days before its due date, suspended Suspend Days past it. */
    public function test_a_renewal_issued_before_its_due_date_is_suspended_suspend_days_past_it_as_before(): void
    {
        $service = $this->service('2026-10-15');

        $this->cronOn('2026-10-08');
        $this->assertNotNull($this->lineOpenedOn($service, '2026-10-08'), 'billed 7 days ahead');
        $this->cronOn('2026-10-23');
        $this->assertSame('active', $service->fresh()->status, '8 days past due: not yet');
        $this->cronOn('2026-10-24');
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame('Overdue on Payment', $service->fresh()->suspend_reason);
    }

    /** A new invoice next to an old overdue one buys no time: the suspension counts from the due date. */
    public function test_a_new_invoice_next_to_an_old_overdue_one_does_not_delay_the_suspension(): void
    {
        $service = $this->service('2026-10-15');
        $this->cronOn('2026-10-08');
        $this->assertNotNull($this->lineOpenedOn($service, '2026-10-08'));

        // Staff bill it something else the day before it is suspended.
        Carbon::setTestNow('2026-10-23 12:00:00');
        $extra = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $extra->items()->create(['description' => 'Extra work', 'price' => 5, 'quantity' => 1, 'reference_id' => $service->id, 'reference_type' => Service::class]);

        $this->cronOn('2026-10-24');
        $this->assertSame('suspended', $service->fresh()->status);
    }

    /**
     * S6-CRON-BILLING-3. Adapted after #765: the monthly addon of a yearly parent is now a yearly addon on its parent's
     * date (the only shape left). An end-of-period request on the parent ends the addon the day the parent ends, not before.
     */
    public function test_an_end_of_period_request_on_a_parent_ends_its_addon_with_it_not_before(): void
    {
        Carbon::setTestNow('2026-11-20 03:00:00');
        $parent = $this->service('2027-09-01', 'year');
        $addon = $this->service('2027-09-01', 'year', $parent);

        ServiceCancellation::query()->create(['service_id' => $parent->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);

        foreach (['2026-11-24', '2026-12-01', '2027-08-31'] as $day) {
            $this->cronOn($day);
        }

        $this->assertSame('active', $parent->fresh()->status, 'the parent runs, paid until 2027-09-01');
        Queue::assertNotPushed(TerminateJob::class, fn (TerminateJob $job) => $job->service->id === $addon->id);
        $this->assertSame('active', $addon->fresh()->status, 'the addon runs as long as the parent the customer asked to close at its end');
        $this->assertSame(0, InvoiceItem::query()->whereIn('reference_id', [$parent->id, $addon->id])->where('reference_type', Service::class)->count(),
            'neither is billed a period it will not have');

        $this->cronOn('2027-09-01');

        $this->assertSame('cancelled', $parent->fresh()->status);
        $this->assertSame('cancelled', $addon->fresh()->status, 'ended the day its parent ended');
    }
}
