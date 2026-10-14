<?php

namespace App\Admin\Resources;

use App\Admin\Actions\CancelSubscriptionAction;
use App\Admin\Components\ClientComponent;
use App\Admin\Resources\OrderResource\Pages\CreateOrder;
use App\Admin\Resources\OrderResource\Pages\EditOrder;
use App\Admin\Resources\OrderResource\Pages\ListOrders;
use App\Admin\Resources\OrderResource\RelationManagers\ServiceRelationManager;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Services\Order\OrderPricing;
use Brick\Math\BigDecimal;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-shopping-bag-4-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-shopping-bag-4-fill';

    public static string|\UnitEnum|null $navigationGroup = 'Administration';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ClientComponent::make('client_id')
                    ->afterStateUpdated(function (Set $set, Get $get) {
                        // the services are the order's account's
                        $set('services', collect($get('services'))->map(fn ($service) => array_merge($service, ['client_id' => $get('client_id')]))->toArray());
                    }),
                Select::make('currency_code')
                    ->label('Currency')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get) {
                        // update all the services currency_code
                        $set('services', collect($get('services'))->map(fn ($service) => array_merge($service, ['currency_code' => $get('currency_code')]))->toArray());
                    })
                    ->options(Currency::query()->pluck('code', 'code'))
                    ->helperText('Does not convert the price, only displays the currency symbol')
                    ->placeholder('Select the currency'),
                // Creating: the lines go to the PlaceOrderService (Σ7, CART-4), which prices them as checkout does.
                Repeater::make('lines')
                    ->label('Services')
                    ->visibleOn('create')
                    ->required()
                    ->minItems(1)
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('product_id')
                            ->label('Product')
                            ->required()
                            ->options(fn () => Product::query()->with('category')->get()->mapWithKeys(fn (Product $product) => [$product->id => $product->name . ' - ' . data_get($product, 'category.name') . " (#{$product->id})"]))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('plan_id', null))
                            ->placeholder('Select the product'),
                        Select::make('plan_id')
                            ->label('Plan')
                            ->required()
                            ->options(fn (Get $get) => $get('product_id') ? Plan::query()->where('priceable_type', Product::class)->where('priceable_id', $get('product_id'))->pluck('name', 'id') : [])
                            ->live()
                            ->disabled(fn (Get $get) => !$get('product_id') || !$get('../../currency_code'))
                            ->placeholder('Select the plan'),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->default(1),
                        TextInput::make('price')
                            ->label('Recurring price')
                            ->nullable()
                            ->numeric()
                            ->minValue(0)
                            ->prefix(fn (Get $get) => Currency::query()->find($get('../../currency_code'))?->prefix)
                            ->suffix(fn (Get $get) => Currency::query()->find($get('../../currency_code'))?->suffix)
                            ->placeholder(fn (Get $get) => self::catalogPrice($get) ?? 'Catalog price')
                            ->helperText('Before tax: the invoice adds the client\'s tax. Empty: the catalog price. The setup fee is added to the first invoice. Options are not chosen here: add them on the service.'),
                    ]),
                Repeater::make('services')
                    ->visibleOn('edit')
                    ->relationship('services')
                    ->label('Services')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Hidden::make('client_id')
                            ->default(fn (Get $get) => $get('../../client_id')),
                        Hidden::make('currency_code')
                            ->default(fn (Get $get) => $get('../../currency_code')),
                        Select::make('product_id')
                            ->label('Product')
                            ->required()
                            ->relationship(
                                name: 'product',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->with('category')
                            )
                            ->getOptionLabelFromRecordUsing(fn (Product $product) => "{$product->name} - {$product->category->name} (#{$product->id})")
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('plan_id', null))
                            ->placeholder('Select the product'),
                        Select::make('plan_id')
                            ->label('Plan')
                            ->required()
                            ->relationship('plan', 'name', fn (Builder $query, Get $get) => $query->where('priceable_type', Product::class)->where('priceable_id', $get('product_id')))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->disabled(fn (Get $get) => (!$get('product_id') || !$get('../../currency_code')))
                            ->afterStateUpdated(function (Set $set, Get $get) {
                                if (!$get('product_id') || !$get('plan_id') || !$get('../../currency_code')) {
                                    return;
                                }
                                // Update the price when the plan changes
                                $plan = Product::find($get('product_id'))->plans->find($get('plan_id'))->prices->where('currency_code', $get('../../currency_code'))->first();
                                if (!$plan) {
                                    return;
                                }
                                $set('price', $plan->price);
                            })
                            ->placeholder('Select the plan'),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->placeholder('Enter the quantity'),
                        TextInput::make('price')
                            ->suffix(fn (Component $component, Get $get) => $component->getRecord()?->currency->suffix ?? Currency::where('code', $get('../../currency_code'))->first()?->suffix)
                            ->prefix(fn (Component $component, Get $get) => $component->getRecord()?->currency->prefix ?? Currency::where('code', $get('../../currency_code'))->first()?->prefix)
                            ->label('Price')
                            ->required()
                            ->mask(RawJs::make(
                                <<<'JS'
                                    $money($input, '.', '', 2)
                                JS
                            ))
                            ->placeholder('Enter the price'),
                        TextInput::make('subscription_id')
                            ->label('Subscription ID')
                            ->nullable()
                            ->placeholder('Enter the subscription ID')
                            ->hintActions([
                                CancelSubscriptionAction::make(),
                            ]),
                    ]),
            ]);
    }

    /** The line's price as checkout would charge it, for the form's placeholder. */
    private static function catalogPrice(Get $get): ?string
    {
        $product = $get('product_id') ? Product::query()->find($get('product_id')) : null;
        $plan = $get('plan_id') ? Plan::query()->find($get('plan_id')) : null;
        $currency = $get('../../currency_code');
        if ($product === null || $plan === null || !$currency || $plan->priceable_id !== $product->id) {
            return null;
        }
        $catalog = OrderPricing::catalog($product, $plan, [], $currency);
        if ($catalog === null) {
            return 'Not available in this currency';
        }
        $line = OrderPricing::line($catalog, null, $get('../../client_id') ? Client::query()->find($get('../../client_id')) : null, $product);

        return $line->netPrice . (BigDecimal::of($line->netSetupFee)->isPositive() ? " (+ {$line->netSetupFee} setup fee)" : '') . ' + tax';
    }

    public static function table(Table $table): Table
    {
        return $table
            // The account's name is its owner's (until the contacts, PR 3): loaded with the rows, not per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client.primaryContact', 'client.ownerGrant.user']))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable(query: fn (Builder $query, $search) => ClientComponent::search($query, (string) $search)),
                TextColumn::make('currency.code')
                    ->label('Currency')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('formattedTotal')
                    ->label('Total'),
                TextColumn::make('updated_at')
                    ->label('Updated At')
                    ->searchable()
                    ->sortable(),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderBy('id', 'desc');
            })
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // An order with services is refused by its guard (DATA-2) and counted as not deleted (R6 of #82).
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ServiceRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'create' => CreateOrder::route('/create'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}
