<?php

namespace App\Admin\Resources;

use App\Admin\Resources\ProductResource\Pages\CreateProduct;
use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Admin\Resources\ProductResource\Pages\ListProducts;
use App\Classes\FilamentInput;
use App\Helpers\ExtensionHelper;
use App\Helpers\TemplateRenderer;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Server;
use App\Support\ProductSettings;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Component;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-instance-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-instance-fill';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Tabs')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('General')
                            ->columns(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                                        if (($get('slug') ?? '') !== Str::slug($old)) {
                                            return;
                                        }

                                        $set('slug', Str::slug($state));
                                    }),
                                TextInput::make('slug')->required()->unique(ignoreRecord: true),
                                // The link the company's site uses to sell it, also when it is hidden (not when retired).
                                TextInput::make('order_link')
                                    ->label('Order link')
                                    ->readOnly()
                                    ->dehydrated(false)
                                    ->copyable()
                                    ->afterStateHydrated(fn (TextInput $component, ?Product $record) => $component->state($record?->exists && $record->category
                                        ? route('products.checkout', ['category' => $record->category, 'product' => $record]) : null))
                                    ->helperText('Add ?plan= with a plan id to preselect its billing cycle.')
                                    ->visibleOn('edit')
                                    ->columnSpanFull(),
                                TextInput::make('stock')->integer()->nullable(),
                                TextInput::make('per_user_limit')->integer()->nullable(),
                                // WHMCS «Apply Tax» (Δ1): a product sold without VAT.
                                Toggle::make('taxable')->label('Apply tax')->default(true)
                                    ->helperText('Off: sold without tax (its invoice lines are not taxed).'),
                                // WHMCS «Prorata Billing» (decision 3, PR D): every service renews on the same day.
                                TextInput::make('prorata_day')->label('Pro rata day')->integer()->nullable()
                                    ->minValue(1)->maxValue(28)
                                    ->helperText('Day of the month every service of a monthly or yearly plan renews on; the first invoice is for the days up to it. Empty: off.'),
                                TextInput::make('prorata_next_after_day')->label('Charge next month after day')->integer()->nullable()
                                    ->minValue(1)->maxValue(31)
                                    ->gt('prorata_day')
                                    ->helperText('Ordered on or after this day, the first invoice also bills the next full period. Empty: never.'),
                                Select::make('allow_quantity')->options([
                                    'disabled' => 'No',
                                    'separated' => 'Separated',
                                    'combined' => 'Combined',
                                ])->default('separated')
                                    ->required(),
                                Textarea::make('email_template')
                                    ->hint('Shown as {{ product_info }} in the "service activated" email. Same template syntax, e.g. {{ service.product.name }}.')
                                    ->rule(TemplateRenderer::rule())
                                    ->nullable(),
                                Checkbox::make('hidden')
                                    ->label('Hide product')
                                    ->hint('Not listed in the shop; still ordered by its link. To stop orders, set the stock to 0.'),

                                RichEditor::make('description')->nullable()->columnSpanFull(),
                                FileUpload::make('image')
                                    ->label('Image')
                                    ->nullable()
                                    ->visibility('public')
                                    ->imageEditor()
                                    ->image()
                                    ->disk('public')
                                    ->acceptedFileTypes(['image/*']),
                                Select::make('category_id')
                                    ->relationship('category', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->createOptionForm(fn (Schema $schema) => CategoryResource::form($schema))
                                    ->required(),
                            ]),
                        Tab::make('Pricing')
                            ->schema([self::plan()]),

                        Tab::make('Upgrades')
                            ->schema([
                                // Select input for the products this product can upgrade to (hasmany relationship)
                                Select::make('upgrades')
                                    ->label('Upgrades')
                                    ->relationship('upgrades', 'name', ignoreRecord: true)
                                    ->multiple()
                                    ->preload()
                                    ->placeholder('Select the products that this product can upgrade to')
                                    // Only products of the same module (sweep §4): the account cannot move to another one.
                                    ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                                        $module = $get('server_id') ? Server::query()->find($get('server_id'))?->extension : null;
                                        $other = Product::query()->whereKey((array) $value)->with('server')->get()
                                            ->reject(fn (Product $product) => $product->server?->extension === $module)->pluck('name');
                                        if ($other->isNotEmpty()) {
                                            $fail(__('Not of this product\'s module: :products', ['products' => $other->implode(', ')]));
                                        }
                                    })
                                    ->helperText('Products of the same module only (the account stays on its server).'),
                            ]),

                        Tab::make('Server')
                            ->schema([
                                // WHMCS's Module Settings → «Auto Setup» (Σ1-b).
                                Radio::make('autosetup')->label('Auto Setup')->required()->default(Product::AUTOSETUP_PAYMENT)
                                    ->options([
                                        Product::AUTOSETUP_ORDER => 'As soon as an order is placed',
                                        Product::AUTOSETUP_PAYMENT => 'As soon as the first payment is received',
                                        Product::AUTOSETUP_ACCEPT => 'When the order is manually accepted',
                                        Product::AUTOSETUP_NEVER => 'Do not automatically setup this product',
                                    ])
                                    ->helperText('Until it is set up, a service stays pending (paid or not) in Pending Orders. Set up before payment, its first invoice is due from the order date: unpaid, it is suspended and terminated as any overdue one.'),
                                Select::make('server_id')
                                    ->relationship('server', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->hintAction(
                                        Action::make('refresh')
                                            ->label('Refresh')
                                            ->action(fn () => Cache::set('product_config', null, 0))
                                            ->hidden(fn (Get $get) => $get('server_id') === null)
                                    )
                                    ->live()
                                    ->afterStateUpdated(fn (Select $component) => $component
                                        ->getContainer()
                                        ->getComponent('extension_settings', withHidden: true)
                                        ->getChildSchema()
                                        ->fill()),
                                Select::make('server_group_id')
                                    ->label('Server Group')
                                    ->relationship('serverGroup', 'name')
                                    ->nullable()
                                    ->hidden(fn (Get $get) => $get('server_id') === null)
                                    ->helperText('New accounts go to a server of this group (WHMCS «Server Group»); empty: the server above.'),

                                Grid::make()
                                    ->hidden(fn (Get $get) => $get('server_id') === null)
                                    ->columns(2)
                                    ->key('extension_settings')
                                    ->schema(
                                        function (Get $get, Component $livewire) {
                                            $server = $get('server_id');
                                            if ($server == null) {
                                                return [];
                                            }
                                            $settings = [];

                                            try {
                                                foreach (ExtensionHelper::getProductConfigOnce(Server::findOrFail($server), $get('settings')) as $setting) {
                                                    // A secret already stored is not sent to the page: left empty, it is kept.
                                                    if (($setting['encrypted'] ?? false) && $livewire instanceof EditRecord
                                                        && ProductSettings::stored($livewire->getRecord())->where('key', $setting['name'])->exists()) {
                                                        $setting['required'] = false;
                                                        $setting['placeholder'] = __('Stored: leave empty to keep it');
                                                    }
                                                    // Easier to use dot notation for settings
                                                    $setting['name'] = 'settings.' . $setting['name'];
                                                    $settings[] = FilamentInput::convert($setting);
                                                }
                                            } catch (Exception $e) {
                                                $settings[] = TextEntry::make('error')->state($e->getMessage());
                                            }

                                            return $settings;
                                        }
                                    ),

                            ]),
                    ]),
            ])->columns(1);
    }

    public static function plan()
    {
        return Repeater::make('plan')
            ->addActionLabel('Add new plan')
            ->relationship('plans')
            ->name('name')
            ->reorderable()
            ->cloneable()
            ->collapsible()
            ->collapsed()
            ->orderColumn()
            ->defaultItems(1)
            ->minItems(1)
            ->columns(2)
            ->deleteAction(function (Action $action) {
                $action->before(function (?Product $record, $state, Action $action, array $arguments) {
                    if (!$record) {
                        return;
                    }
                    $key = $arguments['item'];
                    if (!isset($state[$key]['id'])) {
                        return;
                    }
                    $plan = $record->plans()->find($state[$key]['id']);
                    if ($plan?->inUse()) {
                        Notification::make()
                            ->title('Whoops!')
                            ->body(__('This plan is used by services or upgrades: hide it instead of deleting it.'))
                            ->danger()
                            ->send();
                        $action->cancel();
                    }
                });
            })
            ->itemLabel(fn (array $state) => $state['name'])
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->live(onBlur: true)
                    ->maxLength(255),
                Select::make('type')
                    ->options([
                        'free' => 'Free',
                        'one-time' => 'One Time',
                        'recurring' => 'Recurring',
                    ])
                    ->required()
                    ->live(debounce: 300)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                        if ($state === 'free') {
                            $set('every', null);
                            $set('price', 0);
                        }
                    })
                    ->placeholder('Select the type of the price')
                    ->default('free'),

                TextInput::make('billing_period')
                    ->required()
                    ->label('Time Interval')
                    ->default(1)
                    ->hidden(fn (Get $get) => $get('type') !== 'recurring'),

                Select::make('billing_unit')
                    ->options([
                        'day' => 'Day',
                        'week' => 'Week',
                        'month' => 'Month',
                        'year' => 'Year',
                    ])
                    ->label('Billing period')
                    ->required()
                    ->default('month')
                    ->hidden(fn (Get $get) => $get('type') !== 'recurring'),
                Repeater::make('pricing')
                    ->hidden(fn (Get $get) => $get('type') === 'free')
                    ->columns(3)
                    ->addActionLabel('Add new price')
                    ->reorderable(false)
                    ->relationship('prices')
                    ->columnSpanFull()
                    ->maxItems(Currency::count())
                    ->defaultItems(1)
                    ->itemLabel(fn (array $state) => $state['currency_code'])
                    ->schema([
                        Select::make('currency_code')
                            ->options(function (Get $get, ?string $state) {
                                $pricing = collect($get('../../pricing'))->pluck('currency_code');
                                if ($state !== null) {
                                    $pricing = $pricing->filter(function ($code) use ($state) {
                                        return $code !== $state;
                                    });
                                }
                                $pricing = $pricing->filter(function ($code) {
                                    return $code !== null;
                                });

                                return Currency::whereNotIn('code', $pricing)->pluck('code', 'code');
                            })
                            ->live()
                            ->default(config('settings.default_currency'))
                            ->required(),
                        TextInput::make('price')
                            ->required()
                            ->label('Price')
                            // Suffix based on chosen currency
                            ->prefix(fn (Get $get) => Currency::where('code', $get('currency_code'))->first()?->prefix)
                            ->suffix(fn (Get $get) => Currency::where('code', $get('currency_code'))->first()?->suffix)
                            ->live(onBlur: true)
                            ->mask(RawJs::make(
                                <<<'JS'
                                    $money($input, '.', '', 2)
                                JS
                            ))
                            ->numeric()
                            ->minValue(0)
                            ->hidden(fn (Get $get) => $get('type') === 'free'),
                        TextInput::make('setup_fee')
                            ->label('Setup fee')
                            ->live(onBlur: true)
                            ->mask(RawJs::make(
                                <<<'JS'
                                    $money($input, '.', '', 2)
                                JS
                            ))
                            ->numeric()
                            ->minValue(0)
                            ->hidden(fn (Get $get) => $get('type') === 'free'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->where('products.name', 'like', "%{$search}%");
                }),
                TextColumn::make('slug'),
                TextColumn::make('category.name')->searchable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderBy('sort', 'asc');
            })
            ->defaultGroup('category.name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
