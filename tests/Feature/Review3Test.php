<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Independent review 3 of fix/adv-area-3. Fake data only. */
class Review3Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 11:00:00'); // a Wednesday, within the window below
        Queue::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_terminate_min_suspended_days' => 1,
            'settings.cronjob_invoice' => 7, 'settings.cronjob_suspend_requires_invoice' => false,
            'settings.cronjob_dunning_days' => [1, 2, 3, 4, 5], 'settings.cronjob_dunning_from' => '10:00']); // a window: not always()
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $expires, array $attributes = [], bool $addon = false): Service
    {
        $product = $this->createProduct();
        if ($addon) {
            $product->product->update(['name' => 'Extra IPv4', 'is_addon' => true]);
        }

        return Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => $addon ? 3.00 : 10.00, 'quantity' => 1,
            ...$attributes,
        ]);
    }

    private function openInvoiceFor(string $due, Service $service): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => $due, 'created_at' => Carbon::parse($due)->subDays(7)]);
        $start = Carbon::parse($service->expires_at);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => $service->price, 'period_start' => $start, 'period_end' => $start->copy()->addMonth()]);

        return $invoice;
    }

    /**
     * R3-1. With a dunning window set, a parent back from a non-payment suspension gets an automatic hold through today
     * (ServiceObserver). Its addon, suspended with it but overdue on its own invoice, now passes stillOverdue() through
     * that parent hold and comes back, to be suspended again by tomorrow's run: the unsuspend/suspend churn (two mails, two
     * server jobs) Δ3a's stillOverdue() exists to prevent. Before the fix it stayed suspended «Overdue on Payment».
     */
    public function test_r3_1_parent_paid_does_not_bring_back_an_addon_overdue_on_its_own(): void
    {
        $parent = $this->service('2026-11-01 00:00:00');
        $addon = $this->service('2026-11-01 00:00:00', ['parent_service_id' => $parent->id], addon: true);
        $this->openInvoiceFor('2026-11-01', $parent);
        $this->openInvoiceFor('2026-11-01', $addon);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_SUSPENDED, $parent->fresh()->status);
        $this->assertSame(Service::SUSPEND_REASON_PARENT, $addon->fresh()->suspend_reason);

        // The parent's own invoice paid: renewed and unsuspended (as RenewServiceService does); no hold set by staff.
        $parent = $parent->fresh();
        $parent->update(['expires_at' => '2026-12-01 00:00:00', 'status' => Service::STATUS_ACTIVE]);
        $this->assertSame('2026-11-11', $parent->fresh()->suspend_hold_until?->toDateString(), 'the observer\'s automatic hold through today');

        $this->assertSame(Service::STATUS_SUSPENDED, $addon->fresh()->status, 'overdue on its own: it stays suspended (Δ3a), not back for a day');
    }
}
