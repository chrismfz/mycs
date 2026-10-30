<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * «Don't suspend until…» (sweep 9γ G1, owner 2026-10-30): the customer of a service held while overdue is told the day
 * before the hold ends, with the date, once per date (again when staff move it); when it is suspended after it, staff are
 * told the hold ended unpaid.
 */
class SuspendHoldNoticeTest extends TestCase
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

    /** Overdue since 2026-11-01, held until $hold. */
    private function held(string $hold): Service
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
            'expires_at' => '2026-11-01 00:00:00', 'suspend_hold_until' => $hold]);
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => '2026-11-01', 'created_at' => '2026-10-25']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10]);
        EmailLog::query()->delete();

        return $service;
    }

    private function cron(?string $now = null): void
    {
        if ($now !== null) {
            Carbon::setTestNow($now);
        }
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function notices(): int
    {
        return EmailLog::query()->where('template_key', 'service_hold_ending')->count();
    }

    public function test_the_customer_is_told_the_day_before_the_hold_ends_once_and_again_when_it_moves(): void
    {
        $service = $this->held('2026-11-20');

        $this->cron(); // a week before: nothing yet
        $this->assertSame(0, $this->notices());

        $this->cron('2026-11-19 03:00:00');
        $this->assertSame(1, $this->notices(), 'the day before its last day');
        $this->assertStringContainsString('2026', (string) EmailLog::query()->where('template_key', 'service_hold_ending')->value('subject'));
        $this->cron('2026-11-19 15:00:00');
        $this->assertSame(1, $this->notices(), 'once per date');
        $this->assertSame('active', $service->fresh()->status, 'still held');

        $service->update(['suspend_hold_until' => '2026-11-26']); // staff give a week more
        $this->cron('2026-11-25 03:00:00');
        $this->assertSame(2, $this->notices(), 'told again of the new date');
    }

    public function test_suspended_after_the_hold_staff_are_told_it_ended_unpaid(): void
    {
        $service = $this->held('2026-11-20');
        $this->cron('2026-11-19 03:00:00'); // its customer told

        $this->cron('2026-11-21 03:00:00');

        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertTrue(EmailLog::query()->where('subject', 'like', '%needs staff attention%')
            ->where('body', 'like', "%Service #{$service->id} was suspended: its hold until 2026-11-20 ended%")->exists());
    }

    public function test_a_paid_service_on_hold_is_never_told(): void
    {
        $service = $this->held('2026-11-20');
        Invoice::query()->where('client_id', $service->client_id)->update(['status' => Invoice::STATUS_CANCELLED]);
        $service->update(['expires_at' => '2026-12-01 00:00:00']);

        $this->cron('2026-11-19 03:00:00');

        $this->assertSame(0, $this->notices(), 'not overdue: nothing to warn of');
    }

    /** A hold of months ago, its customer never told of it: staff are not told it «ended» (code review 1, ADV-HOLD-4). */
    public function test_a_stale_hold_tells_staff_nothing(): void
    {
        $service = $this->held('2026-03-01');

        $this->cron('2026-11-21 03:00:00');

        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertFalse(EmailLog::query()->where('body', 'like', '%its hold until 2026-03-01 ended%')->exists());
    }
}
