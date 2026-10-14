<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of 9b05d707 + 3567ddda (the Codex commits merged with PR #32: coupon state,
 * dunning marker, applyStaffChanges, refreshOpenPeriods), PR #35. Each test asserts the CORRECT
 * behaviour; the admin-form tests drive the real Filament page through Livewire.
 */
class BillingCursorCodexAdversarialTest extends TestCase
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

    private function invoiceFor(Service $service, ?string $start, ?string $end, string $status = 'unpaid', float $price = 10.00): Invoice
    {
        // Due when its period starts, as the cron's renewal invoices (suspension needs an overdue invoice, BIL-5).
        $invoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => $status === Invoice::STATUS_PAID ? Invoice::STATUS_UNPAID : $status, 'currency_code' => 'USD', 'due_at' => $start ?? now()]);
        $invoice->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'description' => 'Renewal',
            'quantity' => 1,
            'price' => $price,
            'period_start' => $start,
            'period_end' => $end,
        ]);

        // A paid fixture (a period paid in the past): stored paid, its lines added while it was open (MT-1c: a paid
        // invoice keeps its lines, and is never created paid).
        if ($status === Invoice::STATUS_PAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        }

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice): void
    {
        $invoice->transactions()->create(['amount' => $invoice->fresh()->remaining]);
    }

    private function payPending(Service $service): Invoice
    {
        $invoice = $service->invoices()->where('invoices.status', 'unpaid')->orderByDesc('invoices.id')->firstOrFail();
        $this->pay($invoice);

        return $invoice;
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
            'type' => 'percentage', 'applies_to' => 'all', 'code' => 'ADV' . $percent . 'x' . $recurring,
            'value' => $percent, 'recurring' => $recurring,
        ]);
    }

    private function yearlyPlan(Service $service, float $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $service->product_id, 'priceable_type' => Product::class, 'name' => 'Yearly', 'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'currency_code' => 'USD']);

        return $plan;
    }

    /** @return list<string> renewal line prices, oldest period first */
    private function linePrices(Service $service): array
    {
        return $service->invoiceItems()->whereNotNull('period_start')->orderBy('period_start')->pluck('price')
            ->map(fn ($p) => number_format((float) $p, 2, '.', ''))->all();
    }

    private function editForm(Service $service)
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        return $this->livewireAs($admin)->test(EditService::class, ['record' => $service->getRouteKey()]);
    }

    // ---------------------------------------------------------------- admin form (real Livewire page)

    /** Staff opened the form while dunning-suspended; the customer paid; staff saves an unrelated field. */
    public function test_stale_admin_form_does_not_put_a_paid_service_on_manual_hold(): void
    {
        $service = $this->service(['status' => 'suspended', 'dunning_suspended_at' => now(), 'expires_at' => '2026-10-08']);
        $invoice = $this->invoiceFor($service, '2026-10-08', '2026-11-08');
        $form = $this->editForm($service);
        $this->pay($invoice);
        $this->assertSame('active', $service->fresh()->status);
        $form->set('data.quantity', 1)->call('save')->assertHasNoErrors();
        $this->assertSame('active', $service->fresh()->status, 'Saving an unrelated field re-suspended a paid service as a manual hold');
    }

    /** Staff opened the form while active; the cron suspended for non-payment; staff saves an unrelated field. */
    public function test_stale_admin_form_does_not_lift_a_new_dunning_suspension(): void
    {
        $service = $this->service(['expires_at' => '2026-10-08']);
        $form = $this->editForm($service);
        $this->cronOn('2026-10-12 10:00:00');
        $this->assertSame('suspended', $service->fresh()->status);
        $form->set('data.quantity', 1)->call('save')->assertHasNoErrors();
        $this->assertSame('suspended', $service->fresh()->status, 'Saving an unrelated field lifted a non-payment suspension');
        $this->assertNotNull($service->fresh()->dunning_suspended_at);
    }

    /** Staff opened the form; the customer paid the renewal; staff saves an unrelated field. */
    public function test_stale_admin_form_does_not_roll_back_a_paid_expiry(): void
    {
        $service = $this->service();
        $invoice = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $form = $this->editForm($service);
        $this->pay($invoice);
        $this->assertSame('2026-11-15', $this->expires($service));
        $form->set('data.quantity', 1)->call('save')->assertHasNoErrors();
        $this->assertSame('2026-11-15', $this->expires($service), 'Stale form wrote the old Next Due Date back: the paid month is lost');
    }

    public function test_admin_form_unrelated_save_keeps_dunning_marker_and_payment_lifts_it(): void
    {
        $service = $this->service(['status' => 'suspended', 'dunning_suspended_at' => now(), 'expires_at' => '2026-10-08']);
        $this->editForm($service)->set('data.quantity', 2)->call('save')->assertHasNoErrors();
        $this->assertSame(2, (int) $service->fresh()->quantity);
        $this->assertNotNull($service->fresh()->dunning_suspended_at);
        $this->pay($this->invoiceFor($service, '2026-10-08', '2026-11-08'));
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_admin_form_unrelated_save_keeps_coupon_state(): void
    {
        $coupon = $this->coupon(50, 3);
        $service = $this->service(['coupon_id' => $coupon->id, 'price' => 5]);
        $service->forceFill(['coupon_started_at' => '2026-08-15'])->saveQuietly();
        $this->editForm($service)->set('data.quantity', 2)->call('save')->assertHasNoErrors();
        $this->assertSame('2026-08-15', $service->fresh()->coupon_started_at?->toDateString());
        $this->assertNull($service->fresh()->coupon_expired_at);
    }

    // ---------------------------------------------------------------- suspensions and payments

    public function test_duplicate_payment_never_unsuspends_a_dunning_suspension(): void
    {
        $service = $this->service(['expires_at' => '2026-09-08']);
        $this->pay($this->invoiceFor($service, '2026-09-08', '2026-10-08'));
        $this->assertSame('2026-10-08', $this->expires($service));
        $dup = $this->invoiceFor($service, '2026-09-08', '2026-10-08');
        $this->cronOn('2026-10-12 10:00:00');
        $this->assertSame('suspended', $service->fresh()->status);
        $this->pay($dup);
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame('2026-10-08', $this->expires($service));
    }

    public function test_replayed_payment_never_unsuspends_a_dunning_suspension(): void
    {
        $service = $this->service(['expires_at' => '2026-09-08']);
        $paid = $this->invoiceFor($service, '2026-09-08', '2026-10-08');
        $this->pay($paid);
        $this->cronOn('2026-10-12 10:00:00');
        $this->assertSame('suspended', $service->fresh()->status);
        $paid->fresh()->update(['status' => 'unpaid']);
        $paid->fresh()->update(['status' => 'paid']);
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame('2026-10-08', $this->expires($service));
    }

    public function test_ahead_of_gap_payment_waits_then_gap_payment_lifts_dunning(): void
    {
        $service = $this->service(['expires_at' => '2026-10-08']);
        $this->cronOn('2026-10-12 10:00:00');
        $this->assertSame('suspended', $service->fresh()->status);
        $gap = $service->invoices()->where('invoices.status', 'unpaid')->sole();
        $ahead = $this->invoiceFor($service, '2026-11-08', '2026-12-08');
        $this->pay($ahead);
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame('2026-10-08', $this->expires($service));
        $this->pay($gap);
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame('2026-12-08', $this->expires($service));
        $this->assertNull($service->fresh()->dunning_suspended_at);
    }

    public function test_manual_hold_survives_cron_and_payment_and_is_never_terminated(): void
    {
        Queue::fake();
        $service = $this->service(['status' => 'suspended', 'expires_at' => '2026-08-01']);
        $invoice = $this->invoiceFor($service, '2026-08-01', '2026-09-01');
        $this->cronOn('2026-10-12 10:00:00');
        $this->pay($invoice);
        $this->cronOn('2026-10-30 10:00:00');
        $this->cronOn('2026-10-30 18:00:00');
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame('2026-09-01', $this->expires($service));
        Queue::assertNotPushed(TerminateJob::class);
        Queue::assertNotPushed(UnsuspendJob::class);
    }

    public function test_staff_unsuspend_then_cron_resuspends_as_dunning_and_payment_lifts(): void
    {
        $service = $this->service(['status' => 'suspended', 'dunning_suspended_at' => now(), 'expires_at' => '2026-10-08']);
        $invoice = $this->invoiceFor($service, '2026-10-08', '2026-11-08');
        $service->applyStaffChanges(['status' => 'active']);
        $this->assertNull($service->fresh()->dunning_suspended_at);
        $this->cronOn('2026-10-12 11:00:00');
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertNotNull($service->fresh()->dunning_suspended_at);
        $this->pay($invoice);
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_cancelled_service_is_not_revived_by_payment(): void
    {
        $service = $this->service(['status' => 'cancelled']);
        $this->pay($this->invoiceFor($service, '2026-10-15', '2026-11-15'));
        $this->assertSame('cancelled', $service->fresh()->status);
        $this->assertSame('2026-10-15', $this->expires($service));
        $this->cronOn('2026-10-13 10:00:00');
        $this->assertSame('cancelled', $service->fresh()->status);
    }

    public function test_zero_cent_hole_on_manual_hold_keeps_the_hold(): void
    {
        $service = $this->service(['status' => 'suspended', 'price' => 0.10]);
        $this->pay($this->invoiceFor($service, '2026-10-16', '2026-11-16', price: 0.10));
        $this->cronOn('2026-10-12 10:00:00');
        $this->assertSame('2026-11-16', $this->expires($service));
        $this->assertSame('suspended', $service->fresh()->status);
    }

    // ---------------------------------------------------------------- coupons

    public function test_coupon_from_order_discounts_exactly_n_cycles(): void
    {
        $coupon = $this->coupon(50, 2);
        $service = $this->service(['status' => 'pending', 'expires_at' => null, 'price' => 5, 'coupon_id' => $coupon->id]);
        $this->pay($this->invoiceFor($service, null, null, price: 5));
        $this->assertSame('2026-11-12', $this->expires($service));
        $this->cronOn('2026-11-06 10:00:00');
        $this->payPending($service);
        $this->cronOn('2026-12-06 10:00:00');
        $this->payPending($service);
        $this->cronOn('2027-01-06 10:00:00');
        $this->assertSame(['5.00', '5.00', '10.00', '10.00'], $this->linePrices($service));
    }

    /** Staff adds a «50% for 2 cycles» coupon mid-cycle and sets the discounted price. */
    public function test_coupon_added_by_staff_mid_cycle_discounts_exactly_n_renewals(): void
    {
        $coupon = $this->coupon(50, 2);
        $service = $this->service(); // paid until 2026-10-15
        $service->applyStaffChanges(['coupon_id' => $coupon->id, 'price' => 5]);
        $this->cronOn('2026-10-12 10:00:00');
        $this->payPending($service);
        $this->cronOn('2026-11-10 10:00:00');
        $this->payPending($service);
        $this->cronOn('2026-12-10 10:00:00');
        $this->assertSame(['5.00', '5.00', '10.00'], $this->linePrices($service), 'The partly used current cycle was counted as coupon cycle 1');
    }

    /** «50% for 3 cycles»: 2 monthly cycles, then staff switches to yearly: only ONE discounted year. */
    public function test_coupon_cycle_count_survives_a_billing_cycle_change(): void
    {
        $coupon = $this->coupon(50, 3);
        $service = $this->service(['expires_at' => '2026-11-12', 'price' => 5, 'coupon_id' => $coupon->id]);
        $service->forceFill(['coupon_started_at' => '2026-10-12'])->saveQuietly();
        $this->invoiceFor($service, '2026-10-12', '2026-11-12', 'paid', 5);
        $yearly = $this->yearlyPlan($service, 100);
        $this->cronOn('2026-11-06 10:00:00'); // cycle 2, monthly
        $this->payPending($service);
        Carbon::setTestNow('2026-11-20 10:00:00');
        $service->fresh()->applyStaffChanges(['plan_id' => $yearly->id, 'price' => 50]);
        $this->cronOn('2026-12-06 10:00:00'); // cycle 3, first year: discounted
        $this->payPending($service);
        $this->cronOn('2027-12-06 10:00:00'); // cycle 4: full price
        $this->assertSame(['5.00', '5.00', '50.00', '100.00'], $this->linePrices($service), 'Coupon cycles recounted in the new unit: a 4th discounted cycle');
    }

    public function test_upgrade_keeps_coupon_for_the_remaining_cycles_only(): void
    {
        $coupon = $this->coupon(50, 3);
        $service = $this->service(['expires_at' => '2026-11-12', 'price' => 5, 'coupon_id' => $coupon->id]);
        $service->forceFill(['coupon_started_at' => '2026-10-12'])->saveQuietly();
        $this->invoiceFor($service, '2026-10-12', '2026-11-12', 'paid', 5);
        $target = $this->product(20);
        $upgradeInvoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id, 'status' => 'pending', 'invoice_id' => $upgradeInvoice->id]);
        $upgradeInvoice->items()->create(['reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 1]);
        $this->pay($upgradeInvoice->fresh());
        $this->assertEquals('10.00', $service->fresh()->price);
        $this->cronOn('2026-11-06 10:00:00');
        $this->payPending($service);
        $this->cronOn('2026-12-06 10:00:00');
        $this->payPending($service);
        $this->cronOn('2027-01-06 10:00:00');
        $this->assertSame(['5.00', '10.00', '10.00', '20.00'], $this->linePrices($service));
    }

    // ---------------------------------------------------------------- cycle changes / upgrades / holes

    public function test_cycle_change_to_yearly_reprices_open_line_and_payment_buys_a_year(): void
    {
        $service = $this->service();
        $open = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $yearly = $this->yearlyPlan($service, 100);
        $service->applyStaffChanges(['plan_id' => $yearly->id, 'price' => 100]);
        $line = $open->items()->sole();
        $this->assertSame('2027-10-15', $line->period_end->toDateString());
        $this->assertSame('100.00', number_format((float) $line->price, 2, '.', ''));
        $this->pay($open->fresh());
        $this->assertSame('2027-10-15', $this->expires($service));
    }

    public function test_upgrade_reprices_a_prorated_hole_by_its_days(): void
    {
        $service = $this->service();
        $hole = $this->invoiceFor($service, '2026-10-15', '2026-10-20', price: 1.61);
        $future = $this->invoiceFor($service, '2026-10-20', '2026-11-20');
        $this->pay($future);
        $target = $this->product(20);
        $upgradeInvoice = Invoice::factory()->create(['user_id' => $this->user->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'plan_id' => $target->plan->id, 'product_id' => $target->product->id, 'status' => 'pending', 'invoice_id' => $upgradeInvoice->id]);
        $upgradeInvoice->items()->create(['reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class, 'description' => 'Upgrade', 'quantity' => 1, 'price' => 1]);
        $this->pay($upgradeInvoice->fresh());
        $line = $hole->items()->sole();
        $this->assertSame('2026-10-20', $line->period_end->toDateString());
        $this->assertSame('3.23', number_format((float) $line->price, 2, '.', ''));
        $this->assertSame('10.00', number_format((float) $future->items()->sole()->price, 2, '.', ''));
        $this->pay($hole->fresh());
        $this->assertSame('2026-11-20', $this->expires($service));
    }

    public function test_manual_line_and_pending_line_buy_two_whole_periods_in_any_order(): void
    {
        $service = $this->service();
        $pending = $this->invoiceFor($service, '2026-10-15', '2026-11-15');
        $manual = $this->invoiceFor($service, null, null);
        $this->pay($manual);
        $this->assertSame('2026-10-15', $this->expires($service));
        $this->pay($pending);
        $this->assertSame('2026-12-15', $this->expires($service));
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_cycle_change_back_to_monthly_shrinks_open_yearly_line_to_one_month(): void
    {
        $service = $this->service();
        $monthly = $service->plan_id;
        $yearly = $this->yearlyPlan($service, 100);
        $service->applyStaffChanges(['plan_id' => $yearly->id, 'price' => 100]);
        $open = $this->invoiceFor($service, '2026-10-15', '2027-10-15', price: 100);
        $service->fresh()->applyStaffChanges(['plan_id' => $monthly, 'price' => 10]);
        $line = $open->items()->sole();
        $this->assertSame('2026-11-15', $line->period_end->toDateString());
        $this->assertSame('10.00', number_format((float) $line->price, 2, '.', ''));
        $this->pay($open->fresh());
        $this->assertSame('2026-11-15', $this->expires($service));
    }

    public function test_api_style_price_edit_keeps_dunning_marker(): void
    {
        $service = $this->service(['status' => 'suspended', 'dunning_suspended_at' => now(), 'expires_at' => '2026-10-08']);
        $service->applyStaffChanges(['price' => 12]);
        $this->assertNotNull($service->fresh()->dunning_suspended_at);
        $this->assertSame('suspended', $service->fresh()->status);
    }

    // ---------------------------------------------------------------- cron idempotency

    public function test_cron_twice_the_same_day_changes_nothing(): void
    {
        $normal = $this->service();
        $holey = $this->service(['price' => 0.10]);
        $this->pay($this->invoiceFor($holey, '2026-10-16', '2026-11-16', price: 0.10));
        $overdue = $this->service(['expires_at' => '2026-10-01']);
        $this->cronOn('2026-10-12 10:00:00');
        $snapshot = fn () => [
            Invoice::count(),
            InvoiceItem::count(),
            Service::orderBy('id')->get(['id', 'status', 'expires_at', 'price', 'dunning_suspended_at'])->toArray(),
        ];
        $first = $snapshot();
        $this->assertSame('2026-11-16', $this->expires($holey));
        $this->assertSame('suspended', $overdue->fresh()->status);
        $this->cronOn('2026-10-12 18:00:00');
        $this->assertEquals($first, $snapshot());
    }

    // ---------------------------------------------------------------- migrations

    public function test_new_migrations_roll_back_and_forward_on_existing_data(): void
    {
        $coupon = $this->coupon(10, 1);
        $service = $this->service(['status' => 'suspended', 'dunning_suspended_at' => now(), 'coupon_id' => $coupon->id]);
        $service->forceFill(['coupon_started_at' => '2026-09-01', 'coupon_expired_at' => now()])->saveQuietly();
        // Back to before the coupon-state migration, whatever came after it.
        $steps = DB::table('migrations')->where('migration', '>=', '2026_10_12_000300')->count();
        $this->artisan('migrate:rollback', ['--step' => $steps])->assertExitCode(0);
        $this->assertFalse(Schema::hasColumn('services', 'dunning_suspended_at'));
        $this->assertFalse(Schema::hasColumn('services', 'coupon_started_at'));
        $this->assertSame('suspended', DB::table('services')->where('id', $service->id)->value('status'));
        $this->artisan('migrate')->assertExitCode(0);
        $this->assertTrue(Schema::hasColumn('services', 'dunning_suspended_at'));
        $this->assertTrue(Schema::hasColumn('services', 'coupon_expired_at'));
        $this->assertNull(DB::table('services')->where('id', $service->id)->value('dunning_suspended_at'));
    }
}
