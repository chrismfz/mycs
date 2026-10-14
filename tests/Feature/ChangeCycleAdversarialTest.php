<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\Coupon;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of SVC-14b (ChangeCycle). Today is 2026-07-02 10:00 unless a test moves it.
 * The `test_f*` tests failed on the branch as reviewed (b775fdd); all are fixed in the same PR. Where a refusal is a right
 * outcome (F2, F4, F5, F7), the test takes it, as F3 did; the «held» tests follow the change from the next renewal
 * being set to come (`next_plan_id`, code review B3) and NOW being refused for a period paid from today (B1).
 */
class ChangeCycleAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Plan $yearly;

    private Plan $monthly;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-02 10:00:00');
        Queue::fake();
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']]);

        $this->product = Product::factory()->create(['name' => 'Hosting']);
        $this->yearly = $this->plan('Yearly', 'year', 120.00);
        $this->monthly = $this->plan('Monthly', 'month', 12.00);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $values): void
    {
        foreach ($values as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    private function plan(string $name, string $unit, float $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $this->product->id, 'priceable_type' => Product::class, 'name' => $name,
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    /** A yearly service, by default paid for 2026-01-01 → 2027-01-01. */
    private function service(array $attributes = [], bool $line = true): Service
    {
        $service = Service::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id,
            'product_id' => $this->product->id, 'plan_id' => $this->yearly->id, 'price' => 120.00, 'quantity' => 1,
            'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2027-01-01']);
        if ($line) {
            $this->periodLine($service, '2026-01-01', '2027-01-01', 120.00, Invoice::STATUS_PAID);
        }

        return $service->fresh();
    }

    private function periodLine(Service $service, string $start, string $end, float $price, string $status, ?Invoice $invoice = null): InvoiceItem
    {
        $invoice ??= Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $line = $invoice->items()->create(['description' => 'Renewal', 'price' => $price, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        if ($status !== Invoice::STATUS_UNPAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status]);
        }

        return $line;
    }

    private function credit(): string
    {
        return number_format((float) Credit::query()->sum('amount'), 2, '.', '');
    }

    /** @return Collection<int, InvoiceItem> live lines of the service on open invoices */
    private function openLines(Service $service)
    {
        return $service->fresh()->livePeriodLines()->filter(fn ($line) => in_array($line->invoice?->status, Invoice::OPEN_STATUSES, true))->values();
    }

    // ================================================================== FINDINGS (expected to fail on the branch)

    /**
     * F1. The credit is the CURRENT recurring amount × unused days, not what was paid for the period. A first-cycle
     * coupon (50%): the year was paid 60.00, the recurring amount is 120.00. Unused 183/365 of 60.00 = 30.08.
     */
    public function test_f1_now_credits_what_was_paid_not_the_current_recurring_amount(): void
    {
        $coupon = Coupon::create(['type' => 'percentage', 'applies_to' => 'all', 'code' => 'HALF1', 'value' => 50, 'recurring' => 1]);
        $service = $this->service(['coupon_id' => $coupon->id, 'coupon_started_at' => '2026-01-01'], line: false);
        $this->periodLine($service, '2026-01-01', '2027-01-01', 60.00, Invoice::STATUS_PAID);

        ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame('30.08', $this->credit(), 'only the unused part of the 60.00 paid comes back');
    }

    /** F1b. Same root: staff raised the recurring amount after the year was paid at 120.00. */
    public function test_f1b_now_credit_ignores_a_price_raised_after_the_payment(): void
    {
        $service = $this->service(['price' => 240.00]);

        ChangeCycle::apply($service, $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame('60.16', $this->credit(), '120.00 was paid; 183/365 of it is unused');
    }

    /**
     * F2. A renewal paid that starts TODAY (paid the day before it was due) is not refused as «paid ahead» (start > today)
     * and not cut (start < today): the customer gets the whole year as credit AND its paid line stays live, so the
     * next payment chains over it and the year is kept.
     */
    public function test_f2_now_on_a_renewal_that_starts_today_does_not_keep_the_old_year(): void
    {
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $service = $this->service(['expires_at' => '2027-07-02'], line: false);
        $this->periodLine($service, '2025-07-02', '2026-07-02', 120.00, Invoice::STATUS_PAID);
        $this->periodLine($service, '2026-07-02', '2027-07-02', 120.00, Invoice::STATUS_PAID);

        try {
            $invoice = ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
        } catch (DisplayException) {
            $this->assertSame('0.00', $this->credit());
            $this->assertSame('2027-07-02', $service->fresh()->expires_at->toDateString());

            return; // refused (paid ahead): acceptable
        }

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('108.00', $this->credit(), '120.00 credited, 12.00 used for the month');
        $this->assertSame('2026-08-02', $service->fresh()->expires_at->toDateString(),
            'credited the whole year, it must not keep the year as well');
    }

    /**
     * F3. CancelOpenInvoices may leave an invoice open (here: it also bills another service and has a line of no one);
     * it reports that in its return value, which ChangeCycle ignores. The old-cycle renewal stays live and payable next
     * to the new period's invoice.
     */
    public function test_f3_now_does_not_leave_the_old_cycle_renewal_open(): void
    {
        $service = $this->service();
        $other = $this->service(['client_id' => $service->client_id]);
        $shared = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $this->periodLine($service, '2027-01-01', '2028-01-01', 120.00, Invoice::STATUS_UNPAID, $shared);
        $this->periodLine($other, '2027-01-01', '2028-01-01', 120.00, Invoice::STATUS_UNPAID, $shared);
        $shared->items()->create(['description' => 'Setup fee', 'price' => 5.00, 'quantity' => 1]);

        try {
            ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
        } catch (DisplayException) {
            $this->assertSame($this->yearly->id, (int) $service->fresh()->plan_id);

            return; // refused: acceptable
        }

        $old = $this->openLines($service)->filter(fn ($line) => $line->period_start->toDateString() === '2027-01-01');
        $this->assertCount(0, $old, 'the yearly renewal is still open next to the new monthly period');
    }

    /**
     * F4. Overdue service: expired 2026-06-01, renewal unpaid. NOW cancels the renewal and starts the new period today:
     * June 1 → July 2 (a month of service) is never billed, and the expiry moves a month forward without a payment
     * (suspension/termination clocks restart).
     */
    public function test_f4_now_on_an_overdue_service_does_not_give_the_overdue_days_away(): void
    {
        $service = $this->service(['expires_at' => '2026-06-01'], line: false);
        $this->periodLine($service, '2025-06-01', '2026-06-01', 120.00, Invoice::STATUS_PAID);
        $this->periodLine($service, '2026-06-01', '2027-06-01', 120.00, Invoice::STATUS_UNPAID);

        try {
            ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
        } catch (DisplayException) {
            $this->assertSame('0.00', $this->credit());
            $this->assertSame($this->yearly->id, (int) $service->fresh()->plan_id);

            return; // refused: acceptable
        }

        $covered = $service->fresh()->livePeriodLines()->contains(fn ($line) => $line->period_start->toDateString() <= '2026-06-01'
            && $line->period_end->toDateString() > '2026-06-01');
        $this->assertTrue($covered, 'the days since 2026-06-01 are billed by some live line');
        $this->assertTrue($service->fresh()->expires_at->toDateString() <= '2026-06-01', 'no payment, no later expiry');
    }

    /**
     * F5. A period that was never paid (staff moved the expiry over an unpaid renewal: grace) is credited as if paid,
     * and its invoice is cancelled: the used half-year is free AND the unused half-year becomes money.
     */
    public function test_f5_now_gives_no_credit_for_a_period_that_was_never_paid(): void
    {
        $service = $this->service(line: false);
        $this->periodLine($service, '2025-01-01', '2026-01-01', 120.00, Invoice::STATUS_PAID);
        $this->periodLine($service, '2026-01-01', '2027-01-01', 120.00, Invoice::STATUS_UNPAID);

        try {
            ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
        } catch (DisplayException) {
            $this->assertSame('0.00', $this->credit());
            $this->assertSame($this->yearly->id, (int) $service->fresh()->plan_id);

            return; // refused: acceptable
        }

        $this->assertSame('0.00', $this->credit(), 'nothing was paid for 2026');
    }

    /** F5b. Days staff gave beyond the paid period (expiry moved from 2027-01-01 to 2027-03-01) become money. */
    public function test_f5b_now_does_not_credit_days_staff_gave_beyond_the_paid_period(): void
    {
        $service = $this->service(['expires_at' => '2027-03-01']);

        ChangeCycle::apply($service, $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame('60.16', $this->credit(), 'the paid year has 183 of 365 days left: 60.16, not 242/365 of a year');
    }

    /** F6. «with the tax they were paid with»: the year was invoiced with no tax; tax is on now (24%). */
    public function test_f6_now_credits_with_the_tax_the_period_was_paid_with(): void
    {
        $service = $this->service();
        DB::table('invoice_items')->where('reference_id', $service->id)->update(['tax_rate' => 0]);
        $this->settings(['tax_enabled' => [true, 'boolean'], 'tax_type' => ['exclusive', 'string']]);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
        $service->client->primaryContact->update(['country' => 'GR']);

        ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame('60.16', $this->credit(), '120.00 was paid with no tax: 74.60 credits tax never paid');
    }

    /**
     * F7. Customer path: a coupon «50% for 12 cycles» on a monthly plan, 3 months used. Moving to yearly makes each
     * remaining cycle a year: 9 more YEARS at half price (cycleNumber counts yearly periods from here on).
     */
    public function test_f7_customer_cannot_stretch_an_n_cycle_coupon_by_moving_to_a_longer_cycle(): void
    {
        $coupon = Coupon::create(['type' => 'percentage', 'applies_to' => 'all', 'code' => 'HALF12', 'value' => 50, 'recurring' => 12]);
        $service = $this->service(['plan_id' => $this->monthly->id, 'price' => 6.00, 'expires_at' => '2026-08-02',
            'coupon_id' => $coupon->id, 'coupon_started_at' => '2026-05-02'], line: false);
        $this->periodLine($service, '2026-05-02', '2026-06-02', 6.00, Invoice::STATUS_PAID);
        $this->periodLine($service, '2026-06-02', '2026-07-02', 6.00, Invoice::STATUS_PAID);
        $this->periodLine($service, '2026-07-02', '2026-08-02', 6.00, Invoice::STATUS_PAID);

        $this->livewireAs($service->client->users()->first())->test(ServiceShow::class, ['service' => $service])
            ->set('cyclePlan', $this->yearly->id)->call('changeCycle');

        $service->refresh();
        if ((int) $service->plan_id === $this->monthly->id && $service->next_plan_id === null) {
            $this->assertSame('6.00', number_format((float) $service->price, 2, '.', ''));

            return; // refused while the coupon runs: acceptable
        }
        $this->assertSame($this->yearly->id, (int) $service->plan_id);
        // 9 discounted months left of 12: at most 9 × 6.00 off a 120.00 year.
        $this->assertGreaterThanOrEqual(66.00, (float) $service->price, 'a year at 60.00 gives 6 months more discount than the coupon');
        $this->assertGreaterThan(12, $service->cycleNumber(Carbon::parse('2030-08-02')),
            'by 2030 the 12 cycles (months) are long used up');
    }

    /** F8. Customer path: a round trip (yearly → monthly → yearly) drops a price staff set (150.00) to the catalog's. */
    public function test_f8_customer_round_trip_does_not_reset_a_staff_price(): void
    {
        $service = $this->service(['price' => 150.00]);
        $user = $service->client->users()->first();

        $this->livewireAs($user)->test(ServiceShow::class, ['service' => $service])->set('cyclePlan', $this->monthly->id)->call('changeCycle');
        $this->livewireAs($user)->test(ServiceShow::class, ['service' => $service->fresh()])->set('cyclePlan', $this->yearly->id)->call('changeCycle');

        $this->assertSame($this->yearly->id, (int) $service->fresh()->plan_id);
        $this->assertSame('150.00', number_format((float) $service->fresh()->price, 2, '.', ''),
            'the customer, on their own, set their yearly price back from 150.00 to 120.00');
    }

    // ================================================================== what held (expected to pass)

    public function test_held_paying_the_now_invoice_moves_the_expiry_one_new_cycle(): void
    {
        $service = $this->service();
        $invoice = ChangeCycle::apply($service, $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        (new RecordPaymentService)->handle($invoice->fresh(), null, 12.00, transactionId: 'adv-pay');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('2026-08-02', $service->fresh()->expires_at->toDateString());
    }

    public function test_held_cron_after_an_unpaid_now_bills_nothing_twice_and_renews_at_the_new_price(): void
    {
        $service = $this->service();
        $invoice = ChangeCycle::apply($service, $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->artisan('app:cron-job');
        $this->assertCount(1, $this->openLines($service), 'the NOW invoice is the only open one');

        (new RecordPaymentService)->handle($invoice->fresh(), null, 12.00, transactionId: 'adv-pay2');
        Carbon::setTestNow('2026-07-28 10:00:00');
        $this->artisan('app:cron-job');

        $next = InvoiceItem::query()->where('reference_id', $service->id)->whereDate('period_start', '2026-08-02')->sole();
        $this->assertSame('2026-09-02', $next->period_end->toDateString());
        $this->assertSame('12.00', number_format((float) $next->price, 2, '.', ''));
    }

    public function test_held_two_now_changes_in_a_row_credit_the_same_days_once(): void
    {
        $service = $this->service();

        ChangeCycle::apply($service, $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
        ChangeCycle::apply($service->fresh(), $this->yearly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame('60.16', $this->credit());
        $this->assertCount(1, $this->openLines($service), 'the first NOW invoice is cancelled');
        $line = $this->openLines($service)->sole();
        $this->assertSame(['2026-07-02', '2027-07-02'], [$line->period_start->toDateString(), $line->period_end->toDateString()]);
    }

    public function test_held_two_now_changes_with_credit_paying_credit_only_what_was_paid(): void
    {
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $service = $this->service();

        ChangeCycle::apply($service, $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF); // 60.16 − 12.00

        // The month is paid (by credit) from today: a second change now is refused (B1), from the next renewal it is not.
        $this->assertNotNull(ChangeCycle::refusal($service->fresh(), $this->yearly, ChangeCycle::NOW));
        $this->assertNull(ChangeCycle::refusal($service->fresh(), $this->yearly, ChangeCycle::NEXT_RENEWAL));
        $this->assertSame('48.16', $this->credit());
    }

    public function test_held_now_then_next_renewal_back(): void
    {
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $service = $this->service();
        ChangeCycle::apply($service, $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
        ChangeCycle::apply($service->fresh(), $this->yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);

        $service->refresh();
        $this->assertSame('2026-08-02', $service->expires_at->toDateString());
        // Set to come: the month paid keeps its plan and price until the next period is billed.
        $this->assertSame($this->yearly->id, (int) $service->next_plan_id);
        $this->assertSame('12.00', number_format((float) $service->price, 2, '.', ''));
        $this->assertSame('48.16', $this->credit());
    }

    public function test_held_quantity_scales_credit_and_charge(): void
    {
        $service = $this->service(['quantity' => 3]);
        // The year paid for 3 units (360.00): the credit is what was paid (code review B2).
        DB::table('invoice_items')->where('reference_id', $service->id)->update(['quantity' => 3, 'line_total' => 360.00]);

        $invoice = ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame('180.49', $this->credit(), '360.00 × 183 / 365');
        $this->assertSame(36.0, (float) $invoice->fresh()->total);
    }

    public function test_held_a_period_ending_today_gives_no_credit(): void
    {
        $service = $this->service(['expires_at' => '2026-07-02'], line: false);
        $this->periodLine($service, '2025-07-02', '2026-07-02', 120.00, Invoice::STATUS_PAID);

        $invoice = ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame('0.00', $this->credit());
        $this->assertSame(12.0, (float) $invoice->fresh()->total);
    }

    public function test_held_another_clients_user_cannot_change_the_cycle(): void
    {
        $service = $this->service();
        $stranger = User::factory()->create();
        $stranger->client();

        try {
            $this->livewireAs($stranger)->test(ServiceShow::class, ['service' => $service])
                ->set('cyclePlan', $this->monthly->id)->call('changeCycle');
        } catch (Throwable) {
            // forbidden
        }

        $this->assertSame($this->yearly->id, (int) $service->fresh()->plan_id);
    }

    public function test_held_now_refused_with_a_partial_credit_on_the_open_renewal(): void
    {
        $service = $this->service();
        $line = $this->periodLine($service, '2027-01-01', '2028-01-01', 120.00, Invoice::STATUS_UNPAID);
        $line->invoice->transactions()->create(['amount' => 10, 'status' => 'succeeded', 'transaction_id' => 'credit-part',
            'fee' => 0, 'is_credit_transaction' => true]);

        $this->expectException(DisplayException::class);
        ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
    }

    public function test_held_month_end_billing_day_31(): void
    {
        Carbon::setTestNow('2026-01-31 10:00:00');
        $service = $this->service(['expires_at' => '2026-12-31'], line: false);
        $this->periodLine($service, '2025-12-31', '2026-12-31', 120.00, Invoice::STATUS_PAID);

        $invoice = ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $line = $invoice->items()->sole();
        $this->assertSame(['2026-01-31', '2026-02-28'], [$line->period_start->toDateString(), $line->period_end->toDateString()]);
        $this->assertSame('2026-03-31', $service->fresh()->periodEnd(Carbon::parse('2026-02-28'))->toDateString());
    }
}
