<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Round-2 adversarial review of «don't suspend until…» warns (sweep 9γ G1, #555): holdsEnding's two passes, the hourly
 * run, heldBack under «suspend only with an overdue invoice», and the staff notice. test_bug_* fail on the PR as it
 * stands; test_holds_* are guarantees that held.
 *
 * Suspend Days 2. Calendar: 2026-11-19 is a Thursday, 11-20 a Friday, 11-21 a Saturday, 11-23 a Monday.
 */
class SuspendHoldNoticeAdversarialR2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 03:00:00');
        Queue::fake();
        Mail::fake();
        (new EmailTemplateSeeder)->run();
        config([
            'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 60, 'settings.cronjob_invoice' => 7,
            'settings.cronjob_suspend_requires_invoice' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Overdue since $expires with an unpaid renewal (due $due, issued $issued), held until $hold. */
    private function held(?string $hold, string $expires = '2026-11-01', string $due = '2026-11-01', string $issued = '2026-10-25', ?Client $client = null): Service
    {
        $product = $this->createProduct();
        $client ??= User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
            'expires_at' => "{$expires} 00:00:00", 'suspend_hold_until' => $hold]);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => $due, 'created_at' => $issued]);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        EmailLog::query()->delete();

        return $service;
    }

    private function cron(string $now): void
    {
        Carbon::setTestNow($now);
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function hourly(string $now): void
    {
        Carbon::setTestNow($now);
        $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);
    }

    private function notices(): int
    {
        return EmailLog::query()->where('template_key', 'service_hold_ending')->count();
    }

    private function staffToldHoldEnded(Service $service): bool
    {
        return EmailLog::query()->where('body', 'like', "%Service #{$service->id} was suspended: its hold until%")->exists();
    }

    /**
     * ADV-HOLD-R2-1. «Suspend only with an overdue invoice»: the renewal is due 11-17, so without any hold the service is
     * suspended on 11-20 (Suspend Days from the invoice's due date). A hold until 11-19 holds nothing back: it is suspended
     * on 11-20 all the same. heldBack() counts the natural day from expires_at alone (11-04), so staff are told «its hold
     * until 2026-11-19 ended with its invoice unpaid». Without requires_invoice the same hold (the day before the natural
     * day) tells staff nothing (test_holds_a_hold_ending_the_day_before_the_natural_day_tells_staff_nothing).
     */
    public function test_bug_requires_invoice_staff_told_a_hold_ended_that_held_nothing_back(): void
    {
        config(['settings.cronjob_suspend_requires_invoice' => true]);
        $service = $this->held('2026-11-19', due: '2026-11-17', issued: '2026-11-10');

        $this->cron('2026-11-18 03:00:00');
        $this->assertSame(1, $this->notices(), 'precondition: its customer was told');
        $this->cron('2026-11-20 03:00:00');
        $this->assertSame('suspended', $service->fresh()->status, 'precondition');

        $this->assertFalse($this->staffToldHoldEnded($service),
            'suspended on the day its invoice made it suspendable: the hold until 11-19 delayed nothing');
    }

    /**
     * ADV-HOLD-R2-2. The staff notice is recorded inside the suspension's transaction, before the status is saved: a
     * suspension that rolls back (the save throws: a lock wait, a listener) still mails staff «Service #N was suspended:
     * its hold … ended» next to the error, while the service is active.
     */
    public function test_bug_staff_told_suspended_when_the_suspension_rolled_back(): void
    {
        $service = $this->held('2026-11-20');
        $this->cron('2026-11-19 03:00:00');
        $this->assertSame(1, $this->notices(), 'precondition: its customer was told');

        Service::updating(function (Service $s) {
            if ($s->isDirty('status') && $s->status === 'suspended') {
                throw new RuntimeException('Lock wait timeout exceeded');
            }
        });
        Carbon::setTestNow('2026-11-21 03:00:00');
        try {
            $this->artisan('app:cron-job')->run();
        } catch (Throwable) {
            // the daily run rethrows its first failure
        }

        $this->assertSame('active', $service->fresh()->status, 'precondition: the suspension rolled back');
        $this->assertFalse($this->staffToldHoldEnded($service), 'staff told it was suspended; it was not');
    }

    public function test_holds_a_hold_ending_the_day_before_the_natural_day_tells_staff_nothing(): void
    {
        Carbon::setTestNow('2026-11-02 03:00:00');
        $service = $this->held('2026-11-03');

        $this->cron('2026-11-02 03:00:00');
        $this->cron('2026-11-04 03:00:00');

        $this->assertSame('suspended', $service->fresh()->status, 'precondition');
        $this->assertFalse($this->staffToldHoldEnded($service));
    }

    /** One hold date per service: the two passes (today, tomorrow) never find it twice, nor do the hourly runs after. */
    public function test_holds_told_once_across_the_two_passes_and_the_hourly_runs(): void
    {
        config(['settings.cronjob_dunning_days' => [1, 2, 3, 4, 5], 'settings.cronjob_dunning_from' => '10:00']);
        $service = $this->held('2026-11-20');

        $this->cron('2026-11-19 03:00:00');
        $this->hourly('2026-11-19 10:05:00');
        $this->hourly('2026-11-19 11:05:00');
        $this->cron('2026-11-20 03:00:00');
        $this->hourly('2026-11-20 10:05:00');

        $this->assertSame(1, $this->notices());
        $this->assertSame('active', $service->fresh()->status);
    }

    /** Hours: the window from 10:00 on weekdays, a hold until Thursday: told Friday, suspended Friday at 10:05, not before. */
    public function test_holds_the_day_named_is_the_day_the_hourly_run_suspends(): void
    {
        config(['settings.cronjob_dunning_days' => [1, 2, 3, 4, 5], 'settings.cronjob_dunning_from' => '10:00']);
        $service = $this->held('2026-11-19');

        $this->cron('2026-11-19 03:00:00');
        $this->assertStringContainsString('November 20, 2026',
            (string) EmailLog::query()->where('template_key', 'service_hold_ending')->value('subject'));

        $this->cron('2026-11-20 03:00:00');
        $this->assertSame('active', $service->fresh()->status, 'the window opens at 10:00');
        $this->hourly('2026-11-20 10:05:00');
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertTrue($this->staffToldHoldEnded($service));
    }

    /** The hourly run tells a hold the daily run missed (set after it, ending tomorrow), once. */
    public function test_holds_the_hourly_run_tells_a_hold_set_after_the_daily_run(): void
    {
        config(['settings.cronjob_dunning_days' => [1, 2, 3, 4, 5], 'settings.cronjob_dunning_from' => '10:00']);
        $service = $this->held(null);
        $service->update(['expires_at' => '2026-11-18 00:00:00']);
        $this->cron('2026-11-19 03:00:00');
        $this->assertSame(0, $this->notices());

        $service->update(['suspend_hold_until' => '2026-11-20']);
        $this->hourly('2026-11-19 10:05:00');
        $this->hourly('2026-11-19 11:05:00');

        $this->assertSame(1, $this->notices());
    }

    public function test_holds_a_service_without_a_due_date_is_never_told_nor_breaks_the_run(): void
    {
        $service = $this->held('2026-11-20');
        Service::query()->whereKey($service->id)->update(['expires_at' => null]);

        $this->cron('2026-11-19 03:00:00');
        $this->cron('2026-11-21 03:00:00');

        $this->assertSame(0, $this->notices());
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_holds_a_hold_removed_after_its_notice_tells_staff_nothing(): void
    {
        $service = $this->held('2026-11-20');
        $this->cron('2026-11-19 03:00:00');
        $this->assertSame(1, $this->notices());

        $service->update(['suspend_hold_until' => null]);
        $this->cron('2026-11-19 15:00:00');

        $this->assertSame('suspended', $service->fresh()->status, 'no hold: past due, suspended');
        $this->assertFalse($this->staffToldHoldEnded($service));
        $this->assertSame(1, $this->notices());
    }
}
