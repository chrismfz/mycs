<?php

namespace Tests\Feature;

use App\Console\Commands\CronJob;
use App\Models\CronStat;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Service\DunningRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep before cut-over, area «cron-billing»: adversarial verification of the finder's candidates. Each test fails on
 * main (00268d7f) for the reason reported. Fake data only.
 */
class SweepCronBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::lock('app:cron-job')->forceRelease();
        parent::tearDown();
    }

    private function service(string $expires, array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
            ...$attributes,
        ]);
    }

    /**
     * CRON-BILLING-1. With «suspend only with an overdue invoice», Service::stillOverdue() (what a payment asks before it
     * unsuspends) counts renewal invoices only (#507: a charge alone is not the invoice a suspension requires), but
     * DunningRules::suspendable() (what the cron suspends) counts every invoice, a charge too. A service whose renewal
     * was just paid, still past Suspend Days, with an old imported setup fee open: the payment unsuspends it (stillOverdue
     * false) and the same night's cron suspends it again, the churn Δ3a was meant to prevent.
     */
    public function test_cron_billing_1_an_overdue_charge_alone_suspends_what_a_payment_unsuspended(): void
    {
        Carbon::setTestNow('2026-11-11 09:00:00');
        Queue::fake();
        config(['settings.cronjob_suspend_requires_invoice' => true, 'settings.cronjob_order_suspend' => 8, 'settings.cronjob_invoice' => 7,
            'settings.credits_auto_use' => false]);
        // Paid until 2026-11-01 (the renewal just paid): ten days ago, past the 8 Suspend Days.
        $service = $this->service('2026-11-01');
        $charge = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $charge->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Setup fee',
            'quantity' => 1, 'price' => 20, 'kind' => InvoiceItem::KIND_CHARGE]);
        Invoice::query()->whereKey($charge->id)->update(['due_at' => '2026-10-01', 'created_at' => '2026-10-01']);

        $this->assertFalse($service->fresh()->stillOverdue(Carbon::parse('2026-11-01')),
            'the payment side: an open charge alone does not keep it suspended, so the payment unsuspends it');

        $this->assertFalse(DunningRules::suspendable(today(), true)->whereKey($service->id)->exists(),
            'the cron side must agree: the same open charge alone is not the overdue invoice its suspension requires');

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'not suspended again the same night');
    }

    /**
     * CRON-BILLING-2. A run that started before midnight (yesterday's, by hand or late) held the lock past today's 00:00
     * run's wait and ended after midnight: today's run did not start, and staff were told the hourly check starts it at
     * :35. The check reads the end of yesterday's run (last_cron_run, today 00:15) as «a run started today» and does
     * nothing: today's renewals, charges and reminders never run.
     */
    public function test_cron_billing_2_the_end_of_yesterdays_run_is_not_todays_start(): void
    {
        config(['settings.cronjob_time' => '00:00', 'settings.mail_disable' => true, 'settings.credits_auto_use' => false]);
        Setting::query()->create(['key' => CronJob::STARTED, 'settingable_type' => CronStat::class, 'value' => '2026-11-03 23:50:00', 'type' => 'string']);
        Setting::query()->create(['key' => 'last_cron_run', 'settingable_type' => CronStat::class, 'value' => '2026-11-04 00:15:00', 'type' => 'string']);
        Carbon::setTestNow('2026-11-04 00:35:00');

        $this->assertSame(0, Artisan::call('app:cron-job', ['--catch-up' => true]));

        $this->assertSame('2026-11-04 00:35:00', Setting::query()->where('key', CronJob::STARTED)->where('settingable_type', CronStat::class)->value('value'),
            'today\'s daily run is started by the catch-up: the only run that started was yesterday\'s');
    }

    /**
     * CRON-BILLING-3. A service on the 31st (billing_day 31) with no period lines (imported) and an expiry of 28 February:
     * its period is 31 January → 28 February. Service::currentPeriodStart() goes one month back without the billing day
     * (BillingPeriod::start) and says 28 January, a 31-day period: upgrades, downgrade credit and addons are prorated on it.
     */
    public function test_cron_billing_3_the_current_period_of_a_31st_service_starts_on_the_31st(): void
    {
        Carbon::setTestNow('2027-02-14 10:00:00');
        $service = $this->service('2027-02-28', ['billing_day' => 31, 'created_at' => '2026-01-31 12:00:00']);

        $this->assertSame(31, $service->fresh()->billingDay(Carbon::parse('2027-02-28')), 'the app knows it bills on the 31st');
        $this->assertSame('2027-01-31', $service->fresh()->currentPeriodStart()?->toDateString(),
            'its period started on 31 January, not 28 January');
    }
}
