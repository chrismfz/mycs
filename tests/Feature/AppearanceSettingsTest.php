<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Support\Facades\FilamentView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The theme system went: one set of client views in resources/views, its settings in the Appearance tab (theme_<name>),
 * and staff-written CSS/HTML for a company's own header, footer and styles instead of edits to the views.
 */
class AppearanceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']); // empty in the test seed: the menu's currency switch needs one
    }

    public function test_the_active_themes_settings_lose_the_theme_name_and_the_theme_choice_goes(): void
    {
        $migration = require database_path('migrations/2026_10_25_000000_theme_settings_without_theme_name.php');
        // As before: the seeded defaults under theme_default_<name>, and the theme choice.
        DB::table('settings')->where('key', 'like', 'theme%')->delete();
        DB::table('settings')->insert([
            ['key' => 'theme', 'value' => 'default', 'type' => 'string'],
            ['key' => 'theme_default_primary', 'value' => 'hsl(10, 50%, 50%)', 'type' => 'string'],
            ['key' => 'theme_default_direct_checkout', 'value' => '1', 'type' => 'boolean'],
            ['key' => 'theme_other_primary', 'value' => 'hsl(1, 1%, 1%)', 'type' => 'string'],
        ]);

        $migration->up();

        $keys = DB::table('settings')->whereNull('settingable_type')->pluck('value', 'key');
        $this->assertSame('hsl(10, 50%, 50%)', $keys['theme_primary']);
        $this->assertSame('1', $keys['theme_direct_checkout']);
        $this->assertFalse($keys->has('theme'));
        $this->assertFalse($keys->has('theme_default_primary'));
        $this->assertTrue($keys->has('theme_other_primary'), 'another theme\'s settings are left alone');
    }

    public function test_theme_reads_the_appearance_settings(): void
    {
        config(['settings.theme_primary' => 'hsl(1, 2%, 3%)']);

        $this->assertSame('hsl(1, 2%, 3%)', theme('primary'));
        $this->assertSame('fallback', theme('nothing_here', 'fallback'));
    }

    public function test_the_client_area_has_the_custom_css_head_header_and_footer(): void
    {
        config([
            'settings.theme_custom_css' => 'body { color: red } </style><script>alert(1)</script>',
            'settings.theme_custom_head' => '<meta name="x-custom-head" content="1">',
            'settings.theme_custom_header' => '<div id="company-menu">Company menu</div>',
            'settings.theme_custom_footer' => '<footer id="company-footer">Company footer</footer>',
        ]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('body { color: red } <\/style><script>', $html, 'the CSS cannot close its <style>');
        $this->assertStringNotContainsString('</style><script>alert(1)', $html);
        $this->assertStringContainsString('<meta name="x-custom-head" content="1">', $html);
        $this->assertStringContainsString('<div id="company-menu">Company menu</div>', $html);
        $this->assertLessThan(strpos($html, '<nav'), strpos($html, 'company-menu'), 'the header comes before the menu');
        $this->assertStringContainsString('<footer id="company-footer">Company footer</footer>', $html);
        $this->assertStringNotContainsString('All rights reserved', $html, 'the custom footer replaces the built-in one');
    }

    public function test_without_custom_code_the_client_area_looks_as_before(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('All rights reserved', $html);
        $this->assertStringContainsString('--color-primary', $html);
        $this->assertStringNotContainsString('<style></style>', $html);
    }

    public function test_staff_html_is_not_compiled_as_blade(): void
    {
        config(['settings.theme_custom_footer' => '<p>{{ 1 + 1 }} @php echo "x"; @endphp</p>']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<p>{{ 1 + 1 }} @php echo "x"; @endphp</p>', $html);
    }

    public function test_the_appearance_tab_shows_and_saves(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = User::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        config(['settings.system_email_address' => 'billing@example.test']); // required, empty in the test seed

        Livewire::actingAs($owner)->test(Settings::class)
            ->assertSee('Appearance')
            ->assertSee('Custom CSS')
            ->assertSee('Colors (dark mode)')
            ->assertDontSee('Theme</span>', false)
            ->set('data.theme_custom_css', '.x { color: blue }')
            ->set('data.theme_primary', 'hsl(10, 50%, 50%)')
            ->call('save')
            ->assertHasNoErrors();

        $stored = Setting::query()->whereNull('settingable_type')->pluck('value', 'key');
        $this->assertSame('.x { color: blue }', $stored['theme_custom_css']);
        $this->assertSame('hsl(10, 50%, 50%)', $stored['theme_primary']);
    }

    public function test_the_admin_panel_uses_the_same_colors(): void
    {
        config(['settings.theme_primary' => 'hsl(10, 50%, 50%)']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot(); // registers its render hooks, as when it serves a request

        $head = FilamentView::renderHook('panels::head.end')->toHtml(); // AdminPanelProvider's hook

        $this->assertStringContainsString('--color-primary: 10 50% 50%', $head);
    }

    /** Review S-1: custom code runs in the client area, same origin as /admin: settings.update alone does not reach it. */
    public function test_custom_code_and_files_need_their_own_permission(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['settings.system_email_address' => 'billing@example.test']);
        $staff = User::factory()->create(['role_id' => Role::create(['name' => 'Settings', 'permissions' => [
            'admin.settings.view', 'admin.settings.update',
        ]])->id]);

        $page = Livewire::actingAs($staff)->test(Settings::class);
        $field = collect($page->instance()->form->getFlatComponents(withHidden: true))
            ->first(fn ($component) => $component instanceof Field && $component->getName() === 'theme_custom_header');
        $this->assertTrue($field->isDisabled());

        // Sent anyway (a crafted request): a disabled field is not taken from the page, nothing stored (save() also
        // refuses one that would get through).
        $page->set('data.theme_custom_header', '<script>steal()</script>')->call('save');
        $this->assertNull(Setting::query()->whereNull('settingable_type')->where('key', 'theme_custom_header')->value('value') ?: null);

        // The rest of the tab stays theirs.
        Livewire::actingAs($staff)->test(Settings::class)->set('data.theme_primary', 'hsl(10, 50%, 50%)')->call('save')->assertHasNoErrors();
        $this->assertSame('hsl(10, 50%, 50%)', Setting::query()->whereNull('settingable_type')->where('key', 'theme_primary')->value('value'));
    }

    /** Review I-2: the value column is TEXT (64 KB on MariaDB): a validation message, not a database error. */
    public function test_custom_code_is_limited_to_what_the_column_holds(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['settings.system_email_address' => 'billing@example.test']);
        $owner = User::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        Livewire::actingAs($owner)->test(Settings::class)
            ->set('data.theme_custom_css', str_repeat('a', 65001))
            ->call('save')
            ->assertHasErrors(['data.theme_custom_css']);
    }
}
