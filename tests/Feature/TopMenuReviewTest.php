<?php

namespace Tests\Feature;

use App\Admin\TopMenu;
use App\Models\Role;
use App\Models\Staff;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * #276 review: «Addons» lists every page the panel registers that MENU does not name, but ignores the page's own
 * shouldRegisterNavigation(). An extension page hidden from navigation on purpose (a wizard, a page reached from a
 * record, one shown only under a setting, as core's Error/HTTP Logs are) shows up in the top menu anyway, and an
 * extension has no way to keep it out (NOT_IN_MENU and DEBUG_ONLY are core's lists).
 */
class TopMenuReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_extension_page_hidden_from_navigation_is_not_listed_under_addons(): void
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        $panel->pages([...$panel->getPages(), TopMenuReviewHiddenWizard::class]);
        Route::get('admin/review-hidden-wizard', fn () => 'ok')->name('filament.admin.pages.review-hidden-wizard');
        Route::getRoutes()->refreshNameLookups();
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');

        $this->assertContains(TopMenuReviewHiddenWizard::class, TopMenu::unlisted()); // it is the Addons' candidate
        $labels = collect(TopMenu::groups())->flatMap(fn (array $group) => array_column($group['items'], 'label'));

        $this->assertNotContains('Review Hidden Wizard', $labels, 'a page that asks to stay out of navigation is in «Addons»');
    }
}

class TopMenuReviewHiddenWizard extends Page
{
    protected static ?string $slug = 'review-hidden-wizard';

    protected static ?string $navigationLabel = 'Review Hidden Wizard';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
}
