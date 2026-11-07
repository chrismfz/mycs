<?php

namespace App\Admin\Resources;

use App\Admin\Actions\CancelSubscriptionAction;
use App\Admin\Actions\OrderBulkActions;
use App\Admin\Components\ClientComponent;
use App\Admin\Resources\OrderResource\OrderSummary;
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
use App\Support\AdminBadge;
use Brick\Math\BigDecimal;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    /** Its name in «Recently viewed» (the sidebar shows its kind beside it). */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (!$record instanceof Order) {
            return parent::getRecordTitle($record);
        }

        return '#' . $record->getKey();
    }

    protected static string|\BackedEnum|null $navigationIcon = 'ri-shopping-bag-4-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-shopping-bag-4-fill';

    public static string|\UnitEnum|null $navigationGroup = 'Administration';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // WHMCS's order view (owner 2026-10-19): what came in, at a glance, above what staff may change.
                Section::make('Order')->visibleOn('edit')->columns(2)->columnSpanFull()->schema([
                    TextEntry::make('created_at')->label('Date')->dateTime(),
                    TextEntry::make('amount')->label('Amount')
                        ->state(fn (Order $record) => (string) (OrderSummary::invoice($record)->formattedTotal ?? $record->formattedTotal)),
                    TextEntry::make('id')->label('Order #'),
                    TextEntry::make('invoice')->label('Invoice #')
                        ->state(fn (Order $record) => $record->invoice_id === null ? '—' : '#' . (OrderSummary::invoice($record)->number ?? $record->invoice_id))
                        ->url(fn (Order $record) => ($invoice = OrderSummary::invoice($record)) !== null && InvoiceResource::canView($invoice)
                            ? InvoiceResource::getUrl('view', ['record' => $invoice->id]) : null),
                    TextEntry::make('client_summary')->label('Client')
                        ->state(fn (Order $record) => self::clientSummary($record))
                        ->url(fn (Order $record) => $record->client !== null && ClientResource::canView($record->client)
                            ? ClientResource::getUrl('view', ['record' => $record->client_id]) : null)
                        ->extraAttributes(['class' => 'whitespace-pre-line']),
                    TextEntry::make('status')->label('Status')->badge()
                        ->formatStateUsing(fn (string $state) => ucfirst($state))
                        ->color(fn (string $state) => match ($state) {
                            Order::STATUS_PENDING => 'warning',
                            Order::STATUS_ACTIVE => 'success',
                            Order::STATUS_FRAUD => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('payment_method')->label('Payment Method')
                        ->state(fn (Order $record) => OrderSummary::paymentMethod(OrderSummary::invoice($record))),
                    TextEntry::make('payment_status')->label('Payment Status')->badge()
                        ->state(fn (Order $record) => OrderSummary::paymentStatus(OrderSummary::invoice($record)))
                        ->color(fn (string $state) => match ($state) {
                            'Paid' => 'success',
                            'Unpaid' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('ip_address')->label('IP Address')->placeholder('—')->copyable(),
                    TextEntry::make('promotion_code')->label('Promotion Code')
                        ->state(fn (Order $record) => OrderSummary::promotionCode($record)),
                    TextEntry::make('accepted')->label('Accepted')
                        ->state(fn (Order $record) => $record->accepted_at === null ? '—'
                            : $record->accepted_at->toDateTimeString() . ($record->acceptedBy ? ' · ' . $record->acceptedBy->name : '')),
                ]),
                Section::make('Order Items')->visibleOn('edit')->columnSpanFull()->schema([
                    View::make('admin.orders.items'),
                ]),
                // Fixed once the order exists (sweep ADMIN-3): its services and invoices are the client's, and only
                // MoveToClient (the service's «Move to another client», admin.services.move) moves them, with their
                // unpaid invoices, and the order along when it is only theirs. A new currency would keep the old figures.
                ClientComponent::make('client_id')
                    ->disabledOn('edit'),
                Select::make('currency_code')
                    ->label('Currency')
                    ->required()
                    ->live()
                    ->disabledOn('edit')
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
                // Read only (sweep ADMIN-1/2): the order page shows its services and never writes them. Saving the
                // order wrote every row back as loaded (undoing an upgrade, the cron's repricing, a cleared
                // subscription), and a removed row was a plain delete() without DeleteService and its permission.
                // Changes are made on the service's own page. The subscription's «Cancel» hint stays: it acts by itself.
                Repeater::make('services')
                    ->visibleOn('edit')
                    ->relationship('services')
                    ->saveRelationshipsUsing(null)
                    ->dehydrated(false)
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->label('Services')
                    ->helperText('Change a service on its own page.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('product_id')
                            ->label('Product')
                            ->disabled()
                            ->relationship(
                                name: 'product',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn (Builder $query) => $query->with('category')
                            )
                            ->getOptionLabelFromRecordUsing(fn (Product $product) => "{$product->name} - {$product->category->name} (#{$product->id})"),
                        Select::make('plan_id')
                            ->label('Plan')
                            ->disabled()
                            ->relationship('plan', 'name'),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->disabled(),
                        TextInput::make('price')
                            ->suffix(fn (Component $component) => $component->getRecord()?->currency?->suffix)
                            ->prefix(fn (Component $component) => $component->getRecord()?->currency?->prefix)
                            ->label('Price')
                            ->disabled(),
                        TextInput::make('subscription_id')
                            ->label('Subscription ID')
                            ->disabled()
                            ->hintActions([
                                CancelSubscriptionAction::make(),
                            ]),
                    ]),
            ]);
    }

    /**
     * WHMCS's «Client» on the order: the account's name, and its primary contact's address for staff who may see the
     * account (code review: not to those with orders only).
     */
    private static function clientSummary(Order $order): string
    {
        $contact = $order->client !== null && ClientResource::canView($order->client) ? $order->client->primaryContact : null;
        $place = trim(implode(' ', array_filter([$contact?->postcode, $contact?->city])));

        return implode("\n", array_filter([
            ($order->client->name ?? '') . ' (#' . $order->client_id . ')',
            trim(implode(' ', array_filter([$contact?->address1, $contact?->address2]))),
            implode(', ', array_filter([$place, $contact?->state, $contact?->country])),
        ]));
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
        return AdminBadge::label('orders.pending', fn () => Order::query()->where('status', Order::STATUS_PENDING)->count());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            // The account's name is its owner's (until the contacts, PR 3): loaded with the rows, not per row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client.primaryContact', 'client.ownerGrant.user', 'invoice', 'services.client', 'services.product', 'domains', 'currency']))
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
                TextColumn::make('ip_address')->label('IP Address')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(40)
                    ->tooltip(fn (Order $record): ?string => $record->notes)
                    ->toggleable(),
                // When it was ordered, as WHMCS's list (the last change in a tooltip).
                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime()
                    ->tooltip(fn (Order $record): ?string => $record->updated_at?->format('d/m/Y H:i'))
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
