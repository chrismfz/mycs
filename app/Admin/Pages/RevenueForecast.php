<?php

namespace App\Admin\Pages;

use App\Models\Currency;
use App\Services\Reports\IncomePlan;
use App\Services\Reports\IncomeReport;
use App\Services\Reports\RevenueForecast as Forecast;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Income & forecast (owner 2026-10-28, the approved mockup; docs/income-planning.md): a year month by month, received,
 * still expected and their total against the same month a year before, added up through the year, with the year so far
 * and the months past its end (IncomePlan); then, as before (ROADMAP 6, owner 2026-10-20), what the renewals bring in
 * each of the next 12 months by cycle as WHMCS's Income Forecast. Net (the tax figures are Ekdosi's). One currency at
 * a time.
 */
class RevenueForecast extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-line-chart-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-line-chart-fill';

    protected string $view = 'admin.pages.revenue-forecast';

    public string $currency = '';

    public int|string $year = 0;

    public function mount(): void
    {
        $this->currency = (string) config('settings.default_currency');
        $this->year = (int) now()->format('Y');
    }

    /** A year or currency not offered (tampered input) is set back to the one shown, so the select says it (ADV-501-6). */
    public function updatedYear(): void
    {
        $this->year = $this->chosenYear();
    }

    public function updatedCurrency(): void
    {
        $this->currency = $this->code();
    }

    public function getTitle(): string
    {
        return __('Income & forecast');
    }

    public static function getNavigationLabel(): string
    {
        return __('Income & forecast');
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

    /** The currency shown: the one chosen when it exists, else the default (one place for the figures, the file, the format). */
    public function code(): string
    {
        return array_key_exists($this->currency, $this->currencies()) ? $this->currency : (string) config('settings.default_currency');
    }

    /** @return array<string, mixed> */
    public function forecast(): array
    {
        return Forecast::for($this->code());
    }

    /** @return list<int> the years to choose: next year, this one, and back to the first payment */
    public function years(): array
    {
        return [(int) now()->format('Y') + 1, ...IncomeReport::years()];
    }

    /** The year shown: as chosen when it is one of them, else this year. */
    public function chosenYear(): int
    {
        return in_array((int) $this->year, $this->years(), true) ? (int) $this->year : (int) now()->format('Y');
    }

    /** @return array<string, mixed> */
    public function plan(): array
    {
        return IncomePlan::year($this->code(), $this->chosenYear());
    }

    /**
     * This month, the next ones (and their sums), the year so far (and its difference) and the overdue: this year only.
     *
     * @return array<string, mixed>|null
     */
    public function summary(): ?array
    {
        if ($this->chosenYear() !== (int) now()->format('Y')) {
            return null;
        }
        $summary = IncomePlan::summary($this->code());
        $sum = fn (string $key) => Forecast::money(array_sum(array_map(fn ($row) => Forecast::cents($row[$key]), $summary['ahead'])));

        return $summary + ['ahead_total' => $sum('total'), 'ahead_last_year' => $sum('last_year'),
            'year_difference' => Forecast::money(Forecast::cents($summary['year']['received']) - Forecast::cents($summary['year']['last_year']))];
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
            Action::make('year_csv')->label(__('Export the year (CSV)'))->icon('ri-download-line')
                ->action(fn () => $this->yearCsv()),
            Action::make('csv')->label(__('Export the forecast by cycle (CSV)'))->icon('ri-download-line')
                ->action(fn () => $this->csv()),
        ];
    }

    private function yearCsv(): StreamedResponse
    {
        $plan = $this->plan();

        return response()->streamDownload(function () use ($plan) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [__('Month'), __('Received'), __('Expected'), __('Total'), __('Same month last year'), __('Difference'),
                __('Cumulative this year'), __('Cumulative last year')], escape: '\\');
            foreach ($plan['months'] as $row) {
                fputcsv($out, [$row['month']->format('Y-m'), $row['received'], $row['expected'], $row['total'], $row['last_year'],
                    $row['difference'], $row['cumulative'], $row['cumulative_last_year']], escape: '\\');
            }
            fputcsv($out, [__('Total'), $plan['totals']['received'], $plan['totals']['expected'], $plan['totals']['total'],
                $plan['totals']['last_year'], $plan['totals']['difference']], escape: '\\');
            fclose($out);
        }, 'income-' . $this->code() . '-' . $this->chosenYear() . '-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
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
        }, 'revenue-forecast-' . $this->code() . '-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }
}
