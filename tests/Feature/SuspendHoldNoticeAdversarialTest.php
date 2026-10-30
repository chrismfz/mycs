<?php

namespace Tests\Feature;

use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Adversarial review of «don't suspend until…» warns (sweep 9γ G1): CronJob::holdsEnding and the staff notice in
 * suspendServices. test_bug_* fail on the PR as it stands; test_holds_* are guarantees that held.
 *
 * Calendar: 2026-11-19 is a Thursday, 11-20 a Friday, 11-21 a Saturday, 11-23 a Monday.
 */
class SuspendHoldNoticeAdversarialTest extends TestCase
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

    /** Overdue since 2026-11-01 with its unpaid renewal, held until $hold. */
    private function held(?string $hold, ?Client $client = null, string $name = 'Test Product'): Service
    {
        $product = $this->createProduct();
        $product->product->update(['name' => $name]);
        $client ??= User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id,
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

    private function lastNoticeSubject(): string
    {
        return (string) EmailLog::query()->where('template_key', 'service_hold_ending')->orderByDesc('id')->value('subject');
    }

    /**
     * ADV-HOLD-1. Suspensions only on weekdays (DunningWindow, Settings → Automation). A hold until Friday 11-20: the mail
     * says «suspended on November 21» (a Saturday), the cron suspends on Monday 11-23. PaymentReminders::suspendOn uses
     * DunningWindow::firstDayFrom for this; holdsEnding does not.
     */
    public function test_bug_the_mail_names_a_day_the_dunning_window_does_not_suspend_on(): void
    {
        config(['settings.cronjob_dunning_days' => [1, 2, 3, 4, 5]]);
        $service = $this->held('2026-11-20');

        $this->cron('2026-11-19 03:00:00');
        $this->assertSame(1, $this->notices());

        $this->cron('2026-11-21 03:00:00');
        $this->assertSame('active', $service->fresh()->status, 'precondition: not suspended on the Saturday it named');

        $this->assertStringContainsString('November 23, 2026', $this->lastNoticeSubject(), 'the day it is really suspended');
    }

    /**
     * ADV-HOLD-2a. Staff set the hold to today after the daily run (the customer phoned at 10:00: «till tonight»). Only the
     * daily run looks for holds ending; tomorrow's run finds the hold past and suspends with no notice at all.
     */
    public function test_bug_a_hold_set_to_today_after_the_daily_run_is_suspended_unwarned(): void
    {
        $service = $this->held('2026-11-30');
        $this->cron('2026-11-19 03:00:00');
        $this->assertSame(0, $this->notices(), 'precondition: not yet');

        Carbon::setTestNow('2026-11-19 10:00:00');
        $service->update(['suspend_hold_until' => '2026-11-19']);
        $this->cron('2026-11-20 03:00:00');

        $this->assertSame('suspended', $service->fresh()->status, 'precondition');
        $this->assertSame(1, $this->notices(), 'the customer was never told the date');
    }

    /**
     * ADV-HOLD-2b. A hold moved earlier after its notice went out: told «suspended on November 21» on 11-19, staff move the
     * hold to 11-19 after the run, and it is suspended on 11-20 with no new notice: the only mail names the wrong day.
     */
    public function test_bug_a_hold_moved_earlier_after_its_notice_leaves_the_wrong_date(): void
    {
        $service = $this->held('2026-11-20');
        $this->cron('2026-11-19 03:00:00');
        $this->assertStringContainsString('November 21, 2026', $this->lastNoticeSubject());

        Carbon::setTestNow('2026-11-19 11:00:00');
        $service->update(['suspend_hold_until' => '2026-11-19']);
        $this->cron('2026-11-20 03:00:00');

        $this->assertSame('suspended', $service->fresh()->status, 'precondition');
        $this->assertStringContainsString('November 20, 2026', $this->lastNoticeSubject(), 'told of the day it was really suspended');
    }

    /**
     * ADV-HOLD-3. The cron down on the hold's last two days (an outage, a deploy): the next run finds the hold past and
     * suspends at once; the customer the owner wanted told never is. Nothing holds the suspension for a notice not sent.
     */
    public function test_bug_cron_down_on_the_last_two_days_suspends_without_the_notice(): void
    {
        $service = $this->held('2026-11-20');
        $this->cron('2026-11-18 03:00:00');
        // no run on 11-19 nor 11-20
        $this->cron('2026-11-21 03:00:00');

        $this->assertSame('suspended', $service->fresh()->status, 'precondition');
        $this->assertSame(1, $this->notices(), 'suspended with no notice of the hold\'s end');
    }

    /**
     * ADV-HOLD-4. suspend_hold_until is never cleared: a hold from March (a phoned-in delay long paid since), and the
     * service suspended in November for a new unpaid period. Staff are told «its hold until 2026-03-01 ended with its
     * invoice unpaid»: the hold had nothing to do with it. Same for holds the system sets (datesOnStart's order hold, the
     * observer's «today» on an unsuspend, the pro rata Suspend Days hold).
     */
    public function test_bug_a_stale_hold_from_months_ago_tells_staff_its_hold_ended_unpaid(): void
    {
        $service = $this->held('2026-03-01');

        $this->cron('2026-11-13 03:00:00');

        $this->assertSame('suspended', $service->fresh()->status, 'precondition');
        $this->assertFalse(EmailLog::query()->where('body', 'like', "%Service #{$service->id} was suspended: its hold until 2026-03-01 ended%")->exists(),
            'a hold that ended eight months ago did not delay this suspension');
    }

    /**
     * ADV-HOLD-5. A card charge on its way for the invoice (taken by the gateway, webhook not here): suspendServices skips
     * it (chargeOnItsWay), holdsEnding does not, so the customer who just paid is told «will be suspended».
     */
    public function test_bug_a_charge_on_its_way_is_told_it_will_be_suspended(): void
    {
        $service = $this->held('2026-11-20');
        $invoice = Invoice::query()->where('client_id', $service->client_id)->firstOrFail();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'TakenPay'], ['name' => 'TakenPay', 'type' => 'gateway', 'enabled' => true]);
        $agreement = BillingAgreement::query()->create(['client_id' => $service->client_id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_t', 'type' => 'card']);
        Carbon::setTestNow('2026-11-19 02:00:00');
        ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'client_id' => $service->client_id,
            'billing_agreement_id' => $agreement->id, 'amount' => '10.00', 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON,
            'status' => ChargeAttempt::STATUS_SUCCEEDED, 'started_at' => now(), 'sent_at' => now(), 'finished_at' => now()]);

        $this->cron('2026-11-19 03:00:00');

        $this->assertSame(0, $this->notices(), 'its payment is on its way: nothing will suspend it');
    }

    /**
     * ADV-HOLD-6 (SQLite only). A hold written without a time part (the WHMCS importer writes substr(…, 0, 10) with the
     * query builder): the notice date is saved through the cast as «2026-11-20 00:00:00», and whereColumn('!=') compares
     * the strings, so the customer is mailed again on every run. MariaDB's DATE columns hide it.
     */
    public function test_bug_a_hold_without_a_time_part_is_told_on_every_run(): void
    {
        $service = $this->held('2026-11-20');
        DB::table('services')->where('id', $service->id)->update(['suspend_hold_until' => '2026-11-20']);

        $this->cron('2026-11-19 03:00:00');
        $this->cron('2026-11-19 15:00:00');
        $this->cron('2026-11-20 03:00:00');

        $this->assertSame(1, $this->notices(), 'once per date');
    }

    public function test_holds_no_notice_for_a_dunning_exempt_account(): void
    {
        $service = $this->held('2026-11-20');
        $service->client->forceFill(['dunning_exempt' => true])->save();

        $this->cron('2026-11-19 03:00:00');

        $this->assertSame(0, $this->notices());
    }

    public function test_holds_no_notice_with_suspension_disabled(): void
    {
        config(['settings.cronjob_suspend_enabled' => false]);
        $this->held('2026-11-20');

        $this->cron('2026-11-19 03:00:00');

        $this->assertSame(0, $this->notices());
    }

    public function test_holds_no_notice_with_requires_invoice_and_no_open_invoice(): void
    {
        config(['settings.cronjob_suspend_requires_invoice' => true]);
        $service = $this->held('2026-11-20');
        Invoice::query()->where('client_id', $service->client_id)->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->cron('2026-11-19 03:00:00');

        $this->assertSame(0, $this->notices());
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_holds_no_notice_when_paid_before_the_run(): void
    {
        $service = $this->held('2026-11-20');
        Invoice::query()->where('client_id', $service->client_id)->update(['status' => Invoice::STATUS_PAID]);
        $service->update(['expires_at' => '2026-12-01 00:00:00']);

        $this->cron('2026-11-19 03:00:00');

        $this->assertSame(0, $this->notices());
    }

    public function test_holds_one_mail_per_service_of_one_customer(): void
    {
        $first = $this->held('2026-11-20', null, 'Alpha Hosting');
        $this->held('2026-11-20', $first->client, 'Beta Hosting');

        $this->cron('2026-11-19 03:00:00');
        $this->cron('2026-11-19 15:00:00');

        $subjects = EmailLog::query()->where('template_key', 'service_hold_ending')->pluck('subject')->all();
        $this->assertCount(2, $subjects);
        $this->assertTrue(collect($subjects)->contains(fn ($s) => str_contains($s, 'Alpha Hosting')));
        $this->assertTrue(collect($subjects)->contains(fn ($s) => str_contains($s, 'Beta Hosting')));
    }

    public function test_holds_removed_then_readded_to_the_same_date_is_not_told_twice_and_to_another_date_is(): void
    {
        $service = $this->held('2026-11-20');
        $this->cron('2026-11-19 03:00:00');
        $this->assertSame(1, $this->notices());

        $service->update(['suspend_hold_until' => null]);
        $service->update(['suspend_hold_until' => '2026-11-20']);
        $this->cron('2026-11-19 15:00:00');
        $this->assertSame(1, $this->notices(), 'same date, already told');

        $service->update(['suspend_hold_until' => '2026-11-27']);
        $this->cron('2026-11-26 03:00:00');
        $this->assertSame(2, $this->notices(), 'a new date, told again');
    }

    public function test_holds_the_greek_mail_names_the_dates_in_greek(): void
    {
        config(['settings.allowed_languages' => ['en', 'el']]);
        $service = $this->held('2026-11-20');
        $service->client->forceFill(['language' => 'el'])->save();

        $this->cron('2026-11-19 03:00:00');

        $subject = $this->lastNoticeSubject();
        $this->assertStringContainsString('θα ανασταλεί', $subject);
        $this->assertStringContainsString('21 Νοεμβρίου 2026', $subject);
    }

    public function test_holds_the_hold_ending_today_is_told_on_its_last_day(): void
    {
        $service = $this->held('2026-11-19');

        $this->cron('2026-11-19 03:00:00');

        $this->assertSame(1, $this->notices());
        $this->assertStringContainsString('November 20, 2026', $this->lastNoticeSubject());
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_holds_staff_edits_of_the_template_survive_seed_keys(): void
    {
        DB::table('notification_templates')->where('key', 'service_hold_ending')->update(['subject' => 'Staff wrote this']);

        EmailTemplateSeeder::seedKeys(['service_hold_ending']);
        (new EmailTemplateSeeder)->run();

        $this->assertSame(1, DB::table('notification_templates')->where('key', 'service_hold_ending')->count());
        $this->assertSame('Staff wrote this', DB::table('notification_templates')->where('key', 'service_hold_ending')->value('subject'));
    }
}
