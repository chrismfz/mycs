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
use Throwable;

class CronStats extends Dashboard
{
    use HasFiltersAction;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

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
                        ->label(__('Select Date'))
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

    public function getTitle(): string
    {
        return __('Automation Status');
    }

    public static function getNavigationLabel(): string
    {
        return __('Automation Status');
    }

    /**
     * Something failing or needing a look, on every admin page (as the checks were read in the last minute). Never the
     * reason a page fails (review R3 of #258): no badge then.
     */
    public static function getNavigationBadge(): ?string
    {
        $status = self::healthStatus();

        return $status === null || $status === Health::OK ? null : HealthChecks::label($status);
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return HealthChecks::color(self::healthStatus() ?? Health::OK);
    }

    private static function healthStatus(): ?string
    {
        try {
            return Health::cached()['status'];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function canAccess(): bool
    {
        return Auth::user()->hasPermission('admin.cron_stats.view');
    }
}
