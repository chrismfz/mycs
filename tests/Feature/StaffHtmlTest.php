<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Providers\SettingsProvider;
use App\Services\Money\GatewayReference;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTML staff write shows on this site's origin, where the admin is signed in too (security audit INJ-2, INJ-3): raw HTML
 * is custom code's (its own permission); a product's, a category's or an announcement's is sanitised as it is shown.
 */
class StaffHtmlTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = '<p>Fast <strong>hosting</strong></p><img src="x" onerror="alert(1)"><script>steal()</script><a href="javascript:alert(2)">x</a>';

    public function test_the_home_page_text_needs_the_custom_code_permission(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['settings.system_email_address' => 'billing@example.test']);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Settings', 'permissions' => [
            'admin.settings.view', 'admin.settings.update',
        ]])->id]);

        $this->livewireAs($staff)->test(Settings::class)->set('data.theme_home_page_text', '<script>steal()</script>')->call('save');

        $this->assertStringNotContainsString('steal()', (string) Setting::query()->whereNull('settingable_type')->where('key', 'theme_home_page_text')->value('value'));
    }

    public function test_a_products_description_is_shown_without_script(): void
    {
        $product = $this->createProduct();
        $product->product->update(['description' => self::SCRIPT]);
        $product->product->category->update(['description' => self::SCRIPT]);

        foreach ([route('products.show', [$product->product->category->slug, $product->product->slug]), route('category.show', [$product->product->category->slug])] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('<strong>hosting</strong>', (string) $html, $url);
            $this->assertStringNotContainsString('onerror', (string) $html, $url);
            $this->assertStringNotContainsString('steal()', (string) $html, $url);
            $this->assertStringNotContainsString('javascript:alert(2)', (string) $html, $url);
        }
    }

    public function test_the_gateway_reference_setting_is_the_mark(): void
    {
        Setting::query()->updateOrCreate(['key' => 'gateway_reference', 'settingable_type' => null, 'settingable_id' => null], ['value' => 'nixpal', 'type' => 'string']);
        SettingsProvider::flushCache();
        SettingsProvider::reload();

        $this->assertSame('nixpal', GatewayReference::mark());
    }
}
