<?php

namespace App\Admin\Resources;

use App\Admin\Actions\CancelSubscriptionAction;
use App\Admin\Actions\OrderBulkActions;
use App\Admin\Components\ClientComponent;
use App\Admin\Resources\OrderResource\Pages\CreateOrder;
use App\Admin\Resources\OrderResource\Pages\EditOrder;
use App\Admin\Resources\OrderResource\Pages\ListOrders;
use App\Admin\Resources\OrderResource\RelationManagers\DomainRelationManager;
use App\Admin\Resources\OrderResource\RelationManagers\ServiceRelationManager;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Invoice;
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
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                // WHMCS «Order Notes»: the customer's at checkout, or staff's.
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->autosize()
                    ->maxLength(65535)
                    ->columnSpanFull(),
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
                            // A pro rata product's first invoice is this price for the days to its day (PR D).
                            ->helperText('The price it renews at, before tax: the invoice adds the client\'s tax. Empty: the catalog price. A pro rata product\'s first invoice is this price for the days up to its day. The setup fee is added to the first invoice. Options are not chosen here: add them on the service.'),
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

        $first = OrderPricing::firstPeriod($product, $plan, now());

        return $line->netPrice . (BigDecimal::of($line->netSetupFee)->isPositive() ? " (+ {$line->netSetupFee} setup fee)" : '') . ' + tax'
            // A pro rata product (decision 3, PR D): its first invoice is for the days to its day.
            . ($first === null ? '' : '; first invoice pro rata to ' . $first['end']->toDateString());
    }

    /** Pending Orders (Σ1-b2): waiting for staff's Accept, or for a service to be set up. */
    public static function getNavigationBadge(): ?string
    {
        $pending = Order::query()->where('status', Order::STATUS_PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            // The account's name is its owner's (until the contacts, PR 3): loaded with the rows, not per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client.primaryContact', 'client.ownerGrant.user', 'invoice']))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        Order::STATUS_PENDING => 'warning',
                        Order::STATUS_ACTIVE => 'success',
                        Order::STATUS_FRAUD => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable(query: fn (Builder $query, $search) => ClientComponent::search($query, (string) $search)),
                TextColumn::make('currency.code')
                    ->label('Currency')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('formattedTotal')
                    ->label('Total'),
                // WHMCS's «Payment Status»: its own invoice paid or not (an order with nothing to pay has none).
                TextColumn::make('payment_status')->label('Payment Status')->badge()
                    ->state(fn (Order $record): string => match ($record->invoice?->status) {
                        null => 'Nothing to pay',
                        Invoice::STATUS_PAID => 'Paid',
                        default => ucfirst((string) $record->invoice->status),
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Paid' => 'success',
                        'Unpaid' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(40)
                    ->tooltip(fn (Order $record): ?string => $record->notes)
                    ->toggleable(),
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
                SelectFilter::make('status')->options(array_combine(Order::STATUSES, array_map('ucfirst', Order::STATUSES))),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                // WHMCS's «With Selected» on Pending Orders.
                OrderBulkActions::accept(),
                OrderBulkActions::cancel(),
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
            DomainRelationManager::class,
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
