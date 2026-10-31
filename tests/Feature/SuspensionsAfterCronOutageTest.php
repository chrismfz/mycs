<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The cron down for days (sweep 9γ, owner 2026-10-30: «suspensions after a 2-4 day outage must happen when it runs
 * again, never be skipped»): the first run after it suspends what fell due meanwhile, bills a renewal it missed, and
 * never terminates in that same run (minimum days suspended); with suspensions kept to days and hours, the first hour
 * the window opens after the daily run does them.
 */
class SuspensionsAfterCronOutageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 03:00:00'); // a Monday
        Queue::fake();
        Mail::fake();
        config([
            'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 5, 'settings.cronjob_invoice' => 7,
            'settings.cronjob_suspend_requires_invoice' => false, 'settings.cronjob_terminate_min_suspended_days' => 1,
            'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(string $expires): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10.00,
            'quantity' => 1, 'expires_at' => $expires]);
    }

    private function cron(string $now, bool $hourly = false): void
    {
        Carbon::setTestNow($now);
        $this->artisan('app:cron-job', $hourly ? ['--dunning-only' => true] : [])->assertExitCode(0);
    }

    public function test_the_first_run_after_days_down_suspends_what_fell_due_and_terminates_nothing_yet(): void
    {
        // Due 11-03: its renewal billed by the run of 11-02, suspendable from 11-06 (Suspend Days 2), terminable from 11-09.
        $service = $this->service('2026-11-03 00:00:00');
        $this->cron('2026-11-02 03:00:00');
        $this->assertSame(1, Invoice::query()->where('client_id', $service->client_id)->count(), 'precondition: its renewal billed');

        // The cron down from 11-03 to 11-09 (its suspension day and its termination day both missed).
        $this->cron('2026-11-10 03:00:00');

        $service->refresh();
        $this->assertSame(Service::STATUS_SUSPENDED, $service->status, 'the suspension it missed, done at once');
        $this->assertSame('2026-11-10', $service->dunning_suspended_at->toDateString());

        $this->cron('2026-11-11 03:00:00');
        $this->assertSame(Service::STATUS_TERMINATED, $service->fresh()->status, 'terminated a day later, never in the same run');
    }

    public function test_a_renewal_never_billed_because_the_cron_was_down_is_billed_then_suspended_when_due(): void
    {
        // Due 11-05, never billed: the cron was down from before its invoice day.
        $service = $this->service('2026-11-05 00:00:00');

        $this->cron('2026-11-09 03:00:00');

        $this->assertSame(1, Invoice::query()->where('client_id', $service->client_id)->where('status', Invoice::STATUS_UNPAID)->count(),
            'the renewal it missed is billed');
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'past Suspend Days already: suspended in the same run');
    }

    public function test_with_suspensions_on_weekdays_from_ten_the_first_open_hour_after_the_outage_does_them(): void
    {
        config(['settings.cronjob_dunning_days' => [1, 2, 3, 4, 5], 'settings.cronjob_dunning_from' => '10:00']);
        $service = $this->service('2026-11-03 00:00:00');
        $this->cron('2026-11-02 03:00:00');

        // Down until Tuesday 11-10: the night's daily run waits for the window, its first open hour suspends.
        $this->cron('2026-11-10 03:00:00');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'not before the window opens');
        $this->cron('2026-11-10 10:05:00', hourly: true);

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'the first open hour after the outage');
    }
}
