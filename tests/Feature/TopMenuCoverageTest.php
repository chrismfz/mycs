<?php

namespace Tests\Feature;

use App\Admin\TopMenu;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The top menu is the whole map of the admin (owner 2026-10-21: «αρκεί να μην ξεχάσουμε κάποια σελίδα»): every page
 * the panel registers has a line in TopMenu::MENU or a reason in NOT_IN_MENU, and anything else (an extension's) shows
 * under «Addons». A new page without its line fails here.
 */
class TopMenuCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
    }

    public function test_every_core_page_has_its_place_in_the_top_menu(): void
    {
        $unlisted = array_filter(TopMenu::unlisted(), fn (string $class) => str_starts_with($class, 'App\\'));

        $this->assertSame([], array_values($unlisted), 'Give each a line in TopMenu::MENU (or a reason in NOT_IN_MENU).');
    }

    public function test_every_line_of_the_menu_opens_for_staff_who_may_see_everything(): void
    {
        // Pages shown only with these on. Stored as Settings → …: each request below loads them again from the database,
        // so a config() of the test alone is gone by then (a 403, and the error page's 500 without a currency).
        foreach (['debug' => [true, 'boolean'], 'tax_enabled' => [true, 'boolean'], 'immutable_invoices_enabled' => [true, 'boolean'],
            'default_currency' => ['USD', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        $urls = collect(TopMenu::groups())->flatMap(fn (array $group) => array_column($group['items'], 'url'));
        $lines = collect(TopMenu::MENU)->flatten(1)->count();

        $shown = collect(TopMenu::groups())->flatMap(fn (array $group) => array_column($group['items'], 'label'))->all();
        $missing = collect(TopMenu::MENU)->flatten(1)->map(fn (array $entry) => $entry[2] ?? $entry[0]::getNavigationLabel())->reject(fn ($label) => in_array($label, $shown, true))->values()->all();
        $this->assertCount($lines, $urls, 'not shown for a staff member with every permission: ' . implode(', ', $missing));
        foreach ($urls->unique() as $url) {
            $this->get($url)->assertOk();
        }
    }

    /** An extension's resource, unknown to MENU, is what «Addons» lists (its routes come with the extension). */
    public function test_an_extensions_page_is_left_for_addons(): void
    {
        $panel = Filament::getPanel('admin');
        $extension = 'MyCS\\Extensions\\Others\\Announcements\\Admin\\Resources\\AnnouncementResource';
        if (!class_exists($extension)) {
            $this->markTestSkipped('the Announcements extension is not in this tree');
        }
        $panel->resources([...$panel->getResources(), $extension]);

        $this->assertContains($extension, TopMenu::unlisted());
    }

    public function test_the_debug_logs_show_only_while_debugging(): void
    {
        config(['settings.debug' => false]);
        $labels = collect(TopMenu::groups())->flatMap(fn (array $group) => array_column($group['items'], 'label'));

        $this->assertNotContains('Error Logs', $labels);
        $this->assertContains('Failed Jobs', $labels);
    }
}
