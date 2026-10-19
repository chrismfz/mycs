<?php

namespace App\Admin\Resources\ClientResource\Pages;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ShowInvoices extends ManageRelatedRecords
{
    protected static string $resource = ClientResource::class;

    protected static string $relationship = 'invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-receipt-line';

    public static function getNavigationLabel(): string
    {
        return __('Invoices');
    }

    /**
     * As WHMCS's client «Invoices» tab: number, dates, total, what is still due and the status, the newest first. The
     * totals need the lines, notes and transactions: loaded with the rows, not per row.
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['items', 'adjustmentNotes', 'transactions', 'currency']))
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('number')->label(__('Invoice #'))->searchable()->sortable()->placeholder('—'),
                TextColumn::make('created_at')->label(__('Invoice Date'))->date()->sortable(),
                TextColumn::make('due_at')->label(__('Due Date'))->date()->sortable()->placeholder('—'),
                TextColumn::make('formattedTotal')->label(__('Total')),
                TextColumn::make('formattedCurrentBalance')->label(__('Remaining')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->formatStateUsing(fn (string $state): string => Invoice::statusLabel($state))
                    ->badge()
                    ->color(fn (string $state): string => Invoice::statusColor($state))
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(Invoice::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make()->url(fn ($record) => InvoiceResource::getUrl(config('settings.immutable_invoices_enabled', false) ? 'view' : 'edit', ['record' => $record])),
            ]);
    }
}
