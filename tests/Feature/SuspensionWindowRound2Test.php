<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\SuspendJob;
use App\Models\BillingAgreement;
use App\Models\CronStat;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use MyCS\Extensions\Gateways\AcPay\AcPay;
use Tests\TestCase;
use Throwable;

/**
 * Round-2 adversarial review of #411 (84fefeed): the DUNNING_READY marker, the 24 h gate, the hourly credit sweep, the
 * once-a-day mail guard, and the default settings («always»).
 */
class SuspensionWindowRound2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        MailFacade::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 0,
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

    private function openInvoice(Service $service, string $due): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1,
            'price' => 10, 'period_start' => $due, 'period_end' => Carbon::parse($due)->addMonth()->toDateString()]);

        return $invoice->fresh();
    }

    private function marker(string $at): void
    {
        Setting::query()->updateOrCreate(['key' => CronJob::DUNNING_READY, 'settingable_type' => CronStat::class], ['value' => $at, 'type' => 'string']);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Regression with the default settings (every day, from 00:00): must behave exactly as before #411. These pass.
    // ---------------------------------------------------------------------------------------------------------------

    public function test_default_window_the_hourly_run_does_nothing_at_all(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        $user = User::factory()->create();
        $due = $this->service(['expires_at' => '2026-10-21'], $user);
        $this->openInvoice($due, '2026-10-21');
        (new CreditLedger)->add($user->client()->id, 'USD', 1000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Goodwill');
        $this->marker('2026-10-26 09:00:00');

        Carbon::setTestNow('2026-10-26 10:05:00');
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);

        $this->assertSame(Service::STATUS_ACTIVE, $due->fresh()->status, 'default window: only the daily run suspends');
        $this->assertSame(Invoice::STATUS_UNPAID, Invoice::query()->sole()->status, 'default window: the hourly run applies no credit');
        $this->assertSame(0, CronStat::query()->count(), 'default window: the hourly run leaves no rows');
        Queue::assertNotPushed(SuspendJob::class);
    }

    public function test_default_window_the_daily_run_suspends_and_terminates_as_before(): void
    {
        $due = $this->service(['expires_at' => '2026-10-21']);
        $this->openInvoice($due, '2026-10-21');
        $old = $this->service(['expires_at' => '2026-10-01', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-10-04']);

        Carbon::setTestNow('2026-10-25 03:00:00'); // Sunday, at night: the default window is always open
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame([Service::STATUS_SUSPENDED, Service::STATUS_TERMINATED], [$due->fresh()->status, $old->fresh()->status]);
        $this->assertSame(1, (int) CronStat::query()->where('key', 'services_suspended')->sum('value'));
    }

    public function test_default_window_a_failing_step_after_the_suspensions_does_not_undo_them(): void
    {
        $due = $this->service(['expires_at' => '2026-10-21']);
        $this->openInvoice($due, '2026-10-21');
        Schema::drop('mcp_calls');

        Carbon::setTestNow('2026-10-25 03:00:00');
        try {
            Artisan::call('app:cron-job');
        } catch (Throwable) {
        }

        $this->assertSame(Service::STATUS_SUSPENDED, $due->fresh()->status);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Findings. These fail on 84fefeed.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * MEDIUM (money). A daily run scheduled after the window opens (cronjob_time 14:00, window from 10:00; the 22:00 of
     * round 1 is the same case): the hourly run of 10:05 passes the 24 h gate on YESTERDAY's marker and suspends before
     * today's daily run. Today's AutoCharge then skips the service (AutoCharge::due() leaves out a non-active service),
     * so the retry set on the suspension day (autocharge_retry_days 1,3,3+Suspend Days) is never made, though the card
     * would have paid. Before #411 the same daily run charged first and suspended only what stayed unpaid.
     */
    public function test_the_suspension_day_charge_comes_before_the_hourly_suspension(): void
    {
        config(['settings.cronjob_time' => '14:00', 'settings.cronjob_dunning_days' => [1, 2, 3, 4, 5, 6, 7], 'settings.cronjob_dunning_from' => '10:00',
            'settings.cronjob_order_suspend' => 8, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '1,3,9',
            'settings.autocharge_max_failures' => 0, 'settings.credits_auto_use' => false]);
        if (!class_exists(AcPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\AcPay; class AcPay {
                public static array $charges = [];
                public static $answer = null;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = ["invoice" => $invoice->id, "amount" => (float) $amount, "key" => $invoice->chargeKey];
                    return self::$answer === null ? true : (self::$answer)($invoice, $amount);
                } }');
        }
        AcPay::$charges = [];
        $user = User::factory()->create();
        $client = $user->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway']);
        BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $client->refresh();
        $client->forceFill(['auto_charge' => true])->save();
        $this->assertNotNull($client->fresh()->autoChargeAgreement(), 'precondition: auto-charge on');

        $service = $this->service(['expires_at' => '2026-11-01'], $user);
        $invoice = $this->openInvoice($service, '2026-11-01');

        // Due day: declined. Then retries on days 1 and 3 declined too (the daily run at 14:00).
        AcPay::$answer = fn () => throw new DisplayException('Card declined: insufficient_funds');
        foreach (['2026-11-01', '2026-11-02', '2026-11-04', '2026-11-09'] as $day) {
            Carbon::setTestNow("{$day} 14:00:00");
            try {
                Artisan::call('app:cron-job');
            } catch (Throwable) {
            }
        }
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: day 8, not suspended yet');
        $declined = count(AcPay::$charges);

        // Day 9 (Suspend Days 8 passed): the card now pays. The hourly run comes first, at 10:05.
        AcPay::$answer = function ($invoice, $amount) {
            ExtensionHelper::addPayment($invoice->id, 'AcPay', $amount, null, 'ch_' . count(AcPay::$charges), currency: 'USD');

            return true;
        };
        Carbon::setTestNow('2026-11-10 10:05:00');
        $this->artisan('app:cron-job', ['--dunning-only' => true]);
        Carbon::setTestNow('2026-11-10 14:00:00');
        try {
            Artisan::call('app:cron-job');
        } catch (Throwable) {
        }

        $this->assertGreaterThan($declined, count(AcPay::$charges), 'the retry set on the suspension day must be made');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        Queue::assertNotPushed(SuspendJob::class, 'the card paid on the suspension day: never suspended (as before #411)');
    }

    /**
     * LOW. The hourly run is at :05. A window that opens after 23:05 (from 23:30, say «late evening only») never opens
     * for it, and a daily run at night is outside it: nothing is ever suspended or terminated, while the customer's
     * reminder (PaymentReminders::suspendOn → DunningWindow::firstDayFrom) still names that day. Same for any HH:MM with
     * MM > 05 on the last allowed hour: it slips to the next hour, and on hour 23 to nowhere.
     */
    public function test_a_window_opening_after_the_last_hourly_tick_still_suspends(): void
    {
        config(['settings.cronjob_dunning_days' => [1, 2, 3, 4, 5, 6, 7], 'settings.cronjob_dunning_from' => '23:30']);
        $due = $this->service(['expires_at' => '2026-10-21']);
        $this->openInvoice($due, '2026-10-21');

        foreach (['2026-10-26', '2026-10-27'] as $day) {
            Carbon::setTestNow("{$day} 03:00:00");
            $this->artisan('app:cron-job')->assertExitCode(0);
            for ($hour = 3; $hour <= 23; $hour++) {
                Carbon::setTestNow(sprintf('%s %02d:05:00', $day, $hour));
                $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);
            }
        }

        $this->assertSame(Service::STATUS_SUSPENDED, $due->fresh()->status, 'two whole days past its suspension day, still active');
    }
}
