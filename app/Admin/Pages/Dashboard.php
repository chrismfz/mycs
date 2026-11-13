<?php

namespace App\Admin\Pages;

use App\Admin\Widgets\CronToday;
use App\Admin\Widgets\EstimatedIncome;
use App\Admin\Widgets\NeedsAttention;
use App\Admin\Widgets\PaymentsTracking;
use App\Admin\Widgets\RecentActivity;
use App\Admin\Widgets\Renewals\DomainRenewals;
use App\Admin\Widgets\Renewals\RecordsToCheck;
use App\Admin\Widgets\Renewals\ServiceRenewals;
use App\Admin\Widgets\Renewals\ServicesToCheck;
use App\Admin\Widgets\Revenue;
use App\Admin\Widgets\Servers;
use App\Admin\Widgets\StaffAndSessions;
use App\Admin\Widgets\Support;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;

class Dashboard extends BaseDashboard
{
    protected static string|\BackedEnum|null $navigationIcon = 'ri-function-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-function-fill';

    /**
     * Two columns that stack on their own (owner 2026-11-04, direction B2): the money on the left, the work on the
     * right. In one grid a row is as tall as its tallest card, so Support sat beside Revenue with a hole under it.
     * The widgets in neither list (the cards, the credit and bank bars above) keep the full width, in their `$sort`
     * order: above the columns when they sort before the first of them. Staff and sessions sits under Payments
     * tracking, compact and scrolling (owner 2026-11-04: «small like Servers»), where the left column had room. Payments
     * tracking is on the left since Recent activity (devbox 2026-11-04: left 1435 px, right 1170 px; without it on the
     * right, before Recent activity, the left was 774 px against 685 px and it sat below, full width).
     * Rebalanced (owner 2026-11-14: «άδικα σκρολάρουμε», nixpal live: left 790 px, right 1740 px): the renewals are money
     * to come, so they move left; Staff and sessions moves right beside Support and Recent activity (about 1200 / 1265 px).
     *
     * @var array<int, class-string>
     */
    public const LEFT = [Revenue::class, EstimatedIncome::class, PaymentsTracking::class, ServiceRenewals::class, DomainRenewals::class];

    /** @var array<int, class-string> */
    public const RIGHT = [NeedsAttention::class, CronToday::class, Servers::class, Support::class, StaffAndSessions::class, RecentActivity::class, RecordsToCheck::class, ServicesToCheck::class];

    /**
     * What the viewer sees, in four groups: the full width above, the two columns, the full width below.
     *
     * @return array{above: array<int, class-string>, left: array<int, class-string>, right: array<int, class-string>, below: array<int, class-string>}
     */
    public function layout(): array
    {
        $columned = [...self::LEFT, ...self::RIGHT];
        $firstColumned = min(array_map(fn (string $widget): int => $widget::getSort(), $columned));
        $widgets = array_values(array_filter($this->getWidgets(), fn (string $widget): bool => $widget::canView()));
        $others = array_values(array_filter($widgets, fn (string $widget): bool => !in_array($widget, $columned, true)));

        return [
            'above' => array_values(array_filter($others, fn (string $widget): bool => $widget::getSort() < $firstColumned)),
            'left' => array_values(array_intersect(self::LEFT, $widgets)),
            'right' => array_values(array_intersect(self::RIGHT, $widgets)),
            'below' => array_values(array_filter($others, fn (string $widget): bool => $widget::getSort() >= $firstColumned)),
        ];
    }

    public function getWidgetsContentComponent(): Component
    {
        return Grid::make(1)->schema(function (): array {
            $layout = $this->layout();
            $columns = array_values(array_filter([$layout['left'], $layout['right']]));

            return [
                ...$this->getWidgetsSchemaComponents($layout['above']),
                // One column left (a staff member without the other's permissions) is not squeezed into half the width.
                Grid::make(['default' => 1, 'lg' => max(1, count($columns))])->schema(
                    array_map(fn (array $column): Grid => Grid::make(1)->schema($this->getWidgetsSchemaComponents($column)), $columns),
                )->hidden($columns === []),
                ...$this->getWidgetsSchemaComponents($layout['below']),
            ];
        });
    }
}
