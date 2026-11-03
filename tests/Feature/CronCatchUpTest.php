<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Models\BillingAgreement;
use App\Models\CronStat;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use MyCS\Extensions\Gateways\CatchPay\CatchPay;
use Tests\TestCase;

/**
 * 2026-11-04: a deploy from 23:59 had the app down at 00:00, the daily run did not start, and the day's invoices, charges
 * and reminders waited for staff to read the mail. The hourly check now starts it, once. The same night's run by hand
 * said «charged 0» for a charge it tried and the bank declined: declined charges are counted apart.
 */
class CronCatchUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.cronjob_time' => '00:00',
            'settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0]);
        if (!class_exists(CatchPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\CatchPay; class CatchPay {
                /** @var \\Closure|null */
                public static $answer = null;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) { return self::$answer === null ? true : (self::$answer)($invoice, $amount); } }');
        }
        CatchPay::$answer = null;
    }

    protected function tearDown(): void
    {
        if ($this->app->maintenanceMode()->active()) {
            $this->app->maintenanceMode()->deactivate();
        }
        Carbon::setTestNow();
        Cache::lock('app:cron-job')->forceRelease();
        parent::tearDown();
    }

    private function lastRun(?string $at): void
    {
        Setting::query()->where('key', 'last_cron_run')->where('settingable_type', CronStat::class)->delete();
        if ($at !== null) {
            Setting::query()->create(['key' => 'last_cron_run', 'settingable_type' => CronStat::class, 'value' => $at, 'type' => 'string']);
        }
    }

    private function ranAt(): ?string
    {
        return Setting::query()->where('key', 'last_cron_run')->where('settingable_type', CronStat::class)->value('value');
    }

    private function lateMails(): int
    {
        return EmailLog::query()->where('subject', 'Cron Job: the daily run started late')->count();
    }

    public function test_a_daily_run_that_did_not_start_is_started_by_the_hourly_check_once(): void
    {
        $this->lastRun('2026-11-03 00:00:40');
        Carbon::setTestNow('2026-11-04 00:35:00');

        $this->assertSame(0, Artisan::call('app:cron-job', ['--catch-up' => true]));
        $this->assertSame('2026-11-04 00:35:00', $this->ranAt(), 'today\'s run done');
        $this->assertSame(1, $this->lateMails(), 'staff told it started late');
        $this->assertTrue(CronStat::query()->where('key', 'invoices_created')->whereDate('date', '2026-11-04')->exists());

        // The next hours find it done.
        Carbon::setTestNow('2026-11-04 01:35:00');
        Artisan::call('app:cron-job', ['--catch-up' => true]);
        $this->assertSame('2026-11-04 00:35:00', $this->ranAt());
        $this->assertSame(1, $this->lateMails());
    }

    public function test_a_day_whose_run_started_is_left_alone(): void
    {
        $this->lastRun('2026-11-04 00:00:40');
        Carbon::setTestNow('2026-11-04 00:35:00');
        Artisan::call('app:cron-job', ['--catch-up' => true]);
        $this->assertSame('2026-11-04 00:00:40', $this->ranAt());
        $this->assertSame(0, $this->lateMails());

        // Before its time and ten minutes, nothing either (a run at 09:00 still to come).
        config(['settings.cronjob_time' => '09:00']);
        $this->lastRun('2026-11-03 09:00:40');
        Carbon::setTestNow('2026-11-04 09:05:00');
        Artisan::call('app:cron-job', ['--catch-up' => true]);
        $this->assertSame('2026-11-03 09:00:40', $this->ranAt());
    }

    public function test_not_while_the_app_is_down_nor_while_a_run_holds_the_lock_then_the_next_hour(): void
    {
        $this->lastRun('2026-11-03 00:00:40');
        Carbon::setTestNow('2026-11-04 00:35:00');
        $this->app->maintenanceMode()->activate([]);
        Artisan::call('app:cron-job', ['--catch-up' => true]);
        $this->app->maintenanceMode()->deactivate();
        $this->assertSame('2026-11-03 00:00:40', $this->ranAt(), 'not over a migrating database');
        $this->assertSame(0, EmailLog::query()->where('subject', 'like', 'Cron Job:%')->count(), 'silent: the next hour does it');

        // A run in progress (today's, maybe, still running): left to it, and the day's one start not spent.
        $lock = Cache::lock('app:cron-job', 7200);
        $this->assertTrue($lock->get());
        Carbon::setTestNow('2026-11-04 01:35:00');
        Artisan::call('app:cron-job', ['--catch-up' => true]);
        $lock->release();
        $this->assertSame('2026-11-03 00:00:40', $this->ranAt());

        Carbon::setTestNow('2026-11-04 02:35:00');
        Artisan::call('app:cron-job', ['--catch-up' => true]);
        $this->assertSame('2026-11-04 02:35:00', $this->ranAt());
    }

    public function test_it_is_scheduled_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains((string) $e->command, 'app:cron-job') && str_contains((string) $e->command, '--catch-up'));
        $this->assertNotNull($event, 'the hourly check is registered');
        $this->assertSame('35 * * * *', $event->expression);
    }

    public function test_a_declined_charge_is_counted_apart_from_the_charged_ones(): void
    {
        Carbon::setTestNow('2026-11-10 00:00:00');
        $user = User::factory()->create();
        $client = $user->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'CatchPay'], ['name' => 'CatchPay', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-12-10']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-10']);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        CatchPay::$answer = fn () => throw new DisplayException('Card declined: insufficient_funds');

        Artisan::call('app:cron-job');
        $this->assertSame(1, (int) CronStat::query()->where('key', 'charges_declined')->sum('value'));
        $this->assertSame(0, (int) CronStat::query()->where('key', 'invoices_charged')->sum('value'));
        $this->assertStringContainsString(__('admin.cronjob.charges_declined') . "' completed: Processed 1 items.", Artisan::output());
    }
}
