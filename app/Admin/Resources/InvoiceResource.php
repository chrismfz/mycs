<?php

namespace App\Admin\Resources;

use App\Admin\Actions\MergeInvoicesAction;
use App\Admin\Actions\PayInvoicesAction;
use App\Admin\Clusters\InvoiceCluster;
use App\Admin\Components\ClientComponent;
use App\Admin\RelationManagers\EmailLogsRelationManager;
use App\Admin\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Admin\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\AdjustmentNotesRelationManager;
use App\Admin\Resources\InvoiceResource\RelationManagers\MoneyEventsRelationManager;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Admin\Search\SearchTitle;
use App\Exceptions\DisplayException;
use App\Models\Currency;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\RecordPaymentService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Exceptions\Halt;
use Filament\Support\RawJs;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    /** Its name in «Recently viewed» (the sidebar shows its kind beside it). */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (!$record instanceof Invoice) {
            return parent::getRecordTitle($record);
        }

        return (string) ($record->number ?: '#' . $record->getKey());
    }

    /** The admin search finds an invoice by its number or id, as WHMCS (owner 2026-10-23). */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'id'];
    }

    /** With what its title and details need, not one query each. */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['client.primaryContact', 'items', 'currency', 'adjustmentNotes']);
    }

    public static function getGlobalSearchResultTitle(Model $record): string|Htmlable
    {
        return $record instanceof Invoice
            ? SearchTitle::make(__('Invoice') . ' ' . self::getRecordTitle($record), Invoice::statusLabel($record->status), Invoice::statusColor($record->status))
            : (string) $record->getKey();
    }

    /** @return array<string, string> whose, how much and when due, on one line */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return !$record instanceof Invoice ? [] : array_filter([
            __('Client') => $record->client !== null ? $record->client->name . ' #' . $record->client_id : null,
            __('Total') => (string) $record->formattedTotal,
            __('Due Date') => $record->due_at?->format('d/m/Y'),
        ]);
    }

    // By id, as its URLs ({record:id}): Filament finds the record again on every request by this key, and the model's
    // own route key is the number when it is not numeric (Invoice::getRouteKey).
    protected static ?string $recordRouteKeyName = 'id';

    protected static ?string $cluster = InvoiceCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-receipt-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-receipt-fill';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', Invoice::STATUS_UNPAID)->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** Its issue date may change while it is a draft or unpaid (INV-17). */
    public static function issueDateEditable(Invoice $invoice): bool
    {
        return in_array($invoice->getOriginal('status'), [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID], true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ClientComponent::make('client_id'),
                TextInput::make('number')
                    ->label('Invoice Number')
                    ->helperText('The invoice number will be generated automatically')
                    ->disabled(),
                // Where it went or came from (PR E).
                TextEntry::make('merged_into_id')
                    ->label(__('Merged into'))
                    ->state(fn (?Invoice $record) => $record?->mergedInto ? '#' . ($record->mergedInto->number ?: $record->mergedInto->id) : null)
                    ->url(fn (?Invoice $record) => $record?->merged_into_id ? self::getUrl('edit', ['record' => $record->merged_into_id]) : null)
                    ->visible(fn (?Invoice $record): bool => $record?->merged_into_id !== null),
                TextEntry::make('split_from_id')
                    ->label(__('Split from'))
                    ->state(fn (?Invoice $record) => $record?->splitFrom ? '#' . ($record->splitFrom->number ?: $record->splitFrom->id) : null)
                    ->url(fn (?Invoice $record) => $record?->split_from_id ? self::getUrl('edit', ['record' => $record->split_from_id]) : null)
                    ->visible(fn (?Invoice $record): bool => $record?->split_from_id !== null),
                // Its date, as WHMCS's «Invoice Date» (INV-17: it was shown and never saved): today or before, and while it
                // is not paid or ended (reports count a paid one in its year). Saved by the pages (CreateInvoice,
                // EditInvoice::issuedAt): never fillable, so no other path moves it.
                DatePicker::make('created_at')
                    ->label('Issued At')
                    ->required()
                    ->default(now())
                    ->maxDate(fn () => now()->endOfDay())
                    ->disabled(fn (?Invoice $record): bool => $record !== null && !self::issueDateEditable($record))
                    ->helperText(fn (?Invoice $record): ?string => $record !== null && !self::issueDateEditable($record)
                        ? __('Fixed once the invoice is paid, cancelled or refunded.') : null)
                    ->placeholder('Select the date and time the invoice was issued'),
                DatePicker::make('due_at')
                    ->label('Due At')
                    ->required()
                    // Not before its issue date, while that is staff's to set (an old one, imported, stays as it is).
                    ->rule(fn (Get $get, ?Invoice $record) => function (string $attribute, mixed $value, \Closure $fail) use ($get, $record) {
                        // On a new one, or when staff move either date (adversarial ADV-1, code review 1): a renewal billed late
                        // is due before it was issued, and its other fields still save.
                        $moved = $record === null || ($get('created_at') !== null && Carbon::parse($get('created_at'))->toDateString() !== $record->created_at?->toDateString())
                            || ($value !== null && Carbon::parse($value)->toDateString() !== $record->due_at?->toDateString());
                        if ($moved && ($record === null || self::issueDateEditable($record)) && filled($get('created_at')) && filled($value)
                            && Carbon::parse($value)->startOfDay()->lt(Carbon::parse($get('created_at'))->startOfDay())) {
                            $fail(__('The due date cannot be before the issue date.'));
                        }
                    })
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
                    ->default(fn (): string => config('settings.immutable_invoices_enabled', false) ? Invoice::STATUS_DRAFT : Invoice::STATUS_UNPAID)
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
                            ->label('Price (before tax)')
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
                            // Stored and billed as a whole number (adversarial A1 of PR B): «0.99996» would bill nothing.
                            ->minValue(1)
                            ->rule('regex:/^\d+(\.0+)?$/')
                            ->placeholder('Enter the quantity of the product'),
                        // Δ1: the price is net; the line adds the account's tax when taxed (rate kept from when it was made).
                        Toggle::make('taxed')
                            ->label('Taxed')
                            ->default(true)
                            ->helperText(fn (Get $get): ?string => $get('tax_amount') !== null && (float) $get('tax_amount') != 0
                                ? 'Tax ' . rtrim(rtrim((string) $get('tax_rate'), '0'), '.') . '%: ' . $get('tax_amount')
                                : null),
                        // Several lines, as WHMCS's (DATA-12: the column is text).
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(1)
                            ->autosize()
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
            // The account's name is its owner's (until the contacts, PR 3): loaded with the rows, not per row.
            // Lines and payments too: whether an unpaid one is overdue reads them (Invoice::daysOverdue), not a query a row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client.primaryContact', 'client.ownerGrant.user', 'latestEmailLog', 'items', 'transactions']))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('number')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable(true, fn (Builder $query, string $search) => ClientComponent::search($query, $search)),
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
                // Due, and how late an unpaid one is (owner 2026-10-21: in time and late ones apart at a glance).
                TextColumn::make('due_at')
                    ->label(__('Due Date'))
                    ->date()
                    ->sortable()
                    ->placeholder('—')
                    ->color(fn (Invoice $record): ?string => $record->daysOverdue() > 0 ? 'danger' : null)
                    ->weight(fn (Invoice $record) => $record->daysOverdue() > 0 ? FontWeight::SemiBold : null)
                    ->description(fn (Invoice $record): ?string => $record->daysOverdue() > 0 ? trans_choice(':count day late|:count days late', $record->daysOverdue(), ['count' => $record->daysOverdue()]) : null),
                TextColumn::make('formattedTotal')
                    ->label('Total'),
                TextColumn::make('formattedCurrentBalance')
                    ->label('Remaining'),
                // Its latest mail (the mail audit, docs/mail.md): the error on hover.
                TextColumn::make('latestEmailLog.status')
                    ->label('Mail')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => EmailLogResource::statusLabel($state))
                    ->color(fn (?string $state) => EmailLogResource::statusColor($state))
                    ->tooltip(fn (Invoice $record) => $record->latestEmailLog instanceof EmailLog ? $record->latestEmailLog->error : null)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort(function (Builder $query): Builder {
                return $query
                    ->orderBy('id', 'desc');
            })
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Invoice::statusOptions()),
                // Unpaid past its due date (Invoice::scopeOverdue).
                Filter::make('overdue')
                    ->label(__('Overdue'))
                    ->query(fn (Builder $query) => Invoice::whereOverdue($query)),
                // Paid, its services not renewed yet (BIL-9): the cron's paid_applied tries again; the money check
                // reports one still waiting after a day (MT-3).
                // Its latest mail failed (the mail audit): to resend.
                Filter::make('mail_failed')
                    ->label('Last mail failed')
                    ->query(fn (Builder $query) => $query->whereHas('latestEmailLog', fn (Builder $query) => $query->where('status', EmailLog::STATUS_FAILED))),
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
                        // Δ3a and ADV-4 (owner 2026-10-17): as usual, the periods the cron has reached are given up and the farther ones
                        // (invoiced early) are billed at their time; staff may bill them all again (another price, a mistake) or give
                        // them all up («not renewing»).
                        Radio::make('periods')
                            ->label('Its periods')
                            ->options([
                                'auto' => 'As usual: those the cron has billed are given up, farther ones (invoiced early) are billed at their time',
                                'keep' => 'Bill them all again (to re-issue it)',
                                'give_up' => 'Give them all up (not renewing)',
                            ])
                            ->default('auto')
                            ->required(),
                    ])
                    ->action(function (Invoice $record, array $data) {
                        try {
                            $record->keep_periods_billable = match ($data['periods'] ?? 'auto') {
                                'keep' => true,
                                'give_up' => false,
                                default => null,
                            };
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
            ])
            ->toolbarActions([
                MergeInvoicesAction::make(),
                PayInvoicesAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        $relations = [
            TransactionsRelationManager::class,
        ];

        $relations[] = AdjustmentNotesRelationManager::class;
        $relations[] = MoneyEventsRelationManager::class;
        $relations[] = EmailLogsRelationManager::class;

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
