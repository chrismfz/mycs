<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Models\Role;
use App\Models\Staff;
use App\Providers\SettingsProvider;
use App\Services\Money\GatewayReference;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #330. Staff with settings.update but without custom_code.
 */
class Adv330StaffSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function settingsStaff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['settings.system_email_address' => 'billing@example.test']);

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Settings', 'permissions' => [
            'admin.settings.view', 'admin.settings.update',
        ]])->id]);
    }

    private function save(Staff $staff, string $key, string $value): void
    {
        try {
            $this->livewireAs($staff)->test(Settings::class)->set('data.' . $key, $value)->call('save');
        } catch (\Throwable) {
            // refused: fine, the assertions below read what is stored
        }
        SettingsProvider::flushCache();
        SettingsProvider::reload();
    }

    /**
     * ADV330-1: the Terms of Service setting has no validation and is printed as an <a href> on the register page and
     * in the cart (client-area origin, same as the admin). A `javascript:` URL is script on that origin for staff
     * without custom_code.
     */
    public function test_the_terms_of_service_link_is_never_a_javascript_url(): void
    {
        $this->save($this->settingsStaff(), 'tos', 'javascript:fetch(\'/admin\').then(r=>r.text()).then(t=>alert(t))');

        $html = (string) $this->get(route('register'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('~href="\s*javascript:~i', $html);
    }

    /**
     * ADV330-2: `-` (or `--`) passes the gateway_reference regex but slugs to an empty mark. Stripe drops an empty
     * metadata value and isOurs('') is false: every Stripe and Mollie payment started afterwards comes back «foreign»,
     * the customer is charged and nothing is recorded.
     */
    public function test_a_gateway_reference_of_dashes_never_gives_an_empty_mark(): void
    {
        $this->save($this->settingsStaff(), 'gateway_reference', '--');

        $mark = GatewayReference::mark();
        $this->assertNotSame('', $mark);
        $this->assertTrue(GatewayReference::isOurs($mark), 'a payment this site starts must be known as its own');
    }
}
