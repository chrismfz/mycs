<?php

namespace App\Admin\Pages;

use App\Admin\Widgets\CronStat\CronOverview;
use App\Admin\Widgets\CronStat\CronStat;
use App\Admin\Widgets\CronStat\CronTable;
use App\Admin\Widgets\CronStat\HealthChecks;
use App\Services\Ops\Health;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Actions\FilterAction;
use Filament\Pages\Dashboard\Concerns\HasFiltersAction;
use Illuminate\Support\Facades\Auth;

class CronStats extends Dashboard
{
    use HasFiltersAction;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $title = 'Automation Status';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-time-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-time-fill';

    protected static ?int $navigationSort = 4;

    protected static string $routePath = 'cron-stats';

    protected function getHeaderActions(): array
    {
        return [
            FilterAction::make()
                ->slideOver(false)
                ->schema([
                    DatePicker::make('date')
                        ->default(now())
                        ->label('Select Date')
                        ->required(),
                ]),
        ];
    }

    public function getWidgets(): array
    {
        // but filter out
        return [
            CronOverview::class,
            HealthChecks::class,
            CronTable::class,
            CronStat::class,
        ];
    }

    /** Something failing or needing a look, on every admin page (as the checks were read in the last minute). */
    public static function getNavigationBadge(): ?string
    {
        $status = Health::cached()['status'];

        return $status === Health::OK ? null : HealthChecks::label($status);
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return HealthChecks::color(Health::cached()['status']);
    }

    public static function canAccess(): bool
    {
        return Auth::user()->hasPermission('admin.cron_stats.view');
    }
}
