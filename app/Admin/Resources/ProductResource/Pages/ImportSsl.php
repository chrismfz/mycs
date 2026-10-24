<?php

namespace App\Admin\Resources\ProductResource\Pages;

use App\Admin\Resources\ProductResource;
use App\Helpers\ExtensionHelper;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Server;
use App\Services\Domains\Pricing\TldImportOptions;
use App\Services\Domains\Pricing\TldPriceMath;
use App\Services\Ssl\SslCatalog;
use App\Support\Redact;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use MyCS\Extensions\Servers\OpenproviderSsl\OpenproviderSsl;
use Throwable;

/**
 * «SSL catalogue & pricing» (owner 2026-10-22), as the TLD import: staff pick the Openprovider SSL server, a margin, a
 * rounding, the years and the rates; «Fetch» reads Openprovider's products with this account's cost and shows, for each,
 * whether MyCS can sell it, the MyCS product it is, its price and margin now and the price and margin the import would
 * make. «Import» writes only the products ticked. Nothing is written by «Fetch».
 *
 * @property-read Schema $form
 */
class ImportSsl extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = ProductResource::class;

    protected string $view = 'admin.resources.product-resource.pages.import-ssl';

    protected static ?string $title = 'SSL catalogue & pricing';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /**
     * Openprovider's products as fetched. Locked: written by fetch() only, never from the browser (a forged cost would
     * become a price, as the TLD import's review F2).
     *
     * @var array<int, array<string, mixed>>
     */
    #[Locked]
    public array $fetched = [];

    /** The server the products were fetched from: the import writes to it, and only it. */
    #[Locked]
    public ?int $fetchedFrom = null;

    /** @var list<int|string> the Openprovider product ids ticked; nothing by default */
    public array $selected = [];

    /** The grid (owner 2026-10-24, as the TLD import's): its search, filter, currency, years and order. */
    public string $search = '';

    public string $filter = 'all';

    public string $currency = '';

    public int $gridYears = 1;

    public string $sort = 'default';

    public string $direction = 'asc';

    /**
     * Prices staff typed for one product, currency and years, written by the import instead of the margin's
     * («id:currency:years» => amount). Checked again at the import: never trusted from the browser as they come.
     *
     * @var array<string, string|null>
     */
    public array $overrides = [];

    public const FILTERS = ['all', 'sellable', 'mycs', 'new', 'alerts', 'typed', 'unsellable'];

    /** The largest price the import writes (the plans' price column). */
    public const MAX_PRICE = '99999999.99';

    public static function canAccess(array $parameters = []): bool
    {
        $staff = Auth::guard('staff')->user();

        return $staff !== null && $staff->hasPermission('admin.products.create') && $staff->hasPermission('admin.products.update');
    }

    public function mount(): void
    {
        $this->form->fill([
            'server_id' => array_key_first(self::servers()),
            'margin_type' => TldPriceMath::MARGIN_PERCENT,
            'margin' => '30',
            'rounding' => '.99',
            'years' => 1,
            'hidden' => true,
        ]);
        $this->currency = self::currencies()[0] ?? '';
    }

    /** @return list<string> MyCS's currencies, the euro (Openprovider's) first */
    public static function currencies(): array
    {
        $codes = Currency::query()->orderBy('code')->pluck('code')->all();
        usort($codes, fn (string $a, string $b) => [$a !== 'EUR', $a] <=> [$b !== 'EUR', $b]);

        return $codes;
    }

    public static function overrideKey(int $id, string $currency, int $years): string
    {
        return "{$id}:{$currency}:{$years}";
    }

    /** A typed price as the import writes it, or null for none or one that is not a price (shown as an error). */
    public static function typedPrice(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = str_replace(',', '.', trim($value));
        if (!TldPriceMath::isAmount($value) || bccomp($value, '0', 2) <= 0 || bccomp($value, self::MAX_PRICE, 2) > 0 || preg_match('/^\d+(\.\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        return TldPriceMath::cents($value);
    }

    /** A price typed for a sellable product ticks it: typing is choosing it (cleared, nothing is unticked). */
    public function updatedOverrides(mixed $value, ?string $key = null): void
    {
        $typed = $key !== null ? [$key => $value] : (is_array($value) ? $value : []);
        foreach ($typed as $one => $price) {
            $id = (int) explode(':', (string) $one)[0];
            if (self::typedPrice($price) !== null && ($this->fetched[$id]['sellable'] ?? false) && !in_array($id, array_map('intval', $this->selected), true)) {
                $this->selected[] = $id;
            }
        }
    }

    public function sortBy(string $column): void
    {
        if (!in_array($column, ['default', 'name', 'cost', 'now', 'new', 'margin'], true)) {
            return;
        }
        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
    }

    /**
     * The grid: one line per product for the currency and years chosen, filtered and sorted: price now → the price the
     * import would write (the one typed, else the margin's), cost, margins, and how each stands against the cost
     * (below it, or within 10% over it: TldPriceMath::alert). Counts for the heading.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, alerts: int, typed: int}
     */
    public function grid(): array
    {
        $rows = $this->rows();
        $years = max(1, min(SslCatalog::MAX_YEARS, $this->gridYears));
        $out = [];
        foreach ($rows as $row) {
            $line = collect($row['lines'])->first(fn (array $l) => $l['currency'] === $this->currency && $l['years'] === $years);
            $typedRaw = $this->overrides[self::overrideKey((int) $row['id'], $this->currency, $years)] ?? null;
            $typed = self::typedPrice($typedRaw);
            $cost = $line['cost'] ?? null;
            $now = $line['now'] ?? null;
            $new = $typed ?? ($line['new'] ?? null);
            $out[] = $row + [
                'line' => $line,
                'cost' => $cost,
                'now' => $now,
                'new' => $new,
                'typed' => $typed,
                'typed_invalid' => filled($typedRaw) && $typed === null,
                'now_margin' => $now !== null && $cost !== null ? TldPriceMath::marginPercent($now, $cost) : null,
                'new_margin' => $new !== null && $cost !== null ? TldPriceMath::marginPercent($new, $cost) : null,
                'alert' => $now !== null && $cost !== null ? TldPriceMath::alert($now, $cost) : null,
                'new_alert' => $new !== null && $cost !== null ? TldPriceMath::alert($new, $cost) : null,
                'priceable' => $row['sellable'] && ($years <= (int) ($this->data['years'] ?? 1)) && isset($row['costs'][$years]),
            ];
        }
        $total = count($out);
        $alerts = count(array_filter($out, fn (array $r) => $r['alert'] !== null || ($r['new_alert'] !== null && $r['new'] !== $r['now'])));
        $typedCount = count(array_filter($out, fn (array $r) => $r['typed'] !== null));
        $search = mb_strtolower(trim($this->search));
        $out = array_values(array_filter($out, fn (array $r) => ($search === '' || str_contains(mb_strtolower($r['brand'] . ' ' . $r['name'] . ' #' . $r['id']), $search))
            && match (in_array($this->filter, self::FILTERS, true) ? $this->filter : 'all') {
                'sellable' => $r['sellable'],
                'mycs' => $r['products'] !== [],
                'new' => $r['sellable'] && $r['products'] === [],
                'alerts' => $r['alert'] !== null || $r['new_alert'] !== null,
                'typed' => $r['typed'] !== null,
                'unsellable' => !$r['sellable'],
                default => true,
            }));
        if ($this->sort !== 'default') {
            $sign = $this->direction === 'desc' ? -1 : 1;
            $key = ['name' => null, 'cost' => 'cost', 'now' => 'now', 'new' => 'new', 'margin' => 'new_margin'][$this->sort] ?? null;
            usort($out, function (array $a, array $b) use ($key, $sign) {
                if ($key === null) {
                    return $sign * strcmp($a['brand'] . ' ' . $a['name'], $b['brand'] . ' ' . $b['name']);
                }
                [$x, $y] = [$a[$key], $b[$key]];
                if ($x === null || $y === null) {
                    return ($x === null) <=> ($y === null) ?: strcmp($a['name'], $b['name']); // nothing to compare: last, either way
                }

                return $sign * bccomp((string) $x, (string) $y, 2) ?: strcmp($a['name'], $b['name']);
            });
        }

        return ['rows' => $out, 'total' => $total, 'alerts' => $alerts, 'typed' => $typedCount];
    }

    /**
     * The typed prices of the ticked products, checked: each a price, of a currency of MyCS, of years the import writes
     * (up to the plans' years, a period Openprovider sells). One that is not refuses the import, never skipped unseen.
     *
     * @param  array<int, array<string, mixed>>  $entries  the ticked ones, by id
     * @return array<int, array<string, array<int, string>>> id => currency => years => price
     */
    private function typedFor(array $entries, TldImportOptions $options): array
    {
        $currencies = self::currencies();
        $out = [];
        foreach ($this->overrides as $key => $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $parts = explode(':', (string) $key);
            if (count($parts) !== 3 || !isset($entries[(int) $parts[0]])) {
                continue; // a product not ticked: its typed price waits
            }
            [$id, $currency, $years] = [(int) $parts[0], $parts[1], (int) $parts[2]];
            $price = self::typedPrice($value);
            if ($price === null || !in_array($currency, $currencies, true) || $years < 1 || $years > $options->years || !isset($entries[$id]['costs'][$years])) {
                throw new InvalidArgumentException(__('The price typed for «:product» (:currency, :years year(s)) cannot be written: a price above zero, for years the plans have.', [
                    'product' => trim(($entries[$id]['brand'] ?? '') . ' ' . ($entries[$id]['name'] ?? '')), 'currency' => $currency, 'years' => $years]));
            }
            $out[$id][$currency][$years] = $price;
        }

        return $out;
    }

    /** How many of the ticked products' prices the import would write below their cost (for its confirmation). */
    public function belowCostTicked(): int
    {
        try {
            $options = $this->options();
        } catch (Throwable) {
            return 0;
        }
        $ticked = array_map('intval', $this->selected);
        $count = 0;
        foreach ($this->rows() as $row) {
            if (!in_array((int) $row['id'], $ticked, true)) {
                continue;
            }
            foreach ($row['lines'] as $line) {
                $price = self::typedPrice($this->overrides[self::overrideKey((int) $row['id'], $line['currency'], $line['years'])] ?? null) ?? $line['new'];
                if ($price !== null && $line['cost'] !== null && $line['years'] <= $options->years && bccomp($price, $line['cost'], 2) < 0) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /** @return array<int, string> the Openprovider SSL servers */
    public static function servers(): array
    {
        return Server::query()->where('extension', 'OpenproviderSsl')->orderBy('name')->pluck('name', 'id')->all();
    }

    public function form(Schema $schema): Schema
    {
        $currencies = Currency::query()->orderBy('code')->pluck('code')->all();

        return $schema->statePath('data')->columns(1)->components([
            Section::make(__('Openprovider SSL'))->columns(2)->schema([
                Select::make('server_id')->label(__('Server'))->options(fn () => self::servers())->required()
                    ->helperText(__('A server of the «Openprovider SSL» extension: its connection, and the products it sells.')),
                Select::make('category_id')->label(__('Category of new products'))->options(fn () => Category::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->helperText(__('Only for products made by the import; an existing one keeps its own.')),
            ]),
            Section::make(__('Pricing'))->columns(3)->schema([
                Select::make('margin_type')->label(__('Margin type'))->required()
                    ->options([TldPriceMath::MARGIN_PERCENT => __('Percentage'), TldPriceMath::MARGIN_FIXED => __('Fixed amount')])->live(),
                TextInput::make('margin')->label(__('Profit margin'))->required()->regex('/^\d+(\.\d{1,2})?$/')->live(debounce: 500),
                Select::make('rounding')->label(__('Round to next'))->required()->live()
                    ->options(['none' => __('No rounding'), '.00' => 'x.00', '.50' => 'x.50', '.99' => 'x.99']),
                Select::make('years')->label(__('Plans up to (years)'))->required()->live()
                    ->options([1 => '1', 2 => '2', 3 => '3'])
                    ->helperText(__('Openprovider\'s own price for 2 and 3 years, with the same margin.')),
                Toggle::make('hidden')->label(__('New products hidden'))->default(true)
                    ->helperText(__('Not listed in the shop until you list them; existing products keep their listing.')),
            ]),
            Section::make(__('Exchange rates'))->columns(4)->collapsed()
                ->description(__('Openprovider\'s cost is in EUR: one euro is how much of each other currency. A currency left empty gets no price.'))
                ->schema(array_map(fn (string $code) => TextInput::make("rates.{$code}")->label($code)->live(debounce: 500)
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
            years: max(1, min(SslCatalog::MAX_YEARS, (int) $data['years'])),
            redemptionFee: false,
            autoRegister: false,
            rates: array_filter((array) ($data['rates'] ?? []), fn ($rate) => is_string($rate) && $rate !== ''),
        );
    }

    private function server(): Server
    {
        $id = (int) ($this->form->getState()['server_id'] ?? 0);
        if (!array_key_exists($id, self::servers())) {
            throw new InvalidArgumentException(__('Choose an Openprovider SSL server.'));
        }

        return Server::query()->findOrFail($id);
    }

    /** Read Openprovider's products and this account's costs; writes nothing. */
    public function fetch(): void
    {
        $server = $this->server();
        try {
            $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
            if (!$extension instanceof OpenproviderSsl) {
                throw new InvalidArgumentException(__('Choose an Openprovider SSL server.'));
            }
            $this->fetched = SslCatalog::entries($extension->catalogue());
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title(__('Openprovider could not be read'))->body(Redact::text($e->getMessage()))->send();

            return;
        }
        $this->fetchedFrom = $server->id;
        $this->selected = []; // nothing ticked: an import is staff's choice, product by product
    }

    /**
     * The rows the page shows: per product, whether it can be sold, its MyCS products, and per currency and years the
     * cost, the price and margin now (the lowest of its products and plans), and the price and margin the import would
     * make; a price now the import will not touch is shown too, marked kept (ADV-11 of #348).
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        if ($this->fetched === [] || $this->fetchedFrom === null) {
            return [];
        }
        try {
            $options = $this->options();
        } catch (Throwable) {
            return []; // an invalid option shows its form error, never a broken page
        }
        $server = Server::query()->find($this->fetchedFrom);
        $linked = $server !== null ? SslCatalog::linked($server) : [];
        $rows = [];
        foreach ($this->fetched as $id => $entry) {
            $products = array_column($linked[$id] ?? [], 'product');
            $now = []; // currency => years => every price now of its products and plans (null: not offered)
            foreach ($linked[$id] ?? [] as $existing) {
                foreach ($existing['prices'] as $currency => $byYears) {
                    foreach ($byYears as $years => $all) {
                        $now[$currency][$years] = array_merge($now[$currency][$years] ?? [], $all);
                    }
                }
            }
            $new = SslCatalog::prices($entry, $options);
            $lines = [];
            foreach (array_unique(array_merge(array_keys($new), array_keys($now))) as $currency) {
                $years = array_unique(array_merge(array_keys($new[$currency] ?? []), array_keys($now[$currency] ?? [])));
                sort($years);
                foreach ($years as $y) {
                    $cost = isset($entry['costs'][$y]) ? SslCatalog::cost($entry['costs'][$y], $currency, $options) : null;
                    $cost = $cost !== null ? TldPriceMath::cents($cost) : null;
                    $all = $now[$currency][$y] ?? [];
                    $amounts = array_values(array_filter($all, fn ($p) => $p !== null));
                    usort($amounts, fn ($a, $b) => bccomp($a, $b, 2));
                    $low = $amounts[0] ?? null;
                    $high = $amounts !== [] ? end($amounts) : null;
                    $priceNew = $new[$currency][$y]['price'] ?? null;
                    // A free or not-offered price now is kept by the import (D-6): told, never shown as the new one (R2-2 of #348).
                    $kept = array_values(array_filter($all, fn ($p) => SslCatalog::keeps($p, $options)));
                    $lines[] = ['currency' => $currency, 'years' => $y, 'cost' => $cost, 'now' => $low, 'now_high' => $high !== $low ? $high : null,
                        'now_margin' => $low !== null && $cost !== null ? SslCatalog::marginPercent($low, $cost) : null,
                        'new' => $kept !== [] && count($kept) === count($all) ? null : $priceNew,
                        'new_margin' => $priceNew !== null && $cost !== null ? SslCatalog::marginPercent($priceNew, $cost) : null,
                        'kept_free' => $kept !== []];
                }
            }
            $rows[] = $entry + [
                'products' => array_map(fn ($product) => ['name' => $product->name, 'url' => ProductResource::getUrl('edit', ['record' => $product]),
                    'listed' => !$product->hidden], $products),
                'lines' => $lines,
            ];
        }
        usort($rows, fn ($a, $b) => [!$a['sellable'], $a['products'] === [], $a['brand'], $a['name']] <=> [!$b['sellable'], $b['products'] === [], $b['brand'], $b['name']]);

        return $rows;
    }

    /** Import the ticked products, all or nothing. */
    public function import(): void
    {
        $options = $this->options();
        $server = $this->server();
        if ($this->fetchedFrom !== $server->id) {
            Notification::make()->warning()->title(__('Fetch the products from this server first.'))->send();

            return;
        }
        $entries = [];
        foreach (array_unique(array_map('intval', $this->selected)) as $id) {
            $entry = $this->fetched[$id] ?? null;
            if (is_array($entry) && ($entry['sellable'] ?? false)) {
                $entries[$id] = $entry;
            }
        }
        if ($entries === []) {
            Notification::make()->warning()->title(__('Tick the products to import.'))->send();

            return;
        }
        $category = (int) ($this->form->getState()['category_id'] ?? 0);
        $linked = SslCatalog::linked($server);
        if (array_diff_key($entries, $linked) !== [] && !Category::query()->whereKey($category)->exists()) {
            Notification::make()->warning()->title(__('Choose the category of new products.'))->send();

            return;
        }
        try {
            $typed = $this->typedFor($entries, $options);
            $count = SslCatalog::import($server, array_values($entries), $options, $category, (bool) ($this->form->getState()['hidden'] ?? true), $typed);
        } catch (InvalidArgumentException $e) {
            Notification::make()->warning()->title(__('Nothing was imported'))->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title(__(':count product(s) imported.', ['count' => $count]))->send();
        $this->selected = [];
        $this->overrides = [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetch')->label(__('Fetch products and costs'))->icon('heroicon-o-arrow-path')->action(fn () => $this->fetch()),
            Action::make('importSelected')->label(__('Import selected'))->icon('heroicon-o-arrow-down-tray')
                ->requiresConfirmation()->modalDescription(fn () => __('The ticked products are made or get the new prices; nothing else changes.')
                    . (($below = $this->belowCostTicked()) > 0 ? ' ' . __(':count of these prices are below their cost.', ['count' => $below]) : ''))
                ->disabled(fn () => $this->fetched === [])->action(fn () => $this->import()),
        ];
    }
}
