<?php

namespace App\Admin\Widgets;

use App\Admin\Pages\RevenueForecast;
use App\Classes\Price;
use App\Models\Currency;
use App\Services\Reports\RevenueForecast as Forecast;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;

/**
 * WHMCS's Billing and Income Forecast dashboard widgets in one (owner 2026-10-21, screenshots): what came in today, this
 * month, this year and ever; then per currency each billing cycle's active services (amount and count, zero ones too)
 * and the estimate for a year (monthly × 12 + quarterly × 4 + …). Net, as every MyCS figure (the tax ones are Ekdosi's).
 * Billing in the default currency; the forecast in it always, in another when a service is billed in it.
 */
class EstimatedIncome extends Widget
{
    protected static ?int $sort = 12;

    protected string $view = 'admin.widgets.estimated-income';

    public function render(): View
    {
        $default = (string) config('settings.default_currency');
        $estimates = self::estimate();
        $estimates += [$default => null];
        ksort($estimates);

        $currencies = [];
        foreach ($estimates as $code => $estimate) {
            $currency = Currency::query()->find($code);
            $money = fn ($amount) => (new Price(['price' => $amount, 'currency' => $currency], tax: 0))->format($amount);
            $lines = [];
            foreach (RevenueForecast::cycleTitles() as $column => $title) {
                $cycle = $estimate['cycles'][$column] ?? ['amount' => '0.00', 'count' => 0];
                if ($column === 'other' && $cycle['count'] === 0) {
                    continue; // WHMCS has no «other»: shown only when there is one
                }
                $lines[] = ['title' => $title, 'amount' => $money($cycle['amount']), 'count' => $cycle['count']];
            }
            $currencies[] = ['code' => $code, 'lines' => $lines, 'annual' => $money($estimate['annual'] ?? '0.00')];
        }

        $now = CarbonImmutable::now();
        $currency = Currency::query()->find($default);
        $money = fn ($amount) => (new Price(['price' => $amount, 'currency' => $currency], tax: 0))->format($amount);
        $billing = Cache::remember('reports.billing.' . $default . '.' . $now->format('Y-m-d-H'), 300, fn () => [
            'today' => Forecast::collected($default, $now->startOfDay(), $now->addDay()->startOfDay()),
            'month' => Forecast::collected($default, $now->startOfMonth(), $now->addMonth()->startOfMonth()),
            'year' => Forecast::collected($default, $now->startOfYear(), $now->addYear()->startOfYear()),
            'all' => Forecast::collected($default, null, $now->addDay()->startOfDay()),
        ]);

        return view($this->view, ['currencies' => $currencies, 'url' => RevenueForecast::getUrl(),
            'billing' => array_map($money, $billing)]);
    }

    public static function canView(): bool
    {
        return RevenueForecast::canAccess();
    }

    /**
     * Read at most every 5 minutes (F10 of #275): the dashboard opens often, the services change seldom.
     *
     * @return array<string, array{cycles: array<int|string, array{amount: string, count: int}>, annual: string}>
     */
    private static function estimate(): array
    {
        return Cache::remember('reports.annual_estimate', 300, fn () => Forecast::annualEstimate());
    }
}
