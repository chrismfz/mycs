<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Independent code review of 9b05d707 + 3567ddda (the Codex commits merged with PR #32), PR #35. */
class BillingCursorCodexCodeReviewTest extends TestCase
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

    private function pay(Invoice $invoice): void
    {
        $invoice->transactions()->create(['amount' => $invoice->fresh()->remaining]);
    }

    /** What Filament's EditRecord::save() passes: the form state hydrated when the page was opened. */
    private function formState(Service $service, array $changes = []): array
    {
        return array_merge([
            'product_id' => $service->product_id,
            'plan_id' => $service->plan_id,
            'user_id' => $service->user_id,
            'status' => $service->status,
            'quantity' => $service->quantity,
            'expires_at' => $service->expires_at?->toDateString(),
            'suspend_hold_until' => $service->suspend_hold_until?->toDateString(),
            'coupon_id' => $service->coupon_id,
            'currency_code' => $service->currency_code,
            'price' => (string) $service->price,
            'billing_agreement_id' => $service->billing_agreement_id,
            'subscription_id' => $service->subscription_id,
        ], $changes);
    }

    /**
     * F1: staff opens the Edit page, the customer pays the renewal, staff then saves an unrelated
     * field. applyStaffChanges() fills the stale form state onto the fresh locked row, so the old
     * expires_at is written back (a «move back» = correction, kept) and the paid period is lost.
     */
    public function test_stale_admin_form_save_does_not_revert_a_concurrent_payment(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $stale = $service->fresh();              // page mounted
        $data = $this->formState($stale, ['price' => '12.00']);

        $this->pay($invoice);                    // gateway callback while the page is open
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString());

        $page = new EditService;
        (new \ReflectionMethod($page, 'handleRecordUpdate'))->invoke($page, $stale, $data);

        $this->assertEquals('12.00', $service->fresh()->price);
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString(),
            'Saving an unrelated field from a stale form reverted the paid period');
    }

    /** Control: Filament's default handleRecordUpdate ($record->update($data)) before 3567ddda. */
    public function test_control_default_filament_update_keeps_the_concurrent_payment(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $stale = $service->fresh();
        $data = $this->formState($stale, ['price' => '12.00']);
        $this->pay($invoice);

        $stale->update($data);

        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString());
    }

    /**
     * F2: a coupon «for 1 cycle» added by staff mid-period starts counting on today, not on a period
     * boundary, so the first period billed after it is already cycle 2 and is billed at full price.
     */
    public function test_coupon_for_one_cycle_added_mid_period_discounts_the_next_renewal(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $service = $this->service(['expires_at' => '2026-11-15']); // paid through Nov 15, bills on the 15th
        $coupon = Coupon::create(['type' => 'percentage', 'applies_to' => 'all', 'code' => 'HALF1', 'value' => 50, 'recurring' => 1]);
        $service->update(['coupon_id' => $coupon->id, 'price' => 5.00]);
        $this->assertSame('2026-10-20', $service->fresh()->coupon_started_at->toDateString());

        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);

        $line = $service->invoiceItems()->whereDate('period_start', '2026-11-15')->sole();
        $this->assertSame('5.00', (string) $line->price, 'The one discounted cycle was never billed');
    }

    /** F4: an API update that sends the unchanged status back keeps a billing suspension a billing suspension. */
    public function test_an_api_update_with_the_same_status_keeps_the_dunning_marker(): void
    {
        $service = $this->service(['status' => 'suspended', 'dunning_suspended_at' => now(), 'expires_at' => '2026-10-08']);

        $service->fresh()->applyStaffChanges(['status' => 'suspended', 'price' => 12.00]);

        $this->assertNotNull($service->fresh()->dunning_suspended_at);
        $this->assertEquals('12.00', $service->fresh()->price);
    }

    /** F4: staff really suspending (active -> suspended) is a manual hold. */
    public function test_staff_suspending_an_active_service_is_a_manual_hold(): void
    {
        $service = $this->service();

        $service->fresh()->applyStaffChanges(['status' => 'suspended']);

        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertNull($service->fresh()->dunning_suspended_at);
    }
}
