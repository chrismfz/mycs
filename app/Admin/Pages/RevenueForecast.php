<?php

namespace App\Admin\Pages;

use App\Models\Currency;
use App\Services\Reports\RevenueForecast as Forecast;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Revenue forecast (ROADMAP 6, owner 2026-10-20): what the renewals bring in each of the next 12 months, by cycle as
 * WHMCS's Income Forecast, beside what was paid the same month a year before; this month's expected, invoiced and
 * collected. Net (the tax figures are Ekdosi's). One currency at a time.
 */
class RevenueForecast extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-line-chart-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-line-chart-fill';

    protected string $view = 'admin.pages.revenue-forecast';

    public string $currency = '';

    public function mount(): void
    {
        $this->currency = (string) config('settings.default_currency');
    }

    public function getTitle(): string
    {
        return __('Revenue forecast');
    }

    public static function getNavigationLabel(): string
    {
        return __('Revenue forecast');
    }

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.reports.view');
    }

    /** @return array<string, string> */
    public function currencies(): array
    {
        return Currency::query()->orderBy('code')->pluck('code', 'code')->all();
    }

    /** @return array<string, mixed> */
    public function forecast(): array
    {
        $currency = array_key_exists($this->currency, $this->currencies()) ? $this->currency : (string) config('settings.default_currency');

        return Forecast::for($currency);
    }

    /** @return array<int|string, string> the cycle columns' titles, as WHMCS's */
    public static function cycleTitles(): array
    {
        return [1 => __('Monthly'), 3 => __('Quarterly'), 6 => __('Semi-Annually'), 12 => __('Annually'), 24 => __('Biennially'),
            36 => __('Triennially'), 'other' => __('Other')];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')->label(__('Export CSV'))->icon('ri-download-line')
                ->action(fn () => $this->csv()),
        ];
    }

    private function csv(): StreamedResponse
    {
        $forecast = $this->forecast();
        $titles = self::cycleTitles();

        return response()->streamDownload(function () use ($forecast, $titles) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [__('Month'), ...array_values($titles), __('Domains'), __('Expected'), __('Same month last year')], escape: '\\');
            foreach ($forecast['months'] as $row) {
                fputcsv($out, [$row['month']->format('Y-m'), ...array_values($row['cycles']), $row['domains'], $row['total'], $row['last_year']], escape: '\\');
            }
            fclose($out);
        }, 'revenue-forecast-' . $this->currency . '-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }
}
