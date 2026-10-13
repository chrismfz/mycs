<?php

namespace App\Admin\Resources;

use App\Admin\Clusters\InvoiceCluster;
use App\Admin\Components\UserComponent;
use App\Admin\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Admin\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\AdjustmentNotesRelationManager;
use App\Admin\Resources\InvoiceResource\RelationManagers\MoneyEventsRelationManager;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Exceptions\DisplayException;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\RecordPaymentService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    // By id, as its URLs ({record:id}): Filament finds the record again on every request by this key, and the model's
    // own route key is the number when it is not numeric (Invoice::getRouteKey).
    protected static ?string $recordRouteKeyName = 'id';

    protected static ?string $cluster = InvoiceCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-receipt-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-receipt-fill';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'pending')->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                UserComponent::make('user_id'),
                TextInput::make('number')
                    ->label('Invoice Number')
                    ->helperText('The invoice number will be generated automatically')
                    ->disabled(),
                DatePicker::make('created_at')
                    ->label('Issued At')
                    ->required()
                    ->default(now())
                    ->placeholder('Select the date and time the invoice was issued'),
                DatePicker::make('due_at')
                    ->label('Due At')
                    ->required()
                    ->default(now()->addDays(7))
                    ->placeholder('Select the date and time the invoice is due'),
                Select::make('status')
                    ->label('Status')
                    ->required()
                    ->options(Invoice::statusOptions())
                    // Paid only by recording its payment (Mark Paid, docs/money-trail.md MT-1c): never on a new invoice,
                    // and on an existing one only when exactly nothing is left to pay. Paid over its total, it is closed
                    // with «Close as paid», which gives the part over as credit (2b). A cancelled invoice is reopened first.
                    // Refunded only by refunding its payments, and final (PR 2c); a paid invoice is refunded, never
                    // cancelled (INV-16).
                    ->disableOptionWhen(fn (string $value, ?Invoice $record): bool => ($value === Invoice::STATUS_PAID
                        && $record?->getOriginal('status') !== Invoice::STATUS_PAID
                        && ($record === null || $record->getOriginal('status') === Invoice::STATUS_CANCELLED
                            || RecordPaymentService::toCents($record->remaining) !== 0))
                        || ($value === Invoice::STATUS_REFUNDED) !== ($record?->getOriginal('status') === Invoice::STATUS_REFUNDED)
                        || ($value === Invoice::STATUS_CANCELLED && $record?->getOriginal('status') === Invoice::STATUS_PAID))
                    ->default(fn (): string => config('settings.immutable_invoices_enabled', false) ? Invoice::STATUS_DRAFT : Invoice::STATUS_PENDING)
                    ->placeholder('Select the status of the invoice'),
                Select::make('currency_code')
                    ->label('Currency')
                    ->required()
                    ->relationship('currency', 'code')
                    ->placeholder('Select the currency'),
                Toggle::make('send_email')
                    ->label('Send Email')
                    ->hiddenOn('edit')
                    ->default(true),
                Repeater::make('items')
                    ->relationship('items')
                    ->label('Items')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('price')
                            ->label('Price')
                            // Grab invoice currency
                            ->prefix(fn (Get $get): ?string => Currency::where('code', $get('../../currency_code'))->first()?->prefix)
                            ->suffix(fn (Get $get): ?string => Currency::where('code', $get('../../currency_code'))->first()?->suffix)
                            ->required()
                            ->numeric()
                            ->mask(RawJs::make(
                                <<<'JS'
                                    $money($input, '.', '', 2)
                                JS
                            ))
                            ->placeholder('Enter the price of the product'),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->required()
                            ->numeric()
                            ->placeholder('Enter the quantity of the product'),
                        TextInput::make('description')
                            ->label('Description')
                            ->required()
                            ->hintAction(
                                Action::make('View Service')
                                    ->url(function (Get $get) {
                                        return ServiceResource::getUrl('edit', ['record' => $get('reference_id')]);
                                    })
                                    ->label('View Service')
                                    ->hidden(fn (Get $get): bool => !in_array($get('reference_type'), [Service::class, ServiceUpgrade::class]))
                            )
                            ->placeholder('Enter the description of the product'),
                        // Shown for the link only, never written from the form: a browser could otherwise point a line at a
                        // Credit or at another customer's service (audit sweep AUTH-8).
                        Hidden::make('reference_type')->dehydrated(false),
                        Hidden::make('reference_id')->dehydrated(false),
                    ]),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable(true, fn (Builder $query, string $search) => $query->whereHas('user', fn (Builder $query) => $query->where('first_name', 'like', "%$search%")->orWhere('last_name', 'like', "%$search%"))),
                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (string $state): string => Invoice::statusLabel($state))
                    ->badge()
                    ->color(fn (string $state): string => Invoice::statusColor($state))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Issued At')
                    ->date()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('formattedTotal')
                    ->label('Total'),
                TextColumn::make('formattedCurrentBalance')
                    ->label('Remaining'),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderBy('id', 'desc');
            })
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Invoice::statusOptions()),
                // Paid, its services not renewed yet (BIL-9): the cron's paid_applied tries again; the money check
                // reports one still waiting after a day (MT-3).
                Filter::make('paid_not_applied')
                    ->label(__('Paid, not applied'))
                    ->query(fn (Builder $query) => $query->where('status', Invoice::STATUS_PAID)->whereNotNull('apply_pending_since')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (): bool => config('settings.immutable_invoices_enabled', false)),
                EditAction::make(),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->form([
                        TextInput::make('cancellation_reason')
                            ->label('Cancellation Reason')
                            ->required(),
                    ])
                    ->action(function (Invoice $record, array $data) {
                        try {
                            $record->update([
                                'status' => Invoice::STATUS_CANCELLED,
                                'cancellation_reason' => $data['cancellation_reason'],
                            ]);
                        } catch (DisplayException $e) { // it holds payments: refund them first (PR 2c)
                            Notification::make()->danger()->title(__('Invoice not cancelled'))->body($e->getMessage())->send();

                            throw new Halt;
                        }
                    })
                    ->visible(fn (Invoice $record): bool => auth()->user()->can('update', Invoice::class)
                        && !in_array($record->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_PAID, Invoice::STATUS_REFUNDED], true)),
            ]);
    }

    public static function getRelations(): array
    {
        $relations = [
            TransactionsRelationManager::class,
        ];

        $relations[] = AdjustmentNotesRelationManager::class;
        $relations[] = MoneyEventsRelationManager::class;

        return $relations;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/create'),
            // Always use id for invoice route binding in admin
            'view' => ViewInvoice::route('/{record:id}'),
            'edit' => EditInvoice::route('/{record:id}/edit'),
        ];
    }
}
