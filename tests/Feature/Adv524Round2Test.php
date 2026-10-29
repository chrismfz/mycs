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
 * Adversarial review of #524, round 2 (ac2270a1).
 */
class Adv524Round2Test extends TestCase
{
    use RefreshDatabase;

    /**
     * ADV-524-R2-1: on deploy, an instance with tax on has no tax_home_country stored (no migration fills it). The
     * Settings page is one form over every tab, so the new required field refuses every save: nixpal (home EE already
     * set under EU VAT, which configuredHomeCountry() falls back to) cannot change its language, mail or counters
     * until someone finds the Tax tab.
     */
    public function test_adv_524_r2_1_an_unrelated_setting_saves_on_an_install_with_tax_on_and_no_company_country(): void
    {
        config(['settings.tax_enabled' => true, 'settings.eu_vat_enabled' => true, 'settings.eu_vat_home_country' => 'EE', 'settings.tax_home_country' => null]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        $this->livewireAs($owner)->test(Settings::class)
            ->set('data.system_email_address', 'billing@example.test') // required, empty in the test seed
            ->set('data.app_language', 'el')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('el', Setting::query()->where('key', 'app_language')->whereNull('settingable_type')->value('value'));
    }
}
