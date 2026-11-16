<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Concerns\NamesTheClient;
use App\Admin\Resources\ClientResource\Widgets\ClientTransactionTotals;
use App\Admin\Resources\InvoiceResource;
use App\Classes\Price;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Gateway;
use App\Models\InvoiceTransaction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The client's payments, as WHMCS's «Transactions» tab (owner 2026-11-16): when, how, which invoice, the gateway's id,
 * the amount, its fee and what was refunded. Read only: a refund or a correction is made on the invoice, as before.
 */
class ShowTransactions extends ManageRelatedRecords
{
    use NamesTheClient;

    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'transactions';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-wallet-2-line';

    public static function getNavigationLabel(): string
    {
        return __('Transactions');
    }

    /** Staff who may see the transactions' list, and the client. */
    public static function canAccess(array $parameters = []): bool
    {
        return Gate::allows('viewAny', InvoiceTransaction::class) && parent::canAccess($parameters);
    }

    protected function getHeaderWidgets(): array
    {
        return [ClientTransactionTotals::class];
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('transaction_id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['invoice.currency', 'gateway']))
            ->columns([
                TextColumn::make('created_at')->label(__('Date'))->dateTime('d/m/Y H:i')->sortable(),
                // No gateway: paid from the account's credit, or recorded by hand.
                TextColumn::make('gateway.name')->label(__('Payment method'))
                    ->placeholder(fn (InvoiceTransaction $record) => $record->is_credit_transaction ? __('Account credit') : '—'),
                // An id, not an amount: «#7283» (owner 2026-11-13).
                TextColumn::make('invoice_id')->label(__('Invoice'))->prefix('#')->sortable()
                    ->url(fn (InvoiceTransaction $record): string => InvoiceResource::getUrl('edit', ['record' => $record->invoice_id])),
                TextColumn::make('transaction_id')->label(__('Transaction ID'))->placeholder('—')->searchable()->copyable(),
                TextColumn::make('formattedAmount')->label(__('Amount')),
                TextColumn::make('formattedFee')->label(__('Fee')),
                TextColumn::make('formattedRefundedAmount')->label(__('Refunded'))
                    ->formatStateUsing(fn ($state, InvoiceTransaction $record) => (float) $record->refunded_amount > 0 ? $state : '—'),
                // What of it became the client's credit: an overpayment's excess or a refund to credit (ADV-875-1).
                TextColumn::make('credited_amount')->label(__('To credit'))
                    ->formatStateUsing(fn ($state, InvoiceTransaction $record) => (float) $record->credited_amount > 0
                        ? (string) new Price(['price' => $record->credited_amount, 'currency' => $record->invoice->currency]) : '—'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->color(fn (InvoiceTransaction $record) => match ($record->status) {
                        InvoiceTransactionStatus::Succeeded => 'success',
                        InvoiceTransactionStatus::Processing => 'warning',
                        InvoiceTransactionStatus::Failed => 'danger',
                    })
                    ->formatStateUsing(fn (InvoiceTransactionStatus $state): string => __(ucfirst($state->value))),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('gateway_id')->label(__('Payment method'))
                    ->options(fn () => Gateway::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('invoice_transactions.created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('invoice_transactions.created_at', '<=', $date))),
            ]);
    }
}
