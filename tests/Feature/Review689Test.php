<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Models\CronStat;
use App\Models\EmailLog;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Services\Ops\CronSummary;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Code review of #689: findings proven by tests. */
class Review689Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00'));
    }

    /** A recipient chosen then deactivated (or losing the permission) leaves the select's options: every save of the page fails. */
    public function test_a_stale_recipient_does_not_block_saving_other_settings(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $reader = Staff::factory()->withPermissions(['admin.cron_stats.view'])->create(['email' => 'reader@example.test']);
        $this->storeSettings(['system_email_address' => 'billing@example.test', 'cron_summary_recipients' => [(string) $reader->id]]);
        $reader->update(['active' => false]); // left the company

        $this->livewireAs($owner)->test(Settings::class)
            ->set('data.cronjob_order_suspend', 15)
            ->call('save')
            ->assertHasNoErrors();
    }

    /** Every chosen recipient gone: no mail at all, and no fallback to the System Email Address. */
    public function test_all_chosen_recipients_gone_falls_back_or_says_so(): void
    {
        $reader = Staff::factory()->withPermissions(['admin.cron_stats.view'])->create(['email' => 'reader@example.test']);
        $this->storeSettings(['mail_disable' => false, 'system_email_address' => 'ops@example.test', 'cron_summary_mode' => 'daily',
            'cron_summary_recipients' => [(string) $reader->id]]);
        $reader->update(['active' => false]);
        CronStat::create(['key' => 'invoices_created', 'value' => 1, 'date' => '2026-11-10']);

        $this->artisan('app:cron-summary')->assertExitCode(0);

        $this->assertSame(1, EmailLog::query()->where('template_key', 'system')->count(), 'nobody is told: the summary silently stops');
        $this->assertNotNull(Setting::query()->where('settingable_type', CronStat::class)->where('key', CronSummary::COVERED)->value('value'));
    }
}
