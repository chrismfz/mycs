<?php

namespace App\Admin\Widgets;

use App\Admin\Pages\RevenueForecast;
use App\Classes\Price;
use App\Models\Currency;
use App\Services\Reports\RevenueForecast as Forecast;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * The active services' income by billing cycle and its estimate for a year (WHMCS's billing widget; owner 2026-10-20), per
 * currency, net.
 */
class EstimatedIncome extends StatsOverviewWidget
{
    protected static ?int $sort = 12;

    protected ?string $pollingInterval = null;

    protected function getHeading(): ?string
    {
        return __('Estimated income');
    }

    protected function getStats(): array
    {
        $stats = [];
        // Read at most every 5 minutes (F10 of #275): the dashboard opens often, the services change seldom.
        foreach (Cache::remember('reports.annual_estimate', 300, fn () => Forecast::annualEstimate()) as $code => $estimate) {
            $currency = Currency::query()->find($code);
            $money = fn ($amount) => (new Price(['price' => $amount, 'currency' => $currency], tax: 0))->format($amount);
            $lines = [];
            foreach (RevenueForecast::cycleTitles() as $column => $title) {
                if ($estimate['cycles'][$column]['count'] > 0) {
                    $lines[] = $title . ': ' . $money($estimate['cycles'][$column]['amount']) . ' (' . $estimate['cycles'][$column]['count'] . ')';
                }
            }
            $stats[] = Stat::make(__('Est. annual') . ' · ' . $code, $money($estimate['annual']))
                ->description(implode(' · ', $lines))
                ->url(RevenueForecast::canAccess() ? RevenueForecast::getUrl() : null);
        }

        return $stats;
    }

    public static function canView(): bool
    {
        return RevenueForecast::canAccess();
    }
}
