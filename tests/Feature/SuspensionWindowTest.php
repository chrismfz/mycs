<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Models\CronStat;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use App\Support\DunningWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Suspensions and terminations on working days, from 10:00 (owner 2026-10-23: someone in the office to unsuspend a good
 * customer the same morning; WHMCS needed cron tricks). The daily run at night does the rest every day; what falls on a
 * weekend waits for Monday, nothing is skipped.
 */
class SuspensionWindowTest extends TestCase
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

    private function service(array $attributes): Service
    {
        $product = $this->createProduct();

        $service = Service::factory()->create(array_merge([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ], $attributes));
        // Its renewal billed, as the daily run does before any suspension (the hourly run leaves an unbilled period to it).
        if ($service->expires_at !== null && $service->status === Service::STATUS_ACTIVE) {
            $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => $service->expires_at]);
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        }

        return $service;
    }

    private function at(string $time): void
    {
        Carbon::setTestNow($time);
    }

    public function test_a_weekend_waits_for_monday_from_ten(): void
    {
        $due = $this->service(['expires_at' => '2026-10-21']); // Suspend Days (2) passed on Saturday 24/10
        $old = $this->service(['expires_at' => '2026-10-01', 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => '2026-10-04']);

        $this->at('2026-10-24 03:00:00'); // Saturday, the daily run at night
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->at('2026-10-24 10:05:00'); // Saturday, the hourly run
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);
        $this->at('2026-10-26 03:00:00'); // Monday night
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->at('2026-10-26 09:05:00'); // Monday, before ten
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);
        $this->assertSame([Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], [$due->fresh()->status, $old->fresh()->status]);

        $this->at('2026-10-26 10:05:00'); // Monday, ten
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);

        $this->assertSame([Service::STATUS_SUSPENDED, Service::STATUS_TERMINATED], [$due->fresh()->status, $old->fresh()->status]);
    }

    public function test_a_daily_run_inside_the_window_does_them_itself(): void
    {
        config(['settings.cronjob_dunning_from' => '09:00']);
        $due = $this->service(['expires_at' => '2026-10-21']);

        $this->at('2026-10-26 09:30:00'); // Monday, the daily run at 09:30
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $due->fresh()->status);
    }

    public function test_the_hourly_run_waits_for_todays_daily_run(): void
    {
        $due = $this->service(['expires_at' => '2026-10-21']);
        Setting::query()->updateOrCreate(['key' => 'last_cron_run', 'settingable_type' => CronStat::class],
            ['value' => '2026-10-25 03:00:00', 'type' => 'string']); // Sunday's: today's has not run

        $this->at('2026-10-26 10:05:00');
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);

        $this->assertSame(Service::STATUS_ACTIVE, $due->fresh()->status, 'credit and payments are applied by the daily run first');
    }

    public function test_by_default_nothing_changes_the_hourly_run_does_nothing(): void
    {
        config(['settings.cronjob_dunning_days' => DunningWindow::ALL_DAYS, 'settings.cronjob_dunning_from' => '00:00']);
        $due = $this->service(['expires_at' => '2026-10-21']);
        Setting::query()->updateOrCreate(['key' => 'last_cron_run', 'settingable_type' => CronStat::class],
            ['value' => '2026-10-24 01:00:00', 'type' => 'string']);

        $this->at('2026-10-24 10:05:00');
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);
        $this->assertSame(Service::STATUS_ACTIVE, $due->fresh()->status);
        $this->assertSame(0, CronStat::query()->count(), 'an hourly run that did nothing leaves no row');

        $this->artisan('app:cron-job')->assertExitCode(0); // the daily run, as before
        $this->assertSame(Service::STATUS_SUSPENDED, $due->fresh()->status);
    }

    public function test_the_hourly_run_is_no_daily_run(): void
    {
        Setting::query()->updateOrCreate(['key' => 'last_cron_run', 'settingable_type' => CronStat::class],
            ['value' => '2026-10-26 03:00:00', 'type' => 'string']);
        $this->service(['expires_at' => '2026-10-21']);

        $this->at('2026-10-26 10:05:00');
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);

        $this->assertSame('2026-10-26 03:00:00', Setting::query()->where('key', 'last_cron_run')->value('value'));
        $this->assertSame(['services_suspended'], CronStat::query()->pluck('key')->all());
    }

    public function test_the_last_overdue_notice_names_the_next_working_day(): void
    {
        // Due Wednesday 21/10: Suspend Days 2, so Saturday 24/10 — on a working-days window, Monday 26/10.
        $service = $this->service(['expires_at' => '2026-10-21']);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-21']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        $this->at('2026-10-22 03:00:00');

        $suspendOn = (new \ReflectionMethod(PaymentReminders::class, 'suspendOn'))->invoke(null, $invoice->fresh());

        $this->assertSame('26/10/2026', $suspendOn);
    }

    public function test_the_window_reads_its_settings_safely(): void
    {
        config(['settings.cronjob_dunning_days' => [], 'settings.cronjob_dunning_from' => 'nonsense']);
        $this->assertTrue(DunningWindow::always(), 'nothing chosen is every day, from midnight');

        config(['settings.cronjob_dunning_days' => ['6', '7'], 'settings.cronjob_dunning_from' => '10:30']);
        $this->assertTrue(DunningWindow::isOpen(Carbon::parse('2026-10-24 10:30:00')));
        $this->assertTrue(DunningWindow::isOpen(Carbon::parse('2026-10-24 10:00:00')), 'on the hour: 10:30 opens at 10:00');
        $this->assertFalse(DunningWindow::isOpen(Carbon::parse('2026-10-24 09:59:00')));
        $this->assertFalse(DunningWindow::isOpen(Carbon::parse('2026-10-26 12:00:00')));
        $this->assertSame('2026-10-31', DunningWindow::firstDayFrom(Carbon::parse('2026-10-26'))->toDateString());
    }

    /** Staff unsuspend a service the cron suspended: held through that day only; the next working day the rule applies. */
    public function test_an_unsuspend_holds_through_the_day_only(): void
    {
        $service = $this->service(['expires_at' => '2026-10-21']);
        Setting::query()->updateOrCreate(['key' => CronJob::DUNNING_READY, 'settingable_type' => CronStat::class],
            ['value' => '2026-10-26 03:00:00', 'type' => 'string']);
        $this->at('2026-10-26 10:05:00');
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);
        $this->at('2026-10-26 10:30:00');
        $service->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);
        $this->assertSame('2026-10-26', Carbon::parse($service->fresh()->suspend_hold_until)->toDateString());

        Setting::query()->where('key', CronJob::DUNNING_READY)->update(['value' => '2026-10-27 03:00:00']);
        $this->at('2026-10-27 10:05:00');
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'still unpaid the next day: suspended');
    }
}
