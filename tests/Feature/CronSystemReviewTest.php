<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\SuspendJob;
use App\Models\BillingAgreement;
use App\Models\CronStat;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use MyCS\Extensions\Gateways\CsPay\CsPay;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of the daily automation as a system (round 3 of #411, 5b4846a9): the hourly dunning run next to
 * the daily run, the shared lock, the counts and the run's own record. Each test failed on 5b4846a9.
 */
class CronSystemReviewTest extends TestCase
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

    /** A customer with «Auto-charge» on a saved method of a stub gateway that declines (or pays with $answer). */
    private function autoChargeCustomer(): User
    {
        if (!class_exists(CsPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\CsPay; class CsPay {
                public static array $charges = [];
                public static $answer = null;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = ["invoice" => $invoice->id, "amount" => (float) $amount, "at" => (string) \Illuminate\Support\Carbon::now()];
                    return self::$answer === null ? true : (self::$answer)($invoice, $amount);
                } }');
        }
        CsPay::$charges = [];
        CsPay::$answer = null;
        $user = User::factory()->create();
        $client = $user->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'CsPay'], ['name' => 'CsPay', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $client->refresh();
        $client->forceFill(['auto_charge' => true])->save();
        $this->assertNotNull($client->fresh()->autoChargeAgreement(), 'precondition: auto-charge on');

        return $user;
    }

    /**
     * HIGH (in #411). The window exists so «someone is in the office to unsuspend a good customer's service the same
     * morning». Staff do (EditService «Unsuspend»: active, no hold), and the next hourly run suspended it again within the
     * hour, mailing the customer a second suspension. A service the cron already suspended today is not suspended again
     * today.
     */
    public function test_a_service_staff_unsuspended_this_morning_is_not_suspended_again_the_next_hour(): void
    {
        config(['settings.cronjob_dunning_days' => [1, 2, 3, 4, 5], 'settings.cronjob_dunning_from' => '10:00']);
        $service = $this->service(['expires_at' => '2026-10-21']);
        $this->openInvoice($service, '2026-10-21');
        $this->marker('2026-10-26 00:00:05'); // Monday's daily run (at night)

        Carbon::setTestNow('2026-10-26 10:05:00');
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'precondition: suspended at 10:05');

        Carbon::setTestNow('2026-10-26 10:30:00'); // staff: Unsuspend (EditService), as the digest says
        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);

        Carbon::setTestNow('2026-10-26 11:05:00');
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'staff unsuspended it at 10:30: suspended again at 11:05');
        Queue::assertPushed(SuspendJob::class, 1);
    }

    /**
     * MEDIUM (in #411, pre-existing for a manual run). The daily run that finds the shared lock taken returned FAILURE
     * with a console line only: no mail to staff. The day's renewals, charges, reminders were skipped in silence.
     */
    public function test_a_daily_run_kept_out_by_the_lock_tells_staff(): void
    {
        Carbon::setTestNow('2026-10-26 14:10:00');
        $hourly = Cache::lock('app:cron-job', 7200);
        $this->assertTrue($hourly->get(), 'precondition: the hourly run holds the lock');

        try {
            $this->artisan('app:cron-job')->assertExitCode(1); // it waits for the lock (0 s in tests: TestCase)
        } finally {
            $hourly->release();
        }

        $this->assertTrue(EmailLog::query()->where('subject', 'like', '%Cron%')->exists(), 'the daily run did not run, and no one was told');
    }

    /** LOW (in #411). Cards the hourly run charges are in the dashboard's «Invoices Charged». */
    public function test_cards_the_hourly_run_charges_are_counted(): void
    {
        config(['settings.cronjob_dunning_days' => [1, 2, 3, 4, 5], 'settings.cronjob_dunning_from' => '10:00',
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.credits_auto_use' => false]);
        $user = $this->autoChargeCustomer();
        $service = $this->service(['expires_at' => '2026-10-26'], $user);
        $this->openInvoice($service, '2026-10-26');
        $this->marker('2026-10-26 00:00:05');

        Carbon::setTestNow('2026-10-26 10:05:00');
        $this->artisan('app:cron-job', ['--dunning-only' => true]);

        $this->assertCount(1, CsPay::$charges, 'precondition: the hourly run charged the card');
        $this->assertSame(1, (int) CronStat::query()->where('key', 'invoice_charged')->sum('value'), '«Invoices Charged» misses the hourly charge');
    }

    /**
     * LOW-MEDIUM (in #411, money). AutoCharge retried «until its service is suspended» from cronjob_order_suspend: with a
     * window that moves the suspension (no weekend) the card was not tried on the days between, nor that morning.
     */
    public function test_the_card_is_tried_before_a_suspension_the_window_postponed(): void
    {
        config(['settings.cronjob_dunning_days' => [1, 2, 3, 4, 5], 'settings.cronjob_dunning_from' => '10:00',
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.credits_auto_use' => false]);
        $user = $this->autoChargeCustomer();
        $service = $this->service(['expires_at' => '2026-10-21'], $user); // Wednesday; suspension day Saturday 24 → Monday 26
        $this->openInvoice($service, '2026-10-21');

        // Wed … Sun: declined by the daily run at night (window closed then).
        CsPay::$answer = fn () => throw new DisplayException('Card declined: insufficient_funds');
        foreach (['2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25'] as $day) {
            Carbon::setTestNow("{$day} 00:00:05");
            try {
                Artisan::call('app:cron-job');
            } catch (Throwable) {
            }
        }
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: not suspended on the weekend');
        $tries = count(CsPay::$charges);

        // Monday: the money is there now (the gateway records the payment, as a real one does: round 2's stub). The daily
        // run at 00:00, then the hourly run at 10:05.
        CsPay::$answer = function ($invoice, $amount) {
            ExtensionHelper::addPayment($invoice->id, 'CsPay', $amount, null, 'ch_' . count(CsPay::$charges), currency: 'USD');

            return true;
        };
        foreach (['2026-10-26 00:00:05' => false, '2026-10-26 10:05:00' => true] as $at => $hourly) {
            Carbon::setTestNow($at);
            try {
                Artisan::call('app:cron-job', $hourly ? ['--dunning-only' => true] : []);
            } catch (Throwable) {
            }
        }

        $this->assertGreaterThan($tries, count(CsPay::$charges), 'never tried on Sat, Sun or Mon before the suspension it postponed');
        $this->assertNotSame(Service::STATUS_SUSPENDED, $service->fresh()->status);
    }

    /**
     * LOW (pre-existing). One record that fails every night made every daily run a failure that never wrote
     * last_cron_run: Health and the Automation status said «not run for N days» while it ran every night.
     */
    public function test_a_run_with_one_failed_step_is_still_recorded_as_run(): void
    {
        // A table dropped makes a step fail; on MariaDB that DDL ends the test's transaction (SQLite runs it).
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('DDL inside the test transaction: run on SQLite.');
        }
        Carbon::setTestNow('2026-10-26 00:00:05');
        Schema::drop('mcp_calls'); // one step fails; every other step does its work

        try {
            Artisan::call('app:cron-job');
        } catch (Throwable) {
        }

        $last = Setting::query()->where('key', 'last_cron_run')->where('settingable_type', CronStat::class)->value('value');
        $this->assertNotNull($last, 'the run happened (every other step done), yet Health says the daily cron never ran');
    }
}
