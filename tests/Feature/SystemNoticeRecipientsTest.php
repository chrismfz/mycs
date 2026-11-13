<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings as SettingsPage;
use App\Classes\Settings;
use App\Helpers\NotificationHelper;
use App\Models\EmailLog;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Services\Ops\CronSummary;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * System notices to the staff chosen in Settings (owner 2026-11-13), each to their own address; none chosen, or none of
 * them active, the System Email Address as before.
 */
class SystemNoticeRecipientsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->storeSettings(['mail_disable' => false, 'system_email_address' => 'ops@example.test']);
    }

    /** @return list<string> */
    private function sentTo(): array
    {
        return EmailLog::query()->where('template_key', 'system')->orderBy('id')->pluck('to')->all();
    }

    public function test_none_chosen_goes_to_the_system_email_address(): void
    {
        NotificationHelper::sendSystemEmailNotification('Cron Job Error', 'body');

        $this->assertSame(['ops@example.test'], $this->sentTo());
    }

    public function test_it_goes_to_each_active_staff_chosen_and_not_to_the_system_address(): void
    {
        $ann = Staff::factory()->withPermissions(['admin.invoices.view'])->create(['email' => 'ann@example.test']);
        $bob = Staff::factory()->admin()->create(['email' => 'bob@example.test']);
        $gone = Staff::factory()->admin()->create(['email' => 'gone@example.test', 'active' => false]);
        Staff::factory()->admin()->create(['email' => 'notchosen@example.test']);
        $this->storeSettings(['system_notice_recipients' => [(string) $ann->id, (string) $bob->id, (string) $gone->id, '999999']]);

        NotificationHelper::sendSystemEmailNotification('Payment needs attention', 'body');

        $this->assertSame(['ann@example.test', 'bob@example.test'], $this->sentTo());
        Mail::assertQueuedCount(2);
    }

    public function test_every_one_chosen_gone_falls_back_to_the_system_address(): void
    {
        $gone = Staff::factory()->admin()->create(['email' => 'gone@example.test', 'active' => false]);
        $this->storeSettings(['system_notice_recipients' => [(string) $gone->id]]);

        NotificationHelper::sendSystemEmailNotification('Cron Job Error', 'body');

        $this->assertSame(['ops@example.test'], $this->sentTo());
    }

    public function test_a_given_address_is_kept_and_mail_off_sends_nothing(): void
    {
        $ann = Staff::factory()->admin()->create(['email' => 'ann@example.test']);
        $this->storeSettings(['system_notice_recipients' => [(string) $ann->id]]);

        NotificationHelper::sendSystemEmailNotification('New ticket', 'body', email: 'dept@example.test');
        $this->assertSame(['dept@example.test'], $this->sentTo());

        $this->storeSettings(['mail_disable' => true]);
        NotificationHelper::sendSystemEmailNotification('Cron Job Error', 'body');
        $this->assertSame(['dept@example.test'], $this->sentTo());
    }

    public function test_the_daily_summary_with_no_one_chosen_follows_the_system_notices_who_may_see_cron_stats(): void
    {
        $ann = Staff::factory()->admin()->create(['email' => 'ann@example.test']);
        $billing = Staff::factory()->withPermissions(['admin.invoices.view'])->create(['email' => 'billing@example.test']);
        $this->storeSettings(['system_notice_recipients' => [(string) $ann->id, (string) $billing->id]]);
        $this->assertSame(['ann@example.test'], CronSummary::recipients());

        // Only staff without Cron stats get the system notices: the summary goes to the System Email Address.
        $this->storeSettings(['system_notice_recipients' => [(string) $billing->id]]);
        $this->assertSame(['ops@example.test'], CronSummary::recipients());
        $this->storeSettings(['system_notice_recipients' => [(string) $ann->id]]);

        // Its own choice wins.
        $may = Staff::factory()->withPermissions(['admin.cron_stats.view'])->create(['email' => 'may@example.test']);
        $this->storeSettings(['cron_summary_recipients' => [(string) $may->id]]);
        $this->assertSame(['may@example.test'], CronSummary::recipients());
    }

    public function test_the_field_lists_active_staff_and_marks_one_chosen_who_left(): void
    {
        $ann = Staff::factory()->withPermissions(['admin.invoices.view'])->create(['email' => 'ann@example.test']);
        $gone = Staff::factory()->admin()->create(['email' => 'gone@example.test', 'active' => false]);
        $this->storeSettings(['system_notice_recipients' => [(string) $gone->id]]);

        $options = Settings::systemNoticeRecipientOptions();
        $this->assertArrayHasKey($ann->id, $options);
        $noMail = Staff::factory()->admin()->create(['email' => '']);
        $this->assertArrayNotHasKey($noMail->id, Settings::systemNoticeRecipientOptions());
        $this->assertStringContainsString(__('not sent to: inactive or no access'), $options[$gone->id]);
    }

    public function test_the_settings_page_saves_the_choice(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->storeSettings(['mail_disable' => true]); // or the page asks for the SMTP server's details
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id, 'email' => 'owner@example.test']);

        $this->livewireAs($owner)->test(SettingsPage::class)
            ->set('data.system_notice_recipients', [(string) $owner->id])
            ->call('save')
            ->assertHasNoErrors();

        $stored = Setting::query()->where('key', 'system_notice_recipients')->whereNull('settingable_type')->value('value');
        $this->assertSame([(string) $owner->id], array_map('strval', (array) json_decode((string) $stored, true)));
        $this->storeSettings(['mail_disable' => false]);
        NotificationHelper::sendSystemEmailNotification('Cron Job Error', 'body');
        $this->assertSame(['owner@example.test'], $this->sentTo());
    }
}
