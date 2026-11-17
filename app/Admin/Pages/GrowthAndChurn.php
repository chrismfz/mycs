<?php

namespace App\Admin\Pages;

use App\Models\Currency;
use App\Services\Reports\GrowthChurn;
use App\Services\Reports\IncomeReport;
use App\Services\Reports\RevenueForecast as Forecast;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Growth and churn (owner 2026-11-17, docs/growth-churn.md; GrowthChurn): month by month, the recurring income a month
 * that came and went, beside the year before; by product; why they left. Net, one currency; no customer is named, so the
 * reports' permission is enough.
 */
class GrowthAndChurn extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-line-chart-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-line-chart-fill';

    protected static ?string $slug = 'growth-and-churn';

    protected string $view = 'admin.pages.growth-and-churn';

    public string $currency = '';

    public int|string $year = 0;

    public function mount(): void
    {
        $this->currency = (string) config('settings.default_currency');
        $this->year = (int) now()->format('Y');
    }

    public function getTitle(): string
    {
        return __('Growth and churn');
    }

    public static function getNavigationLabel(): string
    {
        return __('Growth and churn');
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

    public function code(): string
    {
        return array_key_exists($this->currency, $this->currencies()) ? $this->currency : (string) config('settings.default_currency');
    }

    public function chosenYear(): int
    {
        return in_array((int) $this->year, IncomeReport::years(), true) ? (int) $this->year : (int) now()->format('Y');
    }

    /** A value not offered is set back to the one shown (ADV-501-6). */
    public function updated(): void
    {
        $this->currency = $this->code();
        $this->year = $this->chosenYear();
    }

    /**
     * Read at most every 5 minutes per currency and year; keyed by the day.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $key = 'reports.growth_churn.' . $this->code() . '.' . $this->chosenYear() . '.' . now()->toDateString();

        return Cache::remember($key, 300, fn () => GrowthChurn::year($this->code(), $this->chosenYear()));
    }

    /**
     * A reason as staff read it: the customer's words, or what an ending with no request was.
     *
     * @param  array{key: string, reason: string}  $row
     */
    public static function reasonLabel(array $row): string
    {
        if (str_starts_with($row['key'], 'text:')) {
            return $row['reason'] === '' ? __('No reason given') : $row['reason'];
        }

        return match ($row['key']) {
            GrowthChurn::TERMINATED => __('Terminated, no cancellation request (mostly unpaid)'),
            GrowthChurn::CANCELLED_BY_STAFF => __('Cancelled by staff, no cancellation request'),
            default => __('Other reasons'),
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')->label(__('Export CSV'))->icon('ri-download-line')->action(fn () => $this->csv()),
        ];
    }

    private function csv(): StreamedResponse
    {
        $report = $this->report();

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [__('Month'), __('At the start'), __('New services'), __('New'), __('Upgrades'), __('Downgrades'),
                __('Cancellations'), __('Terminations'), __('Net change'), __('At the end'), __('Net last year'), __('New clients'),
                __('New domains'), __('Domains lost')], escape: '\\');
            foreach ($report['months'] as $row) {
                fputcsv($out, [$row['month']->format('Y-m'), Forecast::money($row['start']), $row['new_count'], Forecast::money($row['new']),
                    Forecast::money($row['up']), Forecast::money($row['down']), Forecast::money(-$row['cancelled']),
                    Forecast::money(-$row['terminated']), Forecast::money($row['net']), Forecast::money($row['end_mrr']),
                    Forecast::money($row['last_year']['net']), $row['clients'], $row['domains_new'], $row['domains_lost']], escape: '\\');
            }
            fclose($out);
        }, 'growth-and-churn-' . $this->code() . '-' . $this->chosenYear() . '-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }
}
