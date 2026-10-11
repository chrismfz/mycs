<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Regression tests from the Ekdosi / WHMCS reference review. */
class BillingCursorReferenceFixTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00:00');
        config([
            'settings.cronjob_invoice' => 7,
            'settings.credits_enabled' => false,
            'settings.cronjob_order_suspend' => 2,
            'settings.cronjob_order_terminate' => 14,
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(float $price = 10.00): object
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => $price]);

        return $product;
    }

    private function service(array $attributes = [], ?object $product = null): Service
    {
        $product ??= $this->product();

        return Service::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => '2026-10-15',
            'currency_code' => 'USD',
            'price' => 10.00,
        ], $attributes));
    }

    private function invoiceFor(Service $service, ?string $start, ?string $end, string $status = 'pending', float $price = 10.00): Invoice
    {
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status, 'currency_code' => 'USD']);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => 'Renewal',
            'quantity' => 1,
            'price' => $price,
            'period_start' => $start,
            'period_end' => $end,
        ]);

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice, ?float $amount = null): void
    {
        $invoice->transactions()->create(['amount' => $amount ?? $invoice->fresh()->remaining]);
    }

    private function expires(Service $service): ?string
    {
        return $service->fresh()->expires_at?->toDateString();
    }

    private function cronOn(string $now): void
    {
        Carbon::setTestNow($now);
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function coupon(float $percent, int $recurring): Coupon
    {
        return Coupon::create([
            'type' => 'percentage', 'applies_to' => 'all', 'code' => 'C' . $percent . 'x' . $recurring,
            'value' => $percent, 'recurring' => $recurring,
        ]);
    }

    public function test_reference_manual_suspension_survives_a_new_payment(): void
    {
        Queue::fake();
        $service = $this->service(['status' => 'suspended']); // staff hold, no overdue renewal
        $server = Server::create(['name' => 'Manual hold', 'type' => 'server', 'extension' => 'DoesNotExist', 'enabled' => true]);
        $service->product->update(['server_id' => $server->id]);
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $this->pay($invoice);
        $this->assertSame('2026-11-15', $this->expires($service));
        $this->assertSame('suspended', $service->fresh()->status, 'A payment must not undo a manual abuse/security hold');
        Queue::assertNotPushed(UnsuspendJob::class);
    }

    public function test_reference_reverting_cycle_restores_full_period_price(): void
    {
        $service = $this->service();
        $originalPlan = $service->plan_id;
        $open = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $future = $this->invoiceFor($service, '2026-11-15', '2026-12-15');
        $this->pay($future);
        $yearly = Plan::factory()->create(['priceable_id' => $service->product_id, 'priceable_type' => Product::class, 'name' => 'Yearly', 'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        $service->update(['plan_id' => $yearly->id, 'price' => 100]);
        $this->assertSame('8.49', (string) $open->items()->sole()->price);
        $service->update(['plan_id' => $originalPlan, 'price' => 10]);
        $line = $open->items()->sole();
        $this->assertSame('2026-11-15', $line->period_end->toDateString());
        $this->assertSame('10.00', (string) $line->price, 'Restored full monthly cycle retained the previous clipped yearly amount');
    }

    public function test_draft_line_round_trip_and_paid_future_line_keep_their_values(): void
    {
        $service = $this->service();
        $originalPlan = $service->plan_id;
        $draft = $this->invoiceFor($service, '2026-10-15', '2026-11-15', 'draft');
        $future = $this->invoiceFor($service, '2026-11-15', '2026-12-15');
        $this->pay($future);
        $yearly = Plan::factory()->create(['priceable_id' => $service->product_id, 'priceable_type' => Product::class, 'name' => 'Yearly', 'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        $service->update(['plan_id' => $yearly->id, 'price' => 100]);
        $this->assertSame('8.49', (string) $draft->items()->sole()->price);
        $service->update(['plan_id' => $originalPlan, 'price' => 10]);
        $this->assertSame('10.00', (string) $draft->items()->sole()->price);
        $this->assertSame('10.00', (string) $future->items()->sole()->price);
        $this->assertSame('2026-12-15', $future->items()->sole()->period_end->toDateString());
    }

    public function test_upgrade_reprices_draft_lines_after_the_new_recurring_amount_is_saved(): void
    {
        $service = $this->service();
        $draft = $this->invoiceFor($service, '2026-10-15', '2026-11-15', 'draft');
        $future = $this->invoiceFor($service, '2026-11-01', '2026-12-01');
        $this->pay($future);
        $target = $this->product(20);
        $upgradeInvoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'pending', 'currency_code' => 'USD']);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id, 'status' => 'pending', 'invoice_id' => $upgradeInvoice->id]);
        $upgradeInvoice->items()->create(['reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 1]);
        $this->pay($upgradeInvoice->fresh());
        $this->assertEquals('20.00', $service->fresh()->price);
        $this->assertSame('10.97', (string) $draft->items()->sole()->price);
        $this->assertSame('10.00', (string) $future->items()->sole()->price);
    }

    public function test_staff_price_edit_without_a_cycle_change_keeps_an_invoice_override(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15', price: 8);
        $service->update(['price' => 12]);
        $this->assertSame('8.00', (string) $invoice->items()->sole()->price);
    }

    public function test_billing_suspension_is_marked_and_payment_clears_it(): void
    {
        Queue::fake();
        $service = $this->service(['expires_at' => '2026-10-08']);
        $server = Server::create(['name' => 'Queued fake', 'type' => 'server', 'extension' => 'DoesNotExist', 'enabled' => true]);
        $service->product->update(['server_id' => $server->id]);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertNotNull($service->fresh()->dunning_suspended_at);
        $invoice = $service->invoices()->where('status', 'pending')->sole();
        $this->pay($invoice);
        $this->assertSame('2026-11-08', $this->expires($service));
        $this->assertSame('active', $service->fresh()->status);
        $this->assertNull($service->fresh()->dunning_suspended_at);
        Queue::assertPushed(UnsuspendJob::class);
        // A later staff suspension must not inherit the old billing reason.
        $service->fresh()->update(['status' => 'suspended']);
        $new = $this->invoiceFor($service, '2026-11-08', '2026-12-08');
        $this->pay($new);
        $this->assertSame('2026-12-08', $this->expires($service));
        $this->assertSame('suspended', $service->fresh()->status);
    }

    public function test_unknown_legacy_suspension_is_not_auto_terminated(): void
    {
        Queue::fake();
        $service = $this->service(['status' => 'suspended', 'expires_at' => '2026-08-01']);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame('suspended', $service->fresh()->status);
        Queue::assertNotPushed(TerminateJob::class);
        $this->assertSame(0, $service->invoices()->count());
    }

    public function test_stale_queued_actions_do_not_reach_the_server_adapter(): void
    {
        $service = $this->service();
        $server = Server::create(['name' => 'No external calls', 'type' => 'server', 'extension' => 'DoesNotExist', 'enabled' => true]);
        $service->product->update(['server_id' => $server->id]);
        $unsuspend = new UnsuspendJob(clone $service);
        $service->update(['status' => 'suspended']);
        $unsuspend->handle(); // Would throw for the intentionally nonexistent adapter if reached.
        $this->assertSame('suspended', $service->fresh()->status);
        $suspend = new SuspendJob(clone $service);
        $service->update(['status' => 'active']);
        $suspend->handle();
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_admin_extension_suspend_keeps_a_manual_hold_even_if_the_adapter_fails(): void
    {
        $service = $this->service(['status' => 'suspended', 'dunning_suspended_at' => now()]);
        $server = Server::create(['name' => 'Unavailable', 'type' => 'server', 'extension' => 'DoesNotExist', 'enabled' => true]);
        $service->product->update(['server_id' => $server->id]);
        $page = new EditService;
        $page->record = $service;
        $actions = (new \ReflectionMethod($page, 'getHeaderActions'))->invoke($page);
        $action = collect($actions)->first(fn ($action) => $action->getName() === 'changeStatus');
        config(['app.debug' => true]);
        $failed = false;
        try {
            ($action->getActionFunction())(['action' => 'suspend', 'sendNotification' => false], $service, $action);
        } catch (\Exception $e) {
            $failed = true;
        }
        $this->assertTrue($failed, 'The deliberately unavailable adapter must fail');
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertNull($service->fresh()->dunning_suspended_at);
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $this->pay($invoice);
        $this->assertSame('suspended', $service->fresh()->status);
    }

    public function test_stale_staff_record_clears_a_new_billing_marker_under_the_payment_lock(): void
    {
        $service = $this->service();
        $stale = $service->fresh();
        $service->forceFill(['status' => 'suspended', 'dunning_suspended_at' => now()])->save();
        $stale->applyStaffChanges(['status' => 'suspended']);
        $this->assertNull($service->fresh()->dunning_suspended_at);
        $this->pay($this->invoiceFor($service, '2026-10-15', '2026-11-15'));
        $this->assertSame('suspended', $service->fresh()->status);
    }

    public function test_saving_unchanged_admin_status_keeps_a_billing_suspension(): void
    {
        $service = $this->service(['status' => 'suspended', 'dunning_suspended_at' => now()]);
        $page = new EditService;
        (new \ReflectionMethod($page, 'handleRecordUpdate'))->invoke($page, $service, ['status' => 'suspended', 'price' => 12]);
        $this->assertNotNull($service->fresh()->dunning_suspended_at);
        $this->pay($this->invoiceFor($service, '2026-10-15', '2026-11-15'));
        $this->assertSame('active', $service->fresh()->status);
    }
}
