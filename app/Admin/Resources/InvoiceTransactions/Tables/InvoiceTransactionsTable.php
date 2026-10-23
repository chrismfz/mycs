<?php

namespace App\Admin\Resources\InvoiceTransactions\Tables;

use App\Admin\Actions\DeleteInvoiceTransaction;
use App\Admin\Resources\InvoiceResource;
use App\Enums\InvoiceTransactionStatus;
use App\Models\InvoiceTransaction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class InvoiceTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Each row's amounts are formatted in its invoice's currency: loaded with the page, not a query a row.
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['invoice.currency', 'gateway']))
            ->columns([
                TextColumn::make('invoice_id')
                    ->url(fn (InvoiceTransaction $record): string => InvoiceResource::getUrl(config('settings.immutable_invoices_enabled', false) ? 'view' : 'edit', ['record' => $record->invoice_id]))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('gateway.name')
                    ->sortable(),
                TextColumn::make('formattedAmount')
                    ->label('Amount')
                    ->sortable(),
                TextColumn::make('formattedFee')
                    ->label('Fee')
                    ->sortable(),
                TextColumn::make('transaction_id')
                    ->searchable(),
                TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(fn (InvoiceTransaction $record) => match ($record->status) {
                        InvoiceTransactionStatus::Succeeded => 'success',
                        InvoiceTransactionStatus::Processing => 'warning',
                        InvoiceTransactionStatus::Failed => 'danger',
                        default => null,
                    })
                    ->formatStateUsing(fn (InvoiceTransactionStatus $state): string => ucfirst($state->value))
                    ->label('Status'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->defaultSort('id', 'desc') // as created, by the primary key
            ->recordActions([
                DeleteAction::make()
                    ->visible(fn (InvoiceTransaction $record): bool => DeleteInvoiceTransaction::allowed($record->invoice))
                    ->schema([DeleteInvoiceTransaction::negativeCreditField()])
                    ->using(fn (InvoiceTransaction $record, array $data): bool => DeleteInvoiceTransaction::run($record, (bool) ($data['allow_negative_credit'] ?? false))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // One by one through the payments service, never a mass query delete.
                    DeleteBulkAction::make()
                        ->using(fn (Collection $records) => $records->each(fn (InvoiceTransaction $record) => DeleteInvoiceTransaction::run($record))),
                ]),
            ]);
    }
}
