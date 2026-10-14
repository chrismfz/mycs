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
 * Saving the admin Settings form (it carries the mail header/footer template fields): the
 * template rule given as a closure made every save throw (devbox, 2026-10-11).
 */
class AdminSettingsSaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_settings_form_saves(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        $this->livewireAs($owner)->test(Settings::class)
            ->set('data.system_email_address', 'billing@example.test') // required, empty in the test seed
            ->set('data.app_language', 'el')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('el', Setting::query()->where('key', 'app_language')->whereNull('settingable_type')->value('value'));
    }

    public function test_a_broken_mail_header_template_is_refused_not_crashed(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        $this->livewireAs($owner)->test(Settings::class)
            ->set('data.mail_header', '{% if app.name %}never closed')
            ->call('save')
            ->assertHasErrors('data.mail_header');
    }
}
