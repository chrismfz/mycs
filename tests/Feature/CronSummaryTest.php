<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings as SettingsPage;
use App\Classes\Settings;
use App\Models\ChargeAttempt;
use App\Models\CronStat;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Ops\CronSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The daily summary email (owner 2026-11-06, ROADMAP 9β-4): off by default; every day or only when something failed;
 * to the staff chosen; working days only, Monday's covering the weekend.
 */
class CronSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00')); // a Wednesday
        $this->storeSettings(['mail_disable' => false, 'system_email_address' => 'ops@example.test', 'cronjob_order_suspend' => 2, 'cronjob_order_terminate' => 14]);
    }

    /** The cron ran that day (its own counts). */
    private function ran(string $day, int $invoices = 0): void
    {
        CronStat::create(['key' => 'invoices_created', 'value' => $invoices, 'date' => $day]);
        CronStat::create(['key' => 'invoice_charged', 'value' => 0, 'date' => $day]);
    }

    /** @return Collection<int, EmailLog> */
    private function sent()
    {
        return EmailLog::query()->where('template_key', 'system')->orderBy('id')->get();
    }

    private function covered(): ?string
    {
        return Setting::query()->where('settingable_type', CronStat::class)->where('key', CronSummary::COVERED)->value('value');
    }

    public function test_it_is_off_by_default(): void
    {
        $this->ran('2026-11-10');
        $this->artisan('app:cron-summary')->expectsOutputToContain('Off')->assertExitCode(0);
        $this->assertCount(0, $this->sent());
        $this->assertNull($this->covered());
    }

    public function test_every_day_it_sends_yesterday_once(): void
    {
        $this->storeSettings(['cron_summary_mode' => 'daily']);
        $this->ran('2026-11-10', invoices: 4);

        $this->artisan('app:cron-summary')->assertExitCode(0);
        $mails = $this->sent();
        $this->assertCount(1, $mails);
        $this->assertSame('ops@example.test', $mails[0]->to);
        $this->assertStringContainsString('4 invoices', $mails[0]->body);
        $this->assertStringNotContainsString('something to look at', $mails[0]->subject);
        $this->assertSame('2026-11-10', $this->covered());

        // The same day again (a re-run of the scheduler): not twice.
        $this->artisan('app:cron-summary')->expectsOutputToContain('Already sent')->assertExitCode(0);
        $this->assertCount(1, $this->sent());

        // The next day: that day only.
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00'));
        $this->ran('2026-11-11', invoices: 7);
        $this->artisan('app:cron-summary')->assertExitCode(0);
        $this->assertCount(2, $this->sent());
        $this->assertStringContainsString('7 invoices', $this->sent()[1]->body);
        $this->assertStringNotContainsString('4 invoices', $this->sent()[1]->body);
    }

    public function test_only_when_something_failed(): void
    {
        $this->storeSettings(['cron_summary_mode' => 'failures']);
        $this->ran('2026-11-10');

        $this->artisan('app:cron-summary')->expectsOutputToContain('Nothing failed')->assertExitCode(0);
        $this->assertCount(0, $this->sent());
        $this->assertSame('2026-11-10', $this->covered(), 'covered all the same: tomorrow does not send it');

        // A day the cron did not run.
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00'));
        $this->artisan('app:cron-summary')->assertExitCode(0);
        $this->assertCount(1, $this->sent());
        $this->assertStringContainsString('something to look at', $this->sent()[0]->subject);
        $this->assertStringContainsString('the cron did not run', $this->sent()[0]->body);

        // A card the cron could not charge.
        Carbon::setTestNow(Carbon::parse('2026-11-13 10:00'));
        $this->ran('2026-11-12');
        $client = User::factory()->create()->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'currency_code' => 'USD']);
        $attempt = ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'client_id' => $client->id,
            'amount' => '12.00', 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON, 'status' => ChargeAttempt::STATUS_FAILED,
            'error' => 'Your card was declined.', 'started_at' => now(), 'sent_at' => now()]);
        $attempt->forceFill(['created_at' => '2026-11-12 09:05'])->save();
        $this->artisan('app:cron-summary')->assertExitCode(0);
        $this->assertCount(2, $this->sent());
        $this->assertStringContainsString('Your card was declined.', $this->sent()[1]->body);
        $this->assertStringContainsString('12.00 USD', $this->sent()[1]->body);
    }

    public function test_working_days_only_monday_covers_the_weekend(): void
    {
        $this->storeSettings(['cron_summary_mode' => 'daily', 'cron_summary_workdays' => true]);
        foreach (['2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15'] as $day) {
            $this->ran($day);
        }
        Carbon::setTestNow(Carbon::parse('2026-11-13 10:00')); // Friday: Thursday
        $this->artisan('app:cron-summary')->assertExitCode(0);
        $this->assertSame('2026-11-12', $this->covered());

        foreach (['2026-11-14 10:00', '2026-11-15 10:00'] as $weekend) {
            Carbon::setTestNow(Carbon::parse($weekend));
            $this->artisan('app:cron-summary')->expectsOutputToContain('weekend')->assertExitCode(0);
        }
        $this->assertCount(1, $this->sent());

        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00')); // Monday: Friday, Saturday, Sunday
        $this->artisan('app:cron-summary')->assertExitCode(0);
        $monday = $this->sent()[1];
        $this->assertStringContainsString('13/11', $monday->subject);
        $this->assertStringContainsString('15/11', $monday->subject);
        $this->assertSame('2026-11-15', $this->covered());
    }

    public function test_at_most_a_week_after_a_long_gap(): void
    {
        $this->storeSettings(['cron_summary_mode' => 'daily']);
        Setting::create(['key' => CronSummary::COVERED, 'settingable_type' => CronStat::class, 'value' => '2026-10-01', 'type' => 'string']);

        [$from, $to] = CronSummary::period(Carbon::today());
        $this->assertSame('2026-11-04', $from->toDateString());
        $this->assertSame('2026-11-10', $to->toDateString());
    }

    public function test_it_goes_to_the_staff_chosen_who_may_see_cron_stats(): void
    {
        $this->storeSettings(['cron_summary_mode' => 'daily']);
        $this->ran('2026-11-10');
        $may = Staff::factory()->withPermissions(['admin.cron_stats.view'])->create(['email' => 'may@example.test']);
        $admin = Staff::factory()->admin()->create(['email' => 'admin@example.test']);
        $mayNot = Staff::factory()->withPermissions(['admin.invoices.view'])->create(['email' => 'maynot@example.test']);
        $gone = Staff::factory()->admin()->create(['email' => 'gone@example.test', 'active' => false]);

        $options = Settings::summaryRecipientOptions();
        $this->assertArrayHasKey((string) $may->id, $options);
        $this->assertArrayHasKey((string) $admin->id, $options);
        $this->assertArrayNotHasKey((string) $mayNot->id, $options);
        $this->assertArrayNotHasKey((string) $gone->id, $options);

        // Chosen before one lost the permission or left: not sent to them.
        $this->storeSettings(['cron_summary_recipients' => [(string) $may->id, (string) $admin->id, (string) $mayNot->id, (string) $gone->id]]);
        $this->artisan('app:cron-summary')->assertExitCode(0);
        $this->assertSame(['may@example.test', 'admin@example.test'], $this->sent()->pluck('to')->all());
    }

    public function test_it_lists_what_is_suspended_next(): void
    {
        $this->storeSettings(['cron_summary_mode' => 'daily']);
        $this->ran('2026-11-10');
        $product = $this->createProduct();
        $client = User::factory()->create()->client();
        $service = Service::factory()->create([
            'client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id, 'status' => 'active',
            'expires_at' => now()->startOfDay()->subDay()->addHours(12), 'currency_code' => 'USD', 'price' => 10.00,
        ]);

        $this->artisan('app:cron-summary')->assertExitCode(0);
        $body = $this->sent()[0]->body;
        $this->assertStringContainsString('#' . $service->id . '</a>', $body);
        $this->assertStringContainsString(e($client->name), $body);
        $this->assertStringContainsString('1 service due', $this->sent()[0]->subject);
    }

    public function test_force_sends_now_and_leaves_the_days_to_be_covered(): void
    {
        $this->ran('2026-11-10');
        $this->artisan('app:cron-summary', ['--force' => true])->expectsOutputToContain('Sent to ops@example.test')->assertExitCode(0);
        $this->assertCount(1, $this->sent());
        $this->assertNull($this->covered());
    }

    public function test_the_settings_page_shows_the_staff_to_choose(): void
    {
        $staff = Staff::factory()->admin()->create(['first_name' => 'Summary', 'last_name' => 'Reader']);
        $this->actingAs($staff);

        Livewire::test(SettingsPage::class)->assertSee('Daily Summary Email')->assertSee('Summary Reader');
    }

    public function test_monday_first_time_with_working_days_only_covers_friday_to_sunday(): void
    {
        $this->storeSettings(['cron_summary_mode' => 'daily', 'cron_summary_workdays' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00')); // Monday, no summary before
        [$from, $to] = CronSummary::period(Carbon::today());
        $this->assertSame(['2026-11-13', '2026-11-15'], [$from->toDateString(), $to->toDateString()]);
    }

    public function test_a_module_action_left_open_is_a_failure(): void
    {
        $this->storeSettings(['cron_summary_mode' => 'failures']);
        $this->ran('2026-11-10');
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 10.00]);
        $action = ModuleAction::query()->create(['service_id' => $service->id, 'action' => 'suspend', 'status' => ModuleAction::STATUS_OPEN]);
        $action->forceFill(['created_at' => '2026-11-10 09:10', 'last_error' => 'cPanel said no'])->save();

        $this->artisan('app:cron-summary')->assertExitCode(0);
        $this->assertCount(1, $this->sent());
        $this->assertStringContainsString('cPanel said no', $this->sent()[0]->body);
    }

    public function test_a_day_with_only_the_hourly_run_did_not_have_its_daily_run(): void
    {
        $this->storeSettings(['cron_summary_mode' => 'failures']);
        CronStat::create(['key' => 'services_suspended', 'value' => 2, 'date' => '2026-11-10']); // the hourly run's

        $this->artisan('app:cron-summary')->assertExitCode(0);
        $this->assertCount(1, $this->sent());
        $this->assertStringContainsString('the cron did not run', $this->sent()[0]->body);
    }

    public function test_with_mail_disabled_the_days_stay_to_be_covered(): void
    {
        $this->storeSettings(['cron_summary_mode' => 'daily', 'mail_disable' => true]);
        $this->ran('2026-11-10');
        $this->artisan('app:cron-summary')->expectsOutputToContain('Mail is disabled')->assertExitCode(0);
        $this->assertNull($this->covered());
    }

    public function test_terminations_off_alone_is_told_apart_from_the_safety_net(): void
    {
        $this->storeSettings(['cron_summary_mode' => 'daily', 'cronjob_terminate_enabled' => false]);
        $this->ran('2026-11-10');
        $this->artisan('app:cron-summary')->assertExitCode(0);
        $body = $this->sent()[0]->body;
        $this->assertStringContainsString('Terminations are off', $body);
        $this->assertStringNotContainsString('Waiting (safety net)', $body);
        $this->assertStringContainsString('Due now', $body);
    }
}
