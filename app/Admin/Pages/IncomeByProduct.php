<?php

namespace App\Admin\Pages;

use App\Models\Currency;
use App\Services\Reports\IncomeReport;
use App\Services\Reports\ProductIncome;
use App\Services\Reports\RevenueForecast as Forecast;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Income by product (owner 2026-10-28, the approved mockup; ProductIncome): the categories, the largest first, each
 * opening on its products (domains by extension), against the same period a year before, with active services, income
 * per service, the yearly value of renewals, and the services new and cancelled in the year. Net, one currency; no
 * customer is named, so the reports' permission is enough.
 */
class IncomeByProduct extends Page
{
    /** The columns it sorts by, largest first. */
    public const SORTS = ['received', 'last_year', 'change', 'services', 'per_service', 'annual'];

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-pie-chart-2-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-pie-chart-2-fill';

    protected static ?string $slug = 'income-by-product';

    protected string $view = 'admin.pages.income-by-product';

    public string $currency = '';

    public int|string $year = 0;

    public string $sort = 'received';

    /** @var list<string> the groups opened on their products */
    public array $open = [];

    public function mount(): void
    {
        $this->currency = (string) config('settings.default_currency');
        $this->year = (int) now()->format('Y');
    }

    public function getTitle(): string
    {
        return __('Income by product');
    }

    public static function getNavigationLabel(): string
    {
        return __('Income by product');
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

    /** The currency, year and sort shown: as chosen when offered, else the defaults (tampered input too). */
    public function code(): string
    {
        return array_key_exists($this->currency, $this->currencies()) ? $this->currency : (string) config('settings.default_currency');
    }

    public function chosenYear(): int
    {
        return in_array((int) $this->year, IncomeReport::years(), true) ? (int) $this->year : (int) now()->format('Y');
    }

    public function chosenSort(): string
    {
        return in_array($this->sort, self::SORTS, true) ? $this->sort : 'received';
    }

    /** A value not offered is set back to the one shown (ADV-501-6). */
    public function updated(): void
    {
        $this->currency = $this->code();
        $this->year = $this->chosenYear();
        $this->sort = $this->chosenSort();
        $this->open = array_values(array_filter($this->open, 'is_string'));
    }

    public function sortBy(string $column): void
    {
        $this->sort = in_array($column, self::SORTS, true) ? $column : 'received';
    }

    public function toggle(string $group): void
    {
        $this->open = in_array($group, $this->open, true)
            ? array_values(array_diff($this->open, [$group])) : [...$this->open, $group];
    }

    /**
     * Read at most every 5 minutes per currency and year (a sort or a group opened only rearranges it); keyed by the
     * day: «to date» turns at midnight.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $key = 'reports.income_by_product.' . $this->code() . '.' . $this->chosenYear() . '.' . now()->toDateString();

        return Cache::remember($key, 300, fn () => ProductIncome::year($this->code(), $this->chosenYear()));
    }

    /**
     * The groups and their items in the order chosen.
     *
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    public function sorted(array $report): array
    {
        $sort = $this->chosenSort();
        $key = fn (array $row) => match ($sort) {
            'change' => $row['received'] - $row['last_year'],
            // Per service in whole cents; nothing to divide by: last.
            'per_service' => $row['services'] ? intdiv($row['received'], $row['services']) : PHP_INT_MIN,
            'services' => $row['services'] ?? PHP_INT_MIN,
            default => $row[$sort],
        };
        $order = fn (array $a, array $b) => [$key($b), $b['received'], $a['name']] <=> [$key($a), $a['received'], $b['name']];
        $groups = $report['groups'];
        foreach ($groups as $i => $group) {
            usort($group['items'], $order);
            $groups[$i] = $group;
        }
        usort($groups, $order);

        return $groups;
    }

    /**
     * The cards: the largest group, the most per service (and the least of a product), the largest rise and fall of a
     * product or extension.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public function highlights(array $report): array
    {
        $groups = array_values(array_filter($report['groups'], fn ($group) => $group['key'] !== ProductIncome::OTHER));
        $items = array_merge(...array_map(fn ($group) => $group['items'], $groups ?: [['items' => []]]));
        $perService = fn (array $row) => $row['services'] ? intdiv($row['received'], $row['services']) : null;
        $withServices = array_filter($groups, fn ($group) => $group['services'] > 0 && $group['received'] > 0);
        usort($withServices, fn ($a, $b) => $perService($b) <=> $perService($a));
        $itemsWithServices = array_filter($items, fn ($item) => $item['services'] > 0 && $item['received'] > 0);
        usort($itemsWithServices, fn ($a, $b) => $perService($a) <=> $perService($b));
        $changes = $items;
        usort($changes, fn ($a, $b) => ($b['received'] - $b['last_year']) <=> ($a['received'] - $a['last_year']));
        $rise = $changes[0] ?? null;
        $fall = $changes === [] ? null : $changes[count($changes) - 1];

        return [
            'largest' => $groups[0] ?? null,
            'best' => $withServices[0] ?? null,
            'best_per' => isset($withServices[0]) ? $perService($withServices[0]) : null,
            'least' => $itemsWithServices[0] ?? null,
            'least_per' => isset($itemsWithServices[0]) ? $perService($itemsWithServices[0]) : null,
            'rise' => $rise !== null && $rise['received'] > $rise['last_year'] ? $rise : null,
            'fall' => $fall !== null && $fall['received'] < $fall['last_year'] ? $fall : null,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')->label(__('Export CSV'))->icon('ri-download-line')->action(fn () => $this->csv()),
        ];
    }

    private function csv(): StreamedResponse
    {
        $groups = $this->sorted($this->report());

        return response()->streamDownload(function () use ($groups) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [__('Category'), __('Product'), __('Income'), __('Same period last year'), __('Difference'),
                __('Active services'), __('Income per service'), __('Yearly value of renewals'), __('New services'), __('Cancellations')], escape: '\\');
            // A name is staff's text: never read as a formula by a spreadsheet.
            $text = fn (string $name) => preg_match('/^[=+\-@\t\r]/', $name) ? "'" . $name : $name;
            $line = fn (array $row, string $category, string $product) => [$text($category), $text($product), Forecast::money($row['received']),
                Forecast::money($row['last_year']), Forecast::money($row['received'] - $row['last_year']), $row['services'],
                $row['services'] ? Forecast::money(intdiv($row['received'], $row['services'])) : null, Forecast::money($row['annual']), $row['new'], $row['ended']];
            foreach ($groups as $group) {
                fputcsv($out, $line($group, $group['name'], ''), escape: '\\');
                foreach ($group['items'] as $item) {
                    fputcsv($out, $line($item, $group['name'], $item['name']), escape: '\\');
                }
            }
            fclose($out);
        }, 'income-by-product-' . $this->code() . '-' . $this->chosenYear() . '-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }
}
