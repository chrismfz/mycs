<?php

namespace Tests\Feature;

use App\Admin\Pages\Dashboard;
use App\Admin\Widgets\ActiveUsers;
use App\Admin\Widgets\EstimatedIncome;
use App\Admin\Widgets\Overview;
use App\Admin\Widgets\Renewals\DomainRenewals;
use App\Models\Currency;
use App\Models\Role;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Independent review of #279 (the dashboard as WHMCS's: five cards, a shorter chart, the four lists two by two). */
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
     * R1. The lists are half width only from lg, where the dashboard's grid has 2 columns, and they pair only if they
     * start a row. With all permissions, EstimatedIncome (full width, sort 12) starts one. Staff without
     * admin.reports.view do not see it, so Revenue | Support, then ActiveUsers alone: Domain renewals sits beside
     * Active users, Service renewals drops to the next row (with a «to check» list, or alone).
     */
    public function test_the_renewal_lists_start_a_row_for_staff_without_reports(): void
    {
        $this->actAs(['admin.widgets.revenue', 'admin.widgets.overview', 'admin.widgets.support', 'admin.widgets.active_users',
            'admin.widgets.renewals', 'admin.domains.viewAny', 'admin.services.viewAny']);

        $placed = $this->placed();

        $this->assertSame(0, $placed[DomainRenewals::class], 'Domain renewals starts its row, not beside the widget before it: ' . json_encode($placed));
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

    /** @return array<class-string, int> each visible widget's column at lg, as CSS grid places them (row by row). */
    private function placed(): array
    {
        // CSS grid auto-placement at lg, as Filament's Grid::make(2) lays the widgets out.
        $columns = (new Dashboard)->getColumns();
        $this->assertSame(2, $columns);
        $column = 0;
        $placed = [];
        foreach (Filament::getWidgets() as $widget) {
            if (!$widget::canView()) {
                continue;
            }
            $span = (fn () => $this->columnSpan)->call(new $widget);
            $span = is_array($span) ? ($span['lg'] ?? $span['md'] ?? $span['sm'] ?? $span['default'] ?? 1) : $span;
            if ($span === 'full' || $span >= $columns) {
                $placed[$widget] = 0;
                $column = 0;

                continue;
            }
            // A widget told to start at column 1 (columnStart, the fix) goes to the next row when the cursor is past it,
            // as CSS grid's auto-placement does.
            $start = (fn () => $this->columnStart)->call(new $widget);
            $start = is_array($start) ? ($start['lg'] ?? $start['md'] ?? $start['default'] ?? null) : $start;
            if ($start === 1 && $column !== 0) {
                $column = 0;
            }
            $placed[$widget] = $column;
            $column = ($column + 1) % $columns;
        }

        return $placed;
    }

    /** Everything seen (income too): domain renewals fill the room beside Income (owner 2026-10-21). */
    public function test_domain_renewals_sit_beside_income(): void
    {
        $this->actAs(['*']);
        if (!EstimatedIncome::canView()) {
            $this->markTestSkipped('no income widget for this role');
        }
        $placed = $this->placed();

        $this->assertSame(0, $placed[EstimatedIncome::class], json_encode($placed));
        $this->assertSame(1, $placed[DomainRenewals::class], json_encode($placed));
        $this->assertSame(array_key_last($placed), ActiveUsers::class, 'Active users last, the width of the page');
    }
}
