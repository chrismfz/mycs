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
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * WHMCS's «TLD Import & Pricing Sync» (docs/domains.md D-6): staff pick a registrar connection and some TLDs, a margin,
 * a rounding, the years and the rates for MyCS's other currencies; «Fetch» reads the registrar's costs and shows the
 * prices the margin would make beside today's; «Import» writes only the TLDs ticked. Nothing is written by «Fetch».
 *
 * @property-read Schema $form
 */
class ImportTlds extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = DomainTldResource::class;

    protected string $view = 'admin.resources.domain-tld-resource.pages.import-tlds';

    protected static ?string $title = 'TLD Import & Pricing Sync';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /**
     * What the registrar answered, per TLD. Locked: written only by fetch() on the server, never from the browser (a
     * forged cost would become a price: review F2).
     *
     * @var array<string, array{costs?: array<string, array{cost: string, currency: string}>, error?: string}>
     */
    #[Locked]
    public array $fetched = [];

    /** The connection the costs were fetched from: the import uses it, and only it. */
    #[Locked]
    public ?int $fetchedFrom = null;

    /** @var list<string> the TLDs ticked; nothing is ticked by default (WHMCS too) */
    public array $selected = [];

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
    }

    /** @return array<int|string, string> usable connections whose registrar has a price list */
    public static function connections(): array
    {
        $factory = app(DomainRegistrarFactory::class);

        return DomainRegistrarConnection::query()->orderBy('label')->get()
            ->filter(fn (DomainRegistrarConnection $c) => $c->isUsable() && $factory->for($c)->capabilities()->supportsPricingSync)
            ->mapWithKeys(fn (DomainRegistrarConnection $c) => [$c->id => $c->label . ' (' . $c->mode . ')'])->all();
    }

    public function form(Schema $schema): Schema
    {
        $currencies = Currency::query()->orderBy('code')->pluck('code')->all();

        return $schema->statePath('data')->columns(1)->components([
            Section::make(__('Registrar and TLDs'))->columns(2)->schema([
                Select::make('connection_id')->label(__('Registrar'))->options(fn () => self::connections())->required(),
                Textarea::make('tlds')->label('TLDs')->rows(2)->required()
                    ->helperText(__('Separated by commas or spaces, without the dot: com, net, org, eu.')),
            ]),
            Section::make(__('Pricing'))->columns(3)->schema([
                Select::make('margin_type')->label(__('Margin type'))->required()
                    ->options([TldPriceMath::MARGIN_PERCENT => __('Percentage'), TldPriceMath::MARGIN_FIXED => __('Fixed amount')]),
                TextInput::make('margin')->label(__('Profit margin'))->required()->regex('/^\d+(\.\d{1,2})?$/'),
                Select::make('rounding')->label(__('Round to next'))->required()
                    ->options(['none' => __('No rounding'), '.00' => 'x.00', '.50' => 'x.50', '.99' => 'x.99']),
                Select::make('years')->label(__('Register and renew up to (years)'))->required()
                    ->options(collect(range(1, 10))->mapWithKeys(fn (int $n) => [$n => (string) $n])->all())
                    ->helperText(__('2 to N years at N times the one-year price.')),
                Toggle::make('redemption_fee')->label(__('Import the grace and redemption fees'))->helperText(__('The restore fee, with the same margin: on the redemption, or on the grace where the TLD has no redemption.')),
                Toggle::make('auto_register')->label(__('Automatic registration with this registrar')),
                Toggle::make('keep_free_and_closed')->label(__('Keep free and not-offered prices'))
                    ->helperText(__('A 0.00 (free) or empty (not offered) price stays as it is.')),
            ]),
            Section::make(__('Exchange rates'))->columns(4)
                ->description(__('MyCS keeps no rates: one unit of the registrar\'s currency is how much of each other currency. A currency left empty gets no price from the import.'))
                ->schema(array_map(fn (string $code) => TextInput::make("rates.{$code}")->label($code)
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

    /** @return list<string> the TLDs typed, in one form, at most 100 */
    private function tlds(): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string) ($this->data['tlds'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tld) {
            try {
                $out[] = Domain::normalise(ltrim($tld, '.'));
            } catch (InvalidArgumentException) {
                // not a TLD: skipped
            }
        }

        return array_slice(array_values(array_unique($out)), 0, 100);
    }

    /** Read the registrar's costs; writes nothing. */
    public function fetch(): void
    {
        $connection = $this->connection();
        $this->fetched = [];
        foreach (app(TldImport::class)->fetch($connection, $this->tlds()) as $tld => $answer) {
            $this->fetched[$tld] = $answer instanceof TldPricing ? ['costs' => $answer->costs] : ['error' => $answer];
        }
        $this->fetchedFrom = $connection->id;
        $this->selected = []; // nothing ticked: an import is staff's choice, TLD by TLD (review 2)
    }

    /**
     * The rows the page shows: per TLD, per currency, the price now and the price the import would write (1 year).
     *
     * @return array<string, array<string, mixed>>
     */
    public function rows(): array
    {
        if ($this->fetched === []) {
            return [];
        }
        try {
            return $this->buildRows($this->options());
        } catch (Throwable) {
            return []; // an invalid option shows its form error, never a broken page
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function buildRows(TldImportOptions $options): array
    {
        $import = app(TldImport::class);
        $rows = [];
        foreach ($this->fetched as $tld => $row) {
            if (isset($row['error'])) {
                $rows[$tld] = ['error' => $row['error']];

                continue;
            }
            $existing = DomainTld::query()->where('tld', $tld)->first();
            $new = $import->prices(new TldPricing(tld: $tld, costs: $row['costs'] ?? []), $options);
            $rows[$tld] = ['costs' => $row['costs'] ?? [], 'exists' => $existing !== null, 'currencies' => []];
            foreach ($new as $currency => $operations) {
                foreach ($operations as $operation => $byYears) {
                    $now = $existing?->prices()->where('operation', $operation)->where('years', 1)->where('currency_code', $currency)->value('price');
                    $rows[$tld]['currencies'][$currency][$operation] = ['now' => $now, 'new' => $byYears[1]['price']];
                }
            }
        }

        return $rows;
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
        $pricings = [];
        foreach ($this->selected as $tld) {
            $costs = $this->fetched[$tld]['costs'] ?? null;
            if (is_array($costs) && $costs !== []) {
                $pricings[$tld] = new TldPricing(tld: $tld, costs: $costs);
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetch')->label(__('Fetch costs'))->icon('heroicon-o-arrow-path')->action(fn () => $this->fetch()),
            Action::make('importSelected')->label(__('Import selected'))->icon('heroicon-o-arrow-down-tray')
                ->requiresConfirmation()->modalDescription(__('The ticked TLDs and their prices are written; other prices stay as they are.'))
                ->disabled(fn () => $this->fetched === [])->action(fn () => $this->import()),
        ];
    }
}
