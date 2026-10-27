<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of PR #486 (Δ3a: a hole before a period given up on is not billed, staff are told).
 * Written without the author's context. A failing test here is a finding.
 */
class Adv486HoleBeforeGivenUpTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'settings.cronjob_invoice' => 7,
            'settings.credits_enabled' => false,
            'settings.cronjob_order_suspend' => 2,
            'settings.cronjob_order_terminate' => 14,
            'settings.cronjob_order_cancel' => 7,
            'settings.system_email_address' => 'staff@example.test',
            'settings.mail_disable' => false,
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $expires, array $attributes = [], string $unit = 'month'): Service
    {
        $product = $this->createProduct();
        Plan::query()->whereKey($product->plan->id)->update(['billing_unit' => $unit, 'billing_period' => 1]);

        return Service::factory()->create(array_merge([
            'client_id' => $this->user->client()->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => $expires,
            'currency_code' => 'USD',
            'price' => 10.00,
        ], $attributes));
    }

    /** @return Collection<int, InvoiceItem> */
    private function lines(Service $service, string $status)
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereHas('invoice', fn ($q) => $q->where('status', $status))->orderBy('period_start')->get();
    }

    private function pay(Invoice $invoice): void
    {
        (new RecordPaymentService)->handle($invoice->fresh(), null, $invoice->fresh()->remaining, transactionId: 'adv-' . $invoice->id);
    }

    /** The PR's case: [11-24, 12-24) billed, given up on, date moved back to 11-15. */
    private function holeBeforeGivenUp(array $attributes = []): array
    {
        Carbon::setTestNow('2027-11-20 10:00:00');
        $service = $this->service('2027-11-24', $attributes);
        Artisan::call('app:cron-job'); // bills [11-24, 12-24)
        $givenUp = $this->lines($service, 'unpaid')->sole()->invoice;
        $givenUp->update(['status' => 'cancelled']);
        $service->fresh()->update(['expires_at' => '2027-11-15']);

        return [$service, $givenUp];
    }

    private function cronDaily(string $from, string $to): void
    {
        for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
            Carbon::setTestNow($day->copy()->setTime(10, 0));
            Artisan::call('app:cron-job');
        }
    }

    // ------------------------------------------------------------------ side effects before the early return

    /**
     * ADV-486-1: a change of cycle «from the next renewal» is consumed (plan switched, price recalculated, next_plan_id
     * cleared) by a run that renews and bills nothing. The cycle change belongs to the renewal that did not happen.
     */
    public function test_adv_a_next_plan_is_not_consumed_when_nothing_is_billed(): void
    {
        [$service] = $this->holeBeforeGivenUp();
        $yearly = Plan::factory()->create(['priceable_id' => $service->product_id, 'priceable_type' => $service->plan->priceable_type,
            'name' => 'Yearly', 'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $yearly->id, 'price' => 100.00, 'currency_code' => 'USD']);
        $monthlyId = (int) $service->plan_id;
        $service->fresh()->forceFill(['next_plan_id' => $yearly->id])->save();
        $this->assertSame($yearly->id, (int) $service->fresh()->next_plan_id);

        Carbon::setTestNow('2027-11-22 10:00:00');
        Artisan::call('app:cron-job');

        $service->refresh();
        $this->assertCount(0, $this->lines($service, 'unpaid'), 'nothing billed (the PR)');
        $this->assertSame([$monthlyId, $yearly->id, '10.00'], [(int) $service->plan_id, (int) $service->next_plan_id, number_format((float) $service->price, 2, '.', '')],
            'the cycle change set for the next renewal was applied by a run that renewed nothing');
    }

    // ------------------------------------------------------------------ stuck services

    /** Default rules: nothing billed, but the service is still suspended, then terminated (not stuck). */
    public function test_adv_the_service_is_suspended_then_terminated_by_the_usual_rules(): void
    {
        [$service] = $this->holeBeforeGivenUp();

        $this->cronDaily('2027-11-21', '2027-12-05');

        $this->assertCount(0, $this->lines($service, 'unpaid'));
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status);
    }

    /**
     * ADV-486-2: with «suspend only with an overdue invoice» (cronjob_suspend_requires_invoice) the service is never billed,
     * never suspended, never terminated: it runs unpaid for good, and staff get two notices every night. The PR's reason
     * («it was suspended there anyway») holds only with the default rule. Already so for a period given up on at the due
     * date itself (checked by hand on this branch); the PR extends it to the moved-back case.
     */
    public function test_adv_with_suspension_requiring_an_invoice_the_service_is_not_left_running_unpaid(): void
    {
        config(['settings.cronjob_suspend_requires_invoice' => true]);
        [$service] = $this->holeBeforeGivenUp();

        $this->cronDaily('2027-11-21', '2028-01-31');

        $service->refresh();
        $this->assertTrue($this->lines($service, 'unpaid')->isNotEmpty() || $service->status !== Service::STATUS_ACTIVE,
            "active, paid until {$service->expires_at->toDateString()}, nothing to pay, 2½ months on");
    }

    // ------------------------------------------------------------------ what is still billed

    /** A live paid period first, a period given up on after it: the hole before the paid one is still billed. */
    public function test_adv_a_hole_before_a_paid_period_ahead_of_one_given_up_on_is_billed(): void
    {
        Carbon::setTestNow('2027-11-20 10:00:00');
        $service = $this->service('2027-11-24');
        Artisan::call('app:cron-job'); // [11-24, 12-24)
        $this->pay($this->lines($service, 'unpaid')->sole()->invoice);
        Carbon::setTestNow('2027-12-20 10:00:00');
        Artisan::call('app:cron-job'); // [12-24, 01-24)
        $this->lines($service, 'unpaid')->sole()->invoice->update(['status' => 'cancelled']);
        $service->fresh()->update(['expires_at' => '2027-11-15']);

        Carbon::setTestNow('2027-12-21 10:00:00');
        Artisan::call('app:cron-job');

        $line = $this->lines($service, 'unpaid')->sole();
        $this->assertSame(['2027-11-15', '2027-11-24'], [$line->period_start->toDateString(), $line->period_end->toDateString()]);
    }

    /** Yearly, the period given up on more than a cycle away: the whole year before it is billed. */
    public function test_adv_a_period_given_up_on_more_than_a_cycle_away_bills_the_cycle_before_it(): void
    {
        Carbon::setTestNow('2027-11-20 10:00:00');
        $service = $this->service('2027-11-24', [], 'year');
        Artisan::call('app:cron-job'); // [2027-11-24, 2028-11-24)
        $this->lines($service, 'unpaid')->sole()->invoice->update(['status' => 'cancelled']);
        $service->fresh()->update(['expires_at' => '2026-11-15']);

        Carbon::setTestNow('2027-11-21 10:00:00');
        Artisan::call('app:cron-job');

        $line = $this->lines($service, 'unpaid')->sole();
        $this->assertSame(['2026-11-15', '2027-11-15', '10.00'], [$line->period_start->toDateString(), $line->period_end->toDateString(),
            number_format((float) $line->price, 2, '.', '')]);
    }

    /** The 31st: a period given up on exactly where the next cycle ends (Feb 29) bills that cycle whole. */
    public function test_adv_billing_day_31_a_period_given_up_on_at_the_cycle_end_bills_the_cycle(): void
    {
        Carbon::setTestNow('2028-02-25 10:00:00');
        $service = $this->service('2028-02-29', ['billing_day' => 31]);
        Artisan::call('app:cron-job'); // [02-29, 03-31)
        $line = $this->lines($service, 'unpaid')->sole();
        $this->assertSame('2028-03-31', $line->period_end->toDateString());
        $line->invoice->update(['status' => 'cancelled']);
        $service->fresh()->update(['expires_at' => '2028-01-31']);

        Artisan::call('app:cron-job');

        $line = $this->lines($service, 'unpaid')->sole();
        $this->assertSame(['2028-01-31', '2028-02-29', '10.00'], [$line->period_start->toDateString(), $line->period_end->toDateString(),
            number_format((float) $line->price, 2, '.', '')]);
    }

    /** Price 0: no free renewal into the gap. */
    public function test_adv_no_free_renewal_into_the_gap(): void
    {
        [$service] = $this->holeBeforeGivenUp();
        $service->fresh()->update(['price' => 0]);

        Carbon::setTestNow('2027-11-22 10:00:00');
        Artisan::call('app:cron-job');

        $this->assertSame('2027-11-15', $service->fresh()->expires_at->toDateString());
    }

    // ------------------------------------------------------------------ staff told, and the way out

    public function test_adv_staff_are_told_once_a_day(): void
    {
        [$service] = $this->holeBeforeGivenUp();
        $body = "%Service #{$service->id}: a period given up on starts 2027-11-24%";

        Carbon::setTestNow('2027-11-22 10:00:00');
        Artisan::call('app:cron-job');
        Artisan::call('app:cron-job');

        $this->assertSame(1, EmailLog::query()->where('body', 'like', $body)->count());
    }

    /**
     * Staff reopen the invoice given up on (the notice's advice); the customer pays it. The hole before it is billed after
     * (prorated), and once both are paid the service runs to 12-24: no free days, nothing billed twice.
     */
    public function test_adv_reopening_the_invoice_given_up_on_bills_on_correctly(): void
    {
        [$service, $givenUp] = $this->holeBeforeGivenUp();
        Carbon::setTestNow('2027-11-22 10:00:00');
        Artisan::call('app:cron-job'); // nothing, staff told

        $givenUp->fresh()->update(['status' => 'unpaid']);
        $this->pay($givenUp);
        Artisan::call('app:cron-job');

        $hole = $this->lines($service, 'unpaid')->sole();
        $this->assertSame(['2027-11-15', '2027-11-24', '3.00'], [$hole->period_start->toDateString(), $hole->period_end->toDateString(),
            number_format((float) $hole->price, 2, '.', '')]);
        $this->pay($hole->invoice);
        Artisan::call('app:cron-job');

        $this->assertSame('2027-12-24', $service->fresh()->expires_at->toDateString());
        $this->assertSame(0, $this->lines($service, 'unpaid')->count());
    }
}
