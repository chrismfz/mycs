<?php

namespace Tests\Feature;

use App\Admin\Pages\Dashboard;
use App\Admin\Widgets\ActiveUsers;
use App\Admin\Widgets\EstimatedIncome;
use App\Admin\Widgets\Overview;
use App\Admin\Widgets\Renewals\DomainRenewals;
use App\Admin\Widgets\Renewals\ServiceRenewals;
use App\Admin\Widgets\Revenue;
use App\Admin\Widgets\StaffOnline;
use App\Admin\Widgets\Support;
use App\Models\Currency;
use App\Models\Role;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Independent review of #279 (the dashboard as WHMCS's: five cards, a shorter chart), and its layout in two columns (2026-11-04). */
class DashboardLayoutReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'prefix' => '€', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function actAs(array $permissions): void
    {
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'R', 'permissions' => $permissions])->id]), 'staff');
    }

    /**
     * R1. Two columns that stack on their own (2026-11-04): the money on the left, the work on the right, the cards and
     * bars above and staff online / active users below. Staff without admin.reports.view have no income: Revenue
     * alone, and the work beside it.
     */
    public function test_the_money_is_one_column_and_the_work_the_other(): void
    {
        $this->actAs(['*']);

        $layout = (new Dashboard)->layout();

        $this->assertSame([Overview::class], $layout['above']);
        $this->assertSame([Revenue::class, EstimatedIncome::class], $layout['left']);
        $this->assertSame([Support::class, ServiceRenewals::class, DomainRenewals::class], $layout['right']);
        $this->assertSame([StaffOnline::class, ActiveUsers::class], $layout['below']);
    }

    /** A column nobody can see is not left empty beside the other: the page does not render a hole. */
    public function test_a_staff_member_with_only_the_work_gets_one_column(): void
    {
        $this->actAs(['admin.widgets.support', 'admin.widgets.renewals', 'admin.domains.viewAny', 'admin.services.viewAny']);

        $layout = (new Dashboard)->layout();

        $this->assertSame([], $layout['left']);
        $this->assertNotContains(Support::class, $layout['above']);
        $this->assertContains(DomainRenewals::class, $layout['right']);
        $html = $this->get('/sysadmin')->assertOk()->getContent();
        $this->assertStringContainsString('--cols-lg: repeat(1, minmax(0, 1fr))', $html);
        $this->assertStringNotContainsString('--cols-default: repeat(1, minmax(0, 1fr)); --cols-lg: repeat(2', $html, 'no half-width column beside nothing');
    }

    /** The page renders the two columns: the left one's widgets come before the right one's in the document. */
    public function test_the_page_renders_the_columns_in_order(): void
    {
        $this->actAs(['*']);

        $html = $this->get('/sysadmin')->assertOk()->getContent();
        $at = fn (string $widget): int => (int) strpos($html, "content.{$widget}-");

        $order = [Overview::class, Revenue::class, EstimatedIncome::class, Support::class, ServiceRenewals::class, DomainRenewals::class, StaffOnline::class, ActiveUsers::class];
        $offsets = array_map($at, $order);
        $this->assertNotContains(0, $offsets, 'every widget is on the page');
        $sorted = $offsets;
        sort($sorted);
        $this->assertSame($sorted, $offsets, 'left column, then right column, then the full width below');
        $this->assertStringContainsString('--cols-default: repeat(1, minmax(0, 1fr)); --cols-lg: repeat(2', $html, 'two columns from lg');
    }

    /**
     * R2. Staff without the orders' or the services' permission get 3 cards; the grid stays 5 wide at xl, so the row
     * is 60% filled and 40% empty (Filament's own getColumns() follows the count).
     */
    public function test_three_cards_do_not_sit_in_a_five_column_row(): void
    {
        $this->actAs(['admin.widgets.overview']);
        $widget = Livewire::test(Overview::class)->instance();
        $stats = (fn () => $this->getStats())->call($widget);
        $columns = (fn () => $this->getColumns())->call($widget);

        $this->assertCount(3, $stats);
        $this->assertLessThanOrEqual(count($stats), is_array($columns) ? max($columns) : $columns, 'as many columns as cards');
    }
}
