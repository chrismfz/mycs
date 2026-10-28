<?php

namespace App\Admin\Pages;

use App\Admin\Resources\ClientResource;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Currency;
use App\Services\Reports\ClientIncome;
use App\Services\Reports\IncomeReport;
use App\Services\Reports\RevenueForecast as Forecast;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Income by customer (owner 2026-10-28, the approved mockup; ClientIncome): the largest first, against the same period a
 * year before, how much the top 10 and 50 weigh, who is new and who stopped paying, each one's active services and what
 * they bring in a year if renewed. Net, one currency. Every customer's name: staff who may see invoices too, as the
 * Income report (F9 of #280).
 */
class IncomeByClient extends Page
{
    /** The table's page size. */
    public const PER_PAGE = 50;

    /** The columns it sorts by: the row's key, largest first. */
    public const SORTS = ['received', 'last_year', 'change', 'services', 'annual', 'since'];

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-user-star-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-user-star-fill';

    protected static ?string $slug = 'income-by-client';

    protected string $view = 'admin.pages.income-by-client';

    public string $currency = '';

    public int|string $year = 0;

    public int|string|null $group = null;

    public string $search = '';

    public string $sort = 'received';

    public int|string $page = 1;

    public function mount(): void
    {
        $this->currency = (string) config('settings.default_currency');
        $this->year = (int) now()->format('Y');
    }

    public function getTitle(): string
    {
        return __('Income by client');
    }

    public static function getNavigationLabel(): string
    {
        return __('Income by client');
    }

    public static function canAccess(): bool
    {
        $staff = Auth::user();

        return (bool) $staff?->hasPermission('admin.reports.view') && (bool) $staff->hasPermission('admin.invoices.viewAny');
    }

    /** @return array<string, string> */
    public function currencies(): array
    {
        return Currency::query()->orderBy('code')->pluck('code', 'code')->all();
    }

    /** @return array<int, string> */
    public function groups(): array
    {
        return ClientGroup::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** The currency, year, group and sort shown: as chosen when offered, else the defaults (tampered input too). */
    public function code(): string
    {
        return array_key_exists($this->currency, $this->currencies()) ? $this->currency : (string) config('settings.default_currency');
    }

    public function chosenYear(): int
    {
        return in_array((int) $this->year, IncomeReport::years(), true) ? (int) $this->year : (int) now()->format('Y');
    }

    public function chosenGroup(): ?int
    {
        return $this->group !== null && $this->group !== '' && array_key_exists((int) $this->group, $this->groups()) ? (int) $this->group : null;
    }

    public function chosenSort(): string
    {
        return in_array($this->sort, self::SORTS, true) ? $this->sort : 'received';
    }

    /** Any filter changed: back to the first page, and a value not offered set back to the one shown (ADV-501-6). */
    public function updated(string $property): void
    {
        $this->currency = $this->code();
        $this->year = $this->chosenYear();
        $this->group = $this->chosenGroup();
        $this->sort = $this->chosenSort();
        if ($property !== 'page') {
            $this->page = 1;
        }
    }

    public function sortBy(string $column): void
    {
        $this->sort = in_array($column, self::SORTS, true) ? $column : 'received';
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    /**
     * Read at most every 5 minutes per currency, year and group (review of #503): a search keystroke, a sort or a page
     * only rearranges it. Keyed by the day, as the dashboard's: «to date» turns at midnight.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $key = 'reports.income_by_client.' . $this->code() . '.' . $this->chosenYear() . '.' . ($this->chosenGroup() ?? 'all') . '.' . now()->toDateString();

        return Cache::remember($key, 300, fn () => ClientIncome::year($this->code(), $this->chosenYear(), $this->chosenGroup()));
    }

    /**
     * The table's rows: searched (name, company, email, VAT number), sorted, with each customer's name and «client since».
     *
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    public function rows(array $report): array
    {
        $rows = $report['rows'];
        $search = trim($this->search);
        if ($search !== '') {
            // Every word in one of the fields (the name as shown, «first last», too; ADV-503-9), % and _ taken literally
            // on MariaDB and SQLite alike (ADV-503-8, as TicketResource).
            $query = Client::query()->whereIn('id', array_column($rows, 'client_id'));
            foreach (preg_split('/\s+/', $search) ?: [] as $word) {
                $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($word)) . '%';
                $query->where(fn (Builder $query) => $query->where('id', ctype_digit($word) ? (int) $word : 0)
                    ->orWhereHas('primaryContact', fn (Builder $query) => $query->where(function (Builder $query) use ($like) {
                        foreach (['first_name', 'last_name', 'company_name', 'email', 'vat_number', 'tax_id'] as $column) {
                            $query->orWhereRaw('LOWER(' . $column . ") LIKE ? ESCAPE '!'", [$like]);
                        }
                    })));
            }
            $found = $query->pluck('id')->flip();
            $rows = array_values(array_filter($rows, fn ($row) => isset($found[$row['client_id']])));
        }
        // The rank, and the income of all up to it, by income whatever the sort.
        $ranks = [];
        $running = 0;
        foreach ($report['rows'] as $rank => $row) {
            $running += $row['received'];
            $ranks[$row['client_id']] = [$rank + 1, $running];
        }
        $sort = $this->chosenSort();
        if ($sort !== 'received') {
            $since = $sort === 'since' ? Client::query()->whereIn('id', array_column($rows, 'client_id'))->pluck('created_at', 'id') : collect();
            $key = fn ($row) => match ($sort) {
                'change' => $row['received'] - $row['last_year'],
                // The longest-standing first; one with no date last.
                'since' => ($date = $since->get($row['client_id'])) === null ? PHP_INT_MIN : -strtotime((string) $date),
                default => $row[$sort],
            };
            usort($rows, fn ($a, $b) => [$key($b), $b['received'], $a['client_id']] <=> [$key($a), $a['received'], $b['client_id']]);
        }

        return array_map(fn ($row) => $row + ['rank' => $ranks[$row['client_id']][0], 'cumulative' => $ranks[$row['client_id']][1]], $rows);
    }

    /**
     * These rows with their client (name, «client since»): only the rows shown, or the file's (review of #503).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function withClients(array $rows): array
    {
        $clients = Client::query()->with(['primaryContact', 'ownerGrant.user'])->whereIn('id', array_column($rows, 'client_id'))->get()->keyBy('id');

        return array_map(fn ($row) => $row + ['client' => $clients->get($row['client_id'])], $rows);
    }

    public static function clientUrl(int $id): string
    {
        return ClientResource::getUrl('view', ['record' => $id]);
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
        $rows = $this->withClients($this->rows($report));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['#', __('Client ID'), __('Client'), __('Income'), __('Same period last year'), __('Difference'),
                __('Active services'), __('Yearly value of renewals'), __('Client since')], escape: '\\');
            foreach ($rows as $row) {
                // A name is the customer's own text: never read as a formula by a spreadsheet.
                $name = (string) $row['client']?->name;
                fputcsv($out, [$row['rank'], $row['client_id'], preg_match('/^[=+\-@\t\r]/', $name) ? "'" . $name : $name,
                    Forecast::money($row['received']), Forecast::money($row['last_year']), Forecast::money($row['received'] - $row['last_year']),
                    $row['services'], Forecast::money($row['annual']), $row['client']?->created_at?->format('Y-m-d')], escape: '\\');
            }
            fclose($out);
        }, 'income-by-client-' . $this->code() . '-' . $this->chosenYear() . '-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }
}
