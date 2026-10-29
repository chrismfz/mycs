<?php

namespace App\Admin\Resources;

use App\Admin\Actions\MergeInvoicesAction;
use App\Admin\Actions\PayInvoicesAction;
use App\Admin\Actions\SendToEkdosi;
use App\Admin\Actions\SplitInvoiceAction;
use App\Admin\Clusters\InvoiceCluster;
use App\Admin\Components\ClientComponent;
use App\Admin\RelationManagers\EmailLogsRelationManager;
use App\Admin\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Admin\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Admin\Resources\InvoiceResource\RelationManagers\AdjustmentNotesRelationManager;
use App\Admin\Resources\InvoiceResource\RelationManagers\EkdosiDocumentsRelationManager;
use App\Admin\Resources\InvoiceResource\RelationManagers\MoneyEventsRelationManager;
use App\Admin\Resources\InvoiceResource\RelationManagers\TransactionsRelationManager;
use App\Admin\Search\SearchTitle;
use App\Exceptions\DisplayException;
use App\Models\EkdosiDocument;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\StaffLineEdit;
use App\Support\AdminBadge;
use App\Support\EkdosiNotice;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
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

    /**
     * What a cancellation does to the periods it bills (Δ3a, ADV-4), the same on the list and the invoice's page. Said as
     * what happens to the service (owner 2026-10-24): «as usual» to re-issue an invoice left an active service suspended
     * on its date with nothing to pay.
     *
     * @return array<string, string>
     */
    public static function cancelPeriods(): array
    {
        return [
            'auto' => __('As usual: not renewing what the cron billed (an active service is suspended on its date, with no invoice to pay, unless «Suspend only with an overdue invoice» is on); farther periods invoiced early are billed at their time'),
            'keep' => __('Re-issue: bill them all again (a new invoice, another price, a mistake)'),
            'give_up' => __('Give them all up (not renewing)'),
        ];
    }

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
        return AdminBadge::label('invoices.unpaid', fn () => Invoice::query()->where('status', Invoice::STATUS_UNPAID)->count());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * Why its lines keep their amounts now, as the Save would refuse them (InvoiceItemObserver, StaffLineEdit): paid or
     * refunded, a fiscal document from Ekdosi, or payments on an open one. Null while they can change. Read once per state
     * of the invoice: the form asks for every line.
     */
    public static function linesKeepAmounts(?Invoice $invoice): ?string
    {
        if ($invoice === null || !$invoice->exists) {
            return null;
        }

        return self::linesKeepAmountsAt($invoice->getKey() . '|' . $invoice->status . '|' . $invoice->updated_at?->getTimestamp(), $invoice);
    }

    /**
     * Once per request and state of the invoice: once() keys on what the closure uses, so it uses `$state` (review of
     * #483). A part payment changes neither status nor (within the second) updated_at: a page shows it from its next
     * request, as the payment actions make it do (TransactionsRelationManager::PAYMENTS_CHANGED).
     */
    private static function linesKeepAmountsAt(string $state, Invoice $invoice): ?string
    {
        return once(fn (): ?string => $state !== '' ? match (true) {
            in_array($invoice->status, Invoice::FROZEN_STATUSES, true) => __('A paid invoice keeps its lines: set it back to unpaid first.'),
            EkdosiDocument::activeOn($invoice->getKey()) => __('This invoice has a fiscal document from Ekdosi: its lines stay as they are. Cancel the document there first.'),
            // A cancelled one too: owing nothing, a repricing only matters reopened, and the reopening Save refuses it
            // (adversarial ADV-3 of #483).
            in_array($invoice->status, [...Invoice::OPEN_STATUSES, Invoice::STATUS_CANCELLED], true) && StaffLineEdit::holdsMoney($invoice, false) => __('This invoice has payments: its lines keep their amounts. Refund the payment first (back, or to account credit), or give a discount with a note.'),
            default => null,
        } : null);
    }

    /** The invoice of the page a line's field is on (inside the repeater, `$record` is the line). */
    private static function pageInvoice(mixed $livewire): ?Invoice
    {
        $record = is_object($livewire) && method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;

        return $record instanceof Invoice ? $record : null;
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
                // Its details, and beside them what it comes to and what is left (owner 2026-10-24).
                Grid::make(['default' => 1, 'lg' => 3])
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)
                            ->columnSpan(fn (string $operation): array|string => $operation === 'edit' ? ['default' => 1, 'lg' => 2] : 'full')
                            ->schema([
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
                                    // Cancelled here too: the fiscal document from Ekdosi stands (E2).
                                    ->helperText(fn (?Invoice $record): ?string => EkdosiNotice::forInvoice($record))
                                    ->placeholder('Select the status of the invoice'),
                                Select::make('currency_code')
                                    ->label('Currency')
                                    ->required()
                                    ->relationship('currency', 'code')
                                    ->placeholder('Select the currency'),
                                // As WHMCS's invoice payment method: its page opens with it, its email names it; the customer
                                // may still pay another way. Empty: the account's own (set when the invoice is made).
                                Select::make('preferred_gateway_id')
                                    ->label('Preferred payment method')
                                    ->relationship('preferredGateway', 'name')
                                    ->placeholder('None: every method offered')
                                    ->helperText('Chosen first on its payment page and named on its email\'s button; the customer may pay another way.'),
                                Toggle::make('send_email')
                                    ->label('Send Email')
                                    ->hiddenOn('edit')
                                    ->default(true),
                            ]),
                        View::make('admin.resources.invoice-resource.balance')
                            ->visible(fn (string $operation, ?Invoice $record): bool => $operation === 'edit' && $record !== null),
                    ]),
                // As an accountant reads it (owner 2026-10-24): the description first, a narrow quantity, the price before
                // tax and whether it is taxed; the amount as saved. A line chosen here can be split off to a new invoice.
                Repeater::make('items')
                    ->relationship('items')
                    ->label('Items')
                    ->columnSpanFull()
                    ->addActionLabel(__('Add line'))
                    // Its lines keep their amounts (paid, a fiscal document, payments on it): the form says so and offers no
                    // change the Save would refuse; descriptions still change (live test of 2026-10-27).
                    ->hint(fn (?Invoice $record): ?string => self::linesKeepAmounts($record))
                    ->hintColor('warning')
                    ->addable(fn (?Invoice $record): bool => self::linesKeepAmounts($record) === null)
                    ->deletable(fn (?Invoice $record): bool => self::linesKeepAmounts($record) === null)
                    ->table([
                        TableColumn::make(__('Select'))->hiddenHeaderLabel()->width('2.5rem'),
                        TableColumn::make(__('Description'))->markAsRequired(),
                        TableColumn::make(__('Qty'))->markAsRequired()->width('6rem'),
                        TableColumn::make(__('Unit price'))->markAsRequired()->width('9rem'),
                        TableColumn::make(__('VAT'))->width('4rem')->alignment(Alignment::Center),
                        TableColumn::make(__('Amount'))->width('8rem')->alignment(Alignment::End),
                    ])
                    ->schema([
                        // Chosen for «Split to a new invoice» below; never saved with the line.
                        Checkbox::make('selected')
                            ->hiddenLabel()
                            ->dehydrated(false)
                            ->visible(fn (string $operation): bool => $operation === 'edit'),
                        // Several lines, as WHMCS's (DATA-12: the column is text).
                        Textarea::make('description')
                            ->label('Description')
                            ->hiddenLabel()
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
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->hiddenLabel()
                            ->required()
                            ->numeric()
                            ->default(1)
                            // Stored and billed as a whole number (adversarial A1 of PR B): «0.99996» would bill nothing.
                            ->minValue(1)
                            ->rule('regex:/^\d+(\.0+)?$/')
                            ->disabled(fn ($livewire): bool => self::linesKeepAmounts(self::pageInvoice($livewire)) !== null),
                        TextInput::make('price')
                            ->label('Price (before tax)')
                            ->hiddenLabel()
                            // No currency beside it: in the table it left the price no room (the amount shows it).
                            ->required()
                            ->numeric()
                            ->mask(RawJs::make(
                                <<<'JS'
                                    $money($input, '.', '', 2)
                                JS
                            ))
                            ->extraInputAttributes(['class' => 'text-end tabular-nums'])
                            ->disabled(fn ($livewire): bool => self::linesKeepAmounts(self::pageInvoice($livewire)) !== null),
                        // Δ1: the price is net; the line adds the account's tax when taxed (rate kept from when it was made).
                        Checkbox::make('taxed')
                            ->label('Taxed')
                            ->hiddenLabel()
                            ->default(true)
                            ->disabled(fn ($livewire): bool => self::linesKeepAmounts(self::pageInvoice($livewire)) !== null),
                        // As the line was saved (net × quantity, its own rounding): worked out again on Save, never here.
                        TextEntry::make('amount')
                            ->hiddenLabel()
                            ->state(fn (?InvoiceItem $record): string => $record?->exists ? (string) $record->formattedTotal : '—')
                            ->tooltip(fn (?InvoiceItem $record): ?string => $record?->exists && (float) $record->tax_amount != 0
                                ? __('Tax :rate%: :amount', ['rate' => rtrim(rtrim((string) $record->tax_rate, '0'), '.'), 'amount' => $record->tax_amount])
                                : null)
                            ->extraAttributes(['class' => 'text-end tabular-nums']),
                        // Shown for the link only, never written from the form: a browser could otherwise point a line at a
                        // Credit or at another customer's service (audit sweep AUTH-8).
                        Hidden::make('reference_type')->dehydrated(false),
                        Hidden::make('reference_id')->dehydrated(false),
                    ]),
                Actions::make([SplitInvoiceAction::selected()])
                    ->key('lineActions')
                    ->visible(fn (string $operation, ?Invoice $record): bool => $operation === 'edit' && $record !== null)
                    ->columnSpanFull(),
                View::make('admin.resources.invoice-resource.totals')
                    ->visible(fn (string $operation, ?Invoice $record): bool => $operation === 'edit' && $record !== null)
                    ->columnSpanFull(),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // The account's name is its owner's (until the contacts, PR 3): loaded with the rows, not per row.
            // Lines and payments too: whether an unpaid one is overdue reads them (Invoice::daysOverdue), not a query a row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['client.primaryContact', 'client.ownerGrant.user', 'latestEmailLog', 'items', 'transactions', 'adjustmentNotes', 'currency', 'ekdosiDocuments']))
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
                // The fiscal document Ekdosi issued from it (E2): its code, struck through when cancelled.
                TextColumn::make('ekdosi_document')
                    ->label(__('Fiscal document'))
                    ->state(fn (Invoice $record): ?string => $record->ekdosiDocuments->map(fn (EkdosiDocument $document) => ($document->code ?: $document->mark)
                        . ($document->state === EkdosiDocument::CANCELLED ? ' ✗' : ''))->implode(', ') ?: null)
                    ->tooltip(fn (Invoice $record): ?string => $record->ekdosiDocuments->map(fn (EkdosiDocument $document) => __('MARK') . ' ' . $document->mark)->implode(', ') ?: null)
                    ->placeholder('—')
                    ->visible(fn (): bool => (bool) config('settings.ekdosi_enabled'))
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
                // Paid with no active fiscal document yet (E2): what Ekdosi has still to issue, or missed.
                SelectFilter::make('ekdosi_document')
                    ->label(__('Fiscal document'))
                    ->options(['with' => __('Issued'), 'without' => __('Paid, not issued')])
                    ->visible(fn (): bool => (bool) config('settings.ekdosi_enabled'))
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'with' => $query->whereHas('ekdosiDocuments', fn (Builder $q) => $q->where('state', EkdosiDocument::ACTIVE)),
                        // Paid (what Ekdosi should have issued from): not every open invoice.
                        'without' => $query->where('status', Invoice::STATUS_PAID)
                            ->whereDoesntHave('ekdosiDocuments', fn (Builder $q) => $q->where('state', EkdosiDocument::ACTIVE)),
                        default => $query,
                    }),
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
                    // A fiscal document from Ekdosi stands: staff are told before they cancel (E2).
                    ->modalDescription(fn (Invoice $record): ?string => EkdosiNotice::forInvoice($record))
                    ->form([
                        TextInput::make('cancellation_reason')
                            ->label('Cancellation Reason')
                            ->required(),
                        // Δ3a and ADV-4 (owner 2026-10-17): as usual, the periods the cron has reached are given up and the farther ones
                        // (invoiced early) are billed at their time; staff may bill them all again (another price, a mistake) or give
                        // them all up («not renewing»).
                        Radio::make('periods')
                            ->label('Its periods')
                            ->options(self::cancelPeriods())
                            ->helperText(fn (?Invoice $record): ?string => EkdosiNotice::forInvoice($record) === null ? __('A duplicate with no payment on it: delete it instead.') : null)
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
                            Notification::make()->danger()->title(__('Invoice not cancelled'))->body(e($e->getMessage()))->send();

                            throw new Halt;
                        }
                    })
                    ->visible(fn (Invoice $record): bool => auth()->user()->can('update', Invoice::class)
                        && !in_array($record->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_PAID, Invoice::STATUS_REFUNDED], true)),
            ])
            ->toolbarActions([
                MergeInvoicesAction::make(),
                PayInvoicesAction::make(),
                SendToEkdosi::bulk(),
            ]);
    }

    public static function getRelations(): array
    {
        $relations = [
            TransactionsRelationManager::class,
        ];

        $relations[] = EkdosiDocumentsRelationManager::class;
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
