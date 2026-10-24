<?php

namespace App\Admin\Resources\DomainTldResource\Pages;

use App\Admin\Resources\DomainTldResource;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\Pricing\TldImport;
use App\Services\Domains\Pricing\TldImportOptions;
use App\Services\Domains\Pricing\TldPriceMath;
use App\Support\Domains\TldPricing;
use App\Support\TimeLimit;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * WHMCS's «TLD Import & Pricing Sync» (docs/domains.md D-6): staff pick a registrar connection and some TLDs, a margin,
 * a rounding, the years and the rates for MyCS's other currencies; «Fetch» reads the registrar's costs (the TLDs typed,
 * the popular ones of the setting, or every TLD it offers) and shows, one row per TLD, today's price, its cost and its
 * margin per operation, with the price the import would write; «Import» writes only the TLDs ticked. Nothing is written
 * by «Fetch».
 *
 * @property-read Schema $form
 */
class ImportTlds extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = DomainTldResource::class;

    protected string $view = 'admin.resources.domain-tld-resource.pages.import-tlds';

    protected static ?string $title = 'TLD Import & Pricing Sync';

    /** How long a fetch is kept for the page (a list of every TLD is ~16 s to read again). */
    private const KEEP_SECONDS = 3 * 3600;

    /** The rows per page staff may choose. */
    public const PER_PAGE = [50, 100, 250];

    /** The grid's filters. */
    public const FILTERS = ['all', 'mycs', 'new', 'alerts', 'geo'];

    /** @var array<string, mixed> */
    public ?array $data = [];

    /**
     * Where the registrar's answer is kept (the cache: ~2,000 TLDs are too many to travel with every click). Locked:
     * written only by a fetch on the server, never from the browser (a forged cost would become a price: review F2).
     */
    #[Locked]
    public ?string $fetchKey = null;

    /** The connection the costs were fetched from: the import uses it, and only it. */
    #[Locked]
    public ?int $fetchedFrom = null;

    /** @var list<string> the TLDs ticked; nothing is ticked by default (WHMCS too) */
    public array $selected = [];

    public string $search = '';

    public string $filter = 'all';

    /** 'default' (MyCS's first, then as fetched), 'tld', or «operation.cost|margin|new». */
    public string $sort = 'default';

    public string $direction = 'asc';

    public int $perPage = 50;

    public int $pageNo = 1;

    /** The currency the grid shows prices and margins in. */
    public string $currency = '';

    /** @var array<string, array{costs?: array<string, array{cost: string, currency: string}>, min_years?: int, error?: string, not_offered?: bool}>|null */
    private ?array $fetchedMemo = null;

    public static function canAccess(array $parameters = []): bool
    {
        $staff = Auth::guard('staff')->user();

        return $staff !== null && $staff->hasPermission('admin.domain_tlds.create') && $staff->hasPermission('admin.domain_tlds.update');
    }

    public function mount(): void
    {
        $this->form->fill([
            'tlds' => DomainTld::query()->orderBy('sort')->pluck('tld')->implode(', '),
            'margin_type' => TldPriceMath::MARGIN_PERCENT,
            'margin' => '20',
            'rounding' => 'none',
            'years' => 1,
            'redemption_fee' => false,
            'auto_register' => false,
            'keep_free_and_closed' => true,
        ]);
        $this->currency = $this->currencies()[0] ?? '';
    }

    /** @return array<int|string, string> usable connections whose registrar has a price list */
    public static function connections(): array
    {
        $factory = app(DomainRegistrarFactory::class);

        return DomainRegistrarConnection::query()->orderBy('label')->get()
            ->filter(fn (DomainRegistrarConnection $c) => $c->isUsable() && $factory->for($c)->capabilities()->supportsPricingSync)
            ->mapWithKeys(fn (DomainRegistrarConnection $c) => [$c->id => $c->label . ' (' . $c->mode . ')'])->all();
    }

    /** @return list<string> MyCS's currencies, the default first */
    public function currencies(): array
    {
        $default = (string) config('settings.default_currency');
        $codes = Currency::query()->orderBy('code')->pluck('code')->all();
        usort($codes, fn (string $a, string $b) => [$a !== $default, $a] <=> [$b !== $default, $b]);

        return $codes;
    }

    public function form(Schema $schema): Schema
    {
        $currencies = Currency::query()->orderBy('code')->pluck('code')->all();

        return $schema->statePath('data')->columns(1)->components([
            Section::make(__('Registrar and TLDs'))->columns(2)->schema([
                Select::make('connection_id')->label(__('Registrar'))->options(fn () => self::connections())->required()->live(),
                Textarea::make('tlds')->label('TLDs')->rows(2) // needed by «Fetch these TLDs» only (review of #422)
                    ->helperText(__('Separated by commas or spaces, without the dot: com, net, org, eu. At first, the TLDs MyCS sells.')),
            ]),
            Section::make(__('Pricing'))->columns(3)->schema([
                Select::make('margin_type')->label(__('Margin type'))->required()
                    ->options([TldPriceMath::MARGIN_PERCENT => __('Percentage'), TldPriceMath::MARGIN_FIXED => __('Fixed amount')]),
                TextInput::make('margin')->label(__('Profit margin'))->required()->regex('/^\d+(\.\d{1,2})?$/'),
                Select::make('rounding')->label(__('Round to next'))->required()
                    ->options(['none' => __('No rounding'), '.00' => 'x.00', '.50' => 'x.50', '.99' => 'x.99']),
                Select::make('years')->label(__('Register and renew up to (years)'))->required()
                    ->options(collect(range(1, 10))->mapWithKeys(fn (int $n) => [$n => (string) $n])->all())
                    ->helperText(__('2 to N years at N times the one-year price; a TLD sold from 2 years (.gr): 2, 4, … up to N.')),
                Toggle::make('redemption_fee')->label(__('Import the grace and redemption fees'))->live()->helperText(__('The restore fee, with the same margin: on the redemption, or on the grace where the TLD has no redemption.')),
                Toggle::make('auto_register')->label(__('Automatic registration with this registrar')),
                Toggle::make('keep_free_and_closed')->label(__('Keep free and not-offered prices'))
                    ->helperText(__('A 0.00 (free) or empty (not offered) price stays as it is.')),
            ]),
            Section::make(__('Exchange rates'))->columns(4)
                ->description(__('MyCS keeps no rates: one unit of the registrar\'s currency is how much of each other currency. A currency left empty gets no price from the import.'))
                ->schema(array_map(fn (string $code) => TextInput::make("rates.{$code}")->label($code)->live(onBlur: true)
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail) {
                        if (filled($value) && (!TldPriceMath::isAmount((string) $value) || bccomp((string) $value, '0', 8) <= 0)) {
                            $fail(__('An exchange rate is a number above zero.'));
                        }
                    }), $currencies)),
        ]);
    }

    private function options(): TldImportOptions
    {
        $data = $this->form->getState();

        return new TldImportOptions(
            marginType: (string) $data['margin_type'],
            margin: (string) $data['margin'],
            rounding: (string) $data['rounding'],
            years: (int) $data['years'],
            redemptionFee: (bool) ($data['redemption_fee'] ?? false),
            autoRegister: (bool) ($data['auto_register'] ?? false),
            rates: array_filter((array) ($data['rates'] ?? []), fn ($rate) => is_string($rate) && $rate !== ''),
            keepFreeAndClosed: (bool) ($data['keep_free_and_closed'] ?? true),
        );
    }

    private function connection(): DomainRegistrarConnection
    {
        $id = (int) ($this->form->getState()['connection_id'] ?? 0);
        if (!array_key_exists($id, self::connections())) {
            throw new InvalidArgumentException(__('Choose a registrar connection with a price list.'));
        }

        return DomainRegistrarConnection::query()->findOrFail($id);
    }

    /** @return list<string> TLDs as typed (commas, spaces, dots allowed), in one form, unique, at most 100 */
    private static function parseTlds(string $typed): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $typed, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tld) {
            try {
                $out[] = Domain::normalise(ltrim($tld, '.'));
            } catch (InvalidArgumentException) {
                // not a TLD: skipped
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 100);
    }

    /** Read the costs of the TLDs typed; writes nothing. */
    public function fetch(): void
    {
        $connection = $this->connection();
        $tlds = self::parseTlds((string) ($this->data['tlds'] ?? ''));
        if ($tlds === []) {
            Notification::make()->warning()->title(__('Type the TLDs to fetch.'))->send();

            return;
        }
        TimeLimit::atLeast(120);
        $answers = app(TldImport::class)->fetch($connection, $tlds);
        $fetched = [];
        foreach ($tlds as $tld) {
            $answer = $answers[$tld] ?? null;
            $fetched[$tld] = match (true) {
                $answer instanceof TldPricing => ['costs' => $answer->costs, 'min_years' => $answer->minYears],
                is_string($answer) => ['error' => $answer],
                default => ['not_offered' => true],
            };
        }
        $this->keep($connection, $fetched, 'all');
    }

    /** The popular TLDs of the setting into the field, then fetched. */
    public function fetchPopular(): void
    {
        $this->data['tlds'] = implode(', ', self::parseTlds((string) (config('settings.domain_popular_tlds') ?: TldImport::POPULAR_TLDS)));
        $this->fetch();
    }

    /**
     * Every TLD the registrar offers (Openprovider: ~2,000, ~16 s); writes nothing. Shown filtered: all, or the
     * geographic ones.
     */
    public function fetchAll(string $filter = 'all'): void
    {
        $connection = $this->connection();
        TimeLimit::atLeast(180);
        $fetched = [];
        foreach (app(TldImport::class)->fetchAll($connection) as $tld => $pricing) {
            $fetched[$tld] = ['costs' => $pricing->costs, 'min_years' => $pricing->minYears];
        }
        $this->keep($connection, $fetched, in_array($filter, self::FILTERS, true) ? $filter : 'all');
    }

    /** @param  array<string, array<string, mixed>>  $fetched */
    private function keep(DomainRegistrarConnection $connection, array $fetched, string $filter): void
    {
        if ($this->fetchKey !== null) {
            Cache::forget($this->cacheKey($this->fetchKey));
        }
        $this->fetchKey = Str::random(40);
        Cache::put($this->cacheKey($this->fetchKey), ['staff' => Auth::guard('staff')->id(), 'connection' => $connection->id, 'tlds' => $fetched], self::KEEP_SECONDS);
        $this->fetchedMemo = null;
        $this->fetchedFrom = $connection->id;
        $this->selected = []; // nothing ticked: an import is staff's choice, TLD by TLD (review 2)
        [$this->filter, $this->search, $this->sort, $this->direction, $this->pageNo] = [$filter, '', 'default', 'asc', 1];
    }

    private function cacheKey(string $key): string
    {
        return 'tld-import:' . $key;
    }

    /**
     * What the registrar answered, as kept: only for the staff member and the connection it was fetched for.
     *
     * @return array<string, array{costs?: array<string, array{cost: string, currency: string}>, min_years?: int, error?: string, not_offered?: bool}>
     */
    public function fetched(): array
    {
        if ($this->fetchedMemo !== null) {
            return $this->fetchedMemo;
        }
        $kept = $this->fetchKey === null ? null : Cache::get($this->cacheKey($this->fetchKey));
        $ours = is_array($kept) && ($kept['staff'] ?? null) === Auth::guard('staff')->id() && ($kept['connection'] ?? null) === $this->fetchedFrom;

        return $this->fetchedMemo = $ours && is_array($kept['tlds'] ?? null) ? $kept['tlds'] : [];
    }

    /** Whether a fetch was made but is no longer kept (the page was left open for hours). */
    public function fetchExpired(): bool
    {
        return $this->fetchKey !== null && !Cache::has($this->cacheKey($this->fetchKey));
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'filter', 'perPage', 'currency'], true)) {
            $this->pageNo = 1;
        }
    }

    /** Sort by a column; again by the same one turns it round. */
    public function sortBy(string $column): void
    {
        if (!in_array($column, $this->sortColumns(), true)) {
            return;
        }
        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
        $this->pageNo = 1;
    }

    /** @return list<string> */
    private function sortColumns(): array
    {
        $columns = ['default', 'tld'];
        foreach (TldImport::GRID_OPERATIONS as $operation) {
            foreach (['cost', 'margin', 'new'] as $what) {
                $columns[] = "{$operation}.{$what}";
            }
        }

        return $columns;
    }

    /** Tick the importable TLDs of this page, or untick them if all are ticked. */
    public function togglePage(): void
    {
        $tlds = array_keys(array_filter($this->grid()['rows'], fn (array $row) => $row['importable']));
        $this->selected = array_values(count(array_diff($tlds, $this->selected)) === 0
            ? array_diff($this->selected, $tlds)
            : array_unique([...$this->selected, ...$tlds]));
    }

    /** @return list<string> the operations the grid shows: the fees after expiry only when they are imported */
    public function operations(): array
    {
        return ($this->data['redemption_fee'] ?? false) ? TldImport::GRID_OPERATIONS : ['register', 'renew', 'transfer'];
    }

    /**
     * The grid: one row per TLD (this page of those the search and filter keep, sorted), and the counts.
     *
     * @return array{rows: array<string, array<string, mixed>>, total: int, shown: int, alerts: int, pages: int, first: int, per_page: int}
     */
    public function grid(): array
    {
        $empty = ['rows' => [], 'total' => 0, 'shown' => 0, 'alerts' => 0, 'pages' => 1, 'first' => 0, 'per_page' => self::PER_PAGE[0]];
        if ($this->fetched() === []) {
            return $empty;
        }
        try {
            $rows = $this->buildRows($this->options());
        } catch (Throwable) {
            return $empty; // an invalid option shows its form error, never a broken page
        }
        $alerts = count(array_filter($rows, fn (array $row) => $row['alert']));
        $total = count($rows);
        $rows = $this->sorted($this->filtered($rows));
        $perPage = in_array($this->perPage, self::PER_PAGE, true) ? $this->perPage : self::PER_PAGE[0];
        $pages = max(1, (int) ceil(count($rows) / $perPage));
        $this->pageNo = min(max(1, $this->pageNo), $pages);

        return ['rows' => array_slice($rows, ($this->pageNo - 1) * $perPage, $perPage, true), 'total' => $total,
            'shown' => count($rows), 'alerts' => $alerts, 'pages' => $pages, 'first' => ($this->pageNo - 1) * $perPage, 'per_page' => $perPage];
    }

    /** @return array<string, array<string, mixed>> */
    private function buildRows(TldImportOptions $options): array
    {
        $currency = in_array($this->currency, $this->currencies(), true) ? $this->currency : ($this->currencies()[0] ?? '');
        $import = app(TldImport::class);
        $existing = DomainTld::query()->with(['prices' => fn ($q) => $q->where('currency_code', $currency)])
            ->orderBy('sort')->get()->keyBy('tld');
        $order = array_flip($existing->keys()->all());
        $rows = [];
        $position = 0;
        foreach ($this->fetched() as $tld => $answer) {
            $tld = (string) $tld;
            $record = $existing->get($tld);
            $row = ['tld' => $tld, 'id' => $record?->id, 'exists' => $record !== null, 'error' => $answer['error'] ?? null,
                'not_offered' => (bool) ($answer['not_offered'] ?? false), 'importable' => false, 'alert' => false, 'ops' => [],
                'order' => [$record === null ? 1 : 0, $record === null ? $position : $order[$tld]]];
            $position++;
            $pricing = self::pricingOf($tld, $answer);
            if ($pricing === null && is_array($answer['costs'] ?? null)) {
                $row['error'] = __('Fetched before an update of MyCS: fetch again.'); // no period: never imported
            }
            $row['min_years'] = $pricing === null ? 1 : $pricing->minYears;
            $costs = $pricing?->costs;
            if ($pricing !== null && $costs !== null) {
                $new = $import->prices($pricing, $options, [$currency])[$currency] ?? [];
                $row['importable'] = $costs !== [];
                foreach (TldImport::GRID_OPERATIONS as $operation) {
                    // Register and renew at the shortest registration the registrar sells (.gr: 2 years), as quoted.
                    $period = in_array($operation, ['register', 'renew'], true) ? $pricing->minYears : 1;
                    $price = $record?->prices->first(fn ($p) => $p->operation === $operation && (int) $p->years === $period);
                    // What the import writes: a free or not-offered price it keeps is shown kept (adversarial F5 of #422).
                    $kept = $price !== null && $options->keepFreeAndClosed && ($price->price === null || bccomp((string) $price->price, '0', 2) === 0);
                    $cell = $this->cell($costs[$operation] ?? null, $currency, $options, $price?->price, $kept ? null : ($new[$operation][$period]['price'] ?? null));
                    $row['ops'][$operation] = $cell;
                    $row['alert'] = $row['alert'] || ($cell['alert'] !== null && in_array($operation, $this->operations(), true));
                }
            }
            $rows[$tld] = $row;
        }

        return $rows;
    }

    /**
     * One operation of one TLD: today's price, the import's, the cost in the grid's currency (or in its own, without a
     * rate), today's margin and its alert. All decimal strings: never a float.
     *
     * @param  ?array{cost: string, currency: string}  $cost
     * @return array{now: ?string, new: ?string, cost: ?string, cost_own: ?string, margin: ?string, alert: ?string}
     */
    private function cell(?array $cost, string $currency, TldImportOptions $options, mixed $now, ?string $new): array
    {
        $now = $now === null ? null : (string) $now;
        $exact = null;
        if ($cost !== null && TldPriceMath::isAmount($cost['cost'])) {
            $rate = $cost['currency'] === $currency ? '1' : ($options->rates[$currency] ?? null);
            $exact = $rate === null ? null : TldPriceMath::convert($cost['cost'], $rate);
        }
        $priced = $now !== null && $exact !== null && TldPriceMath::isAmount($now);

        return [
            'now' => $now,
            'new' => $new,
            'cost' => $exact === null ? null : TldPriceMath::cents($exact),
            'cost_own' => $cost === null ? null : $cost['cost'] . ' ' . $cost['currency'],
            'margin' => $priced ? TldPriceMath::marginPercent($now, $exact) : null,
            'alert' => $priced ? TldPriceMath::alert($now, $exact) : null,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function filtered(array $rows): array
    {
        $search = mb_strtolower(trim($this->search));

        return array_filter($rows, fn (array $row) => ($search === '' || str_contains('.' . $row['tld'], $search))
            && match ($this->filter) {
                'mycs' => $row['exists'],
                'new' => !$row['exists'],
                'alerts' => $row['alert'],
                'geo' => self::isGeographic($row['tld']),
                default => true,
            });
    }

    /** A country's TLD (two letters: gr, de, uk) or one under it (com.gr, co.uk). */
    public static function isGeographic(string $tld): bool
    {
        return preg_match('/(^|\.)[a-z]{2}$/', $tld) === 1;
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function sorted(array $rows): array
    {
        $sort = in_array($this->sort, $this->sortColumns(), true) ? $this->sort : 'default';
        $sign = $this->direction === 'desc' ? -1 : 1;
        uasort($rows, function (array $a, array $b) use ($sort, $sign) {
            if ($sort === 'default') {
                return $sign * ($a['order'] <=> $b['order']);
            }
            if ($sort === 'tld') {
                return $sign * strcmp($a['tld'], $b['tld']);
            }
            [$operation, $what] = explode('.', $sort);
            [$x, $y] = [$a['ops'][$operation][$what] ?? null, $b['ops'][$operation][$what] ?? null];
            if ($x === null || $y === null) {
                return ($x === null) <=> ($y === null) ?: strcmp($a['tld'], $b['tld']); // nothing to compare: last, either way
            }

            return $sign * bccomp($x, $y, 2) ?: strcmp($a['tld'], $b['tld']);
        });

        return $rows;
    }

    /**
     * A fetched answer as the registrar's TldPricing (its costs and its shortest registration), or null for none.
     *
     * @param  array<string, mixed>  $answer
     */
    private static function pricingOf(string $tld, array $answer): ?TldPricing
    {
        $costs = $answer['costs'] ?? null;
        $min = $answer['min_years'] ?? null; // none: kept before #424 (a list price, no period): fetch again (adversarial F4)

        return is_array($costs) && is_int($min) && $min >= 1 && $min <= 10 ? new TldPricing(tld: $tld, costs: $costs, minYears: $min) : null;
    }

    /** Import the ticked TLDs, all or nothing. */
    public function import(): void
    {
        $options = $this->options();
        $connection = $this->connection();
        if ($this->fetchedFrom !== $connection->id) {
            Notification::make()->warning()->title(__('Fetch the costs from this registrar first.'))->send();

            return;
        }
        $fetched = $this->fetched();
        $pricings = [];
        foreach ($this->selected as $tld) {
            $pricing = self::pricingOf($tld, $fetched[$tld] ?? []);
            if ($pricing !== null && $pricing->costs !== []) {
                $pricings[$tld] = $pricing;
            }
        }
        if ($pricings === []) {
            Notification::make()->warning()->title(__('Tick the TLDs to import.'))->send();

            return;
        }
        $count = app(TldImport::class)->import($connection, $pricings, $options);
        Notification::make()->success()->title(__(':count TLD(s) imported.', ['count' => $count]))->send();
        $this->redirect(DomainTldResource::getUrl('index'));
    }

    private function canListAll(): bool
    {
        $id = (int) ($this->data['connection_id'] ?? 0);
        $connection = array_key_exists($id, self::connections()) ? DomainRegistrarConnection::query()->find($id) : null;

        return $connection !== null && app(TldImport::class)->canListAll($connection);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetch')->label(__('Fetch these TLDs'))->icon('heroicon-o-arrow-path')
                ->tooltip(__('The TLDs of the field'))->action(fn () => $this->fetch()),
            Action::make('fetchPopular')->label(__('Popular'))->icon('heroicon-o-star')->color('gray')
                ->tooltip(__('The TLDs of the «Popular TLDs» setting'))->action(fn () => $this->fetchPopular()),
            Action::make('fetchGeographic')->label(__('Geographic'))->icon('heroicon-o-map-pin')->color('gray')
                ->tooltip(__('Every country TLD the registrar offers (about 15 seconds)'))
                ->visible(fn () => $this->canListAll())->action(fn () => $this->fetchAll('geo')),
            Action::make('fetchAll')->label(__('All'))->icon('heroicon-o-globe-alt')->color('gray')
                ->tooltip(__('Every TLD the registrar offers (about 2,000; about 15 seconds)'))
                ->visible(fn () => $this->canListAll())->action(fn () => $this->fetchAll()),
            Action::make('importSelected')->label(fn () => __('Import selected') . ($this->selected === [] ? '' : ' (' . count($this->selected) . ')'))
                ->icon('heroicon-o-arrow-down-tray')
                ->requiresConfirmation()->modalDescription(__('The ticked TLDs and their prices are written; other prices stay as they are.'))
                ->disabled(fn () => $this->fetched() === [])->action(fn () => $this->import()),
        ];
    }
}
