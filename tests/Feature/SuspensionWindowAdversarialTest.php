<?php

namespace Tests\Feature;

use App\Models\CronStat;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #411 (suspension window): the hourly `--dunning-only` run and its gate on last_cron_run.
 */
class SuspensionWindowAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 0,
            'settings.cronjob_dunning_days' => [1, 2, 3, 4, 5], 'settings.cronjob_dunning_from' => '10:00',
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes, ?User $user = null): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge([
            'client_id' => ($user ?? User::factory()->create())->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ], $attributes));
    }

    private function lastDailyRun(string $at): void
    {
        Setting::query()->updateOrCreate(['key' => 'last_cron_run', 'settingable_type' => CronStat::class], ['value' => $at, 'type' => 'string']);
    }

    /**
     * HIGH. A failure in any step of the daily run (one domain its registrar will not renew, one service whose renewal
     * throws, a pruning query) throws before last_cron_run is written. The hourly run then never passes its gate: no
     * suspension and no termination that day, and every day while the failure repeats ("Tried again on the next run").
     * Before #411 the same run suspended regardless of an unrelated step's failure; with the window set, it never does.
     */
    public function test_a_failure_in_an_unrelated_step_of_the_daily_run_blocks_every_suspension(): void
    {
        $due = $this->service(['expires_at' => '2026-10-21']);
        Schema::drop('mcp_calls'); // stands for any step failing: here the MCP log pruning, after the suspensions' slot

        Carbon::setTestNow('2026-10-26 03:00:00'); // Monday, the daily run at night (outside the window)
        try {
            Artisan::call('app:cron-job');
        } catch (Throwable) {
            // the daily run reports the failure and rethrows: that is fine
        }

        Carbon::setTestNow('2026-10-26 10:05:00'); // Monday, the window opens
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $due->fresh()->status,
            'an unrelated failure in the daily run must not stop the suspensions (it never did before the window)');
    }

    /**
     * MEDIUM. A daily run before midnight (cronjob_time 22:00, "at night"): the hourly gate wants a daily run *today*,
     * so on Monday 10:05 … 21:05 it does nothing, and the suspensions happen at 22:00 in the daily run itself, the
     * hour the window was meant to avoid (nobody in the office).
     */
    public function test_a_daily_run_before_midnight_still_lets_the_morning_window_suspend(): void
    {
        config(['settings.cronjob_time' => '22:00']);
        $due = $this->service(['expires_at' => '2026-10-21']);
        $this->lastDailyRun('2026-10-25 22:00:00'); // Sunday night's daily run: credit applied, payments finished

        Carbon::setTestNow('2026-10-26 10:05:00'); // Monday 10:05
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $due->fresh()->status, 'suspended in the morning window, not at 22:00');
    }

    /**
     * MEDIUM. Decision 5 / MT-1e ("a customer with credit is not suspended while it sits unused") holds in the daily run
     * because credit_applied runs right before the suspensions. The hourly run suspends up to a day later without that
     * sweep: credit that arrived meanwhile and is not auto-applied on arrival sits unused while its owner is suspended.
     */
    public function test_credit_given_between_the_daily_run_and_the_window_is_used_before_suspending(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $user = User::factory()->create();
        $due = $this->service(['expires_at' => '2026-10-21'], $user);

        Carbon::setTestNow('2026-10-26 03:00:00'); // Monday, the daily run at night: nothing to apply yet
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-10-21']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $due->id, 'description' => 'Renewal', 'quantity' => 1,
            'price' => 10, 'period_start' => '2026-10-21', 'period_end' => '2026-11-21']);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_ACTIVE, $due->fresh()->status);

        Carbon::setTestNow('2026-10-26 09:00:00'); // staff credit the customer in the morning
        (new CreditLedger)->add($user->client()->id, 'USD', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Goodwill');

        Carbon::setTestNow('2026-10-26 10:05:00');
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);

        $this->assertSame(Service::STATUS_ACTIVE, $due->fresh()->status, 'the credit covers the overdue invoice: not suspended');
    }

    /**
     * LOW-MEDIUM. With «suspend only with an overdue invoice» on, a past-due service with no open invoice is reported
     * to staff (a notice, BIL-5) from inside suspendServices(). Outside the window the daily run skips that step, and
     * the hourly run collects the notices and drops them: staff are never told.
     */
    public function test_the_hourly_run_does_not_drop_the_no_open_invoice_notice(): void
    {
        config(['settings.cronjob_suspend_requires_invoice' => true]);
        $service = $this->service(['expires_at' => '2026-10-21']); // past due, nothing the customer can pay
        // A staff draft (as SuspensionRulesTest): the cron does not bill it again, and it is no open invoice. Without it the
        // daily run bills the service and there is nothing to report, window or not (checked when fixing #411).
        $draft = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'draft', 'currency_code' => 'USD', 'due_at' => '2026-10-21']);
        $draft->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);

        Carbon::setTestNow('2026-10-26 03:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0);
        Carbon::setTestNow('2026-10-26 10:05:00');
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);

        $this->assertTrue(EmailLog::query()->where('body', 'like', '%past due with no open invoice%')->exists(),
            'staff must hear of a past-due service with no invoice, as they do when the window is off');
    }

    /** LOW. A last_cron_run that Carbon cannot read crashes every hourly run (uncaught), instead of waiting. */
    public function test_an_unreadable_last_cron_run_does_not_crash_the_hourly_run(): void
    {
        $this->lastDailyRun('not a date');

        Carbon::setTestNow('2026-10-26 10:05:00');
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);
    }
}
