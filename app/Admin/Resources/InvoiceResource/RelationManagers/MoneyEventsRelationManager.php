<?php

namespace App\Admin\Resources\InvoiceResource\RelationManagers;

use App\Models\Invoice;
use App\Services\Money\MoneyHistory;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * «Κινήσεις» (docs/money-trail.md §3, MT-1d): everything that moved money on this invoice, from the journal, and its
 * status changes, from the audit. Read only: corrections are made where the money is (payments, notes, credit).
 */
class MoneyEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'moneyEvents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Movements');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $invoice = $this->getOwnerRecord();

                return $invoice instanceof Invoice ? (new MoneyHistory)->invoiceMovements($invoice) : [];
            })
            ->paginated(false)
            ->columns([
                TextColumn::make('at')->label(__('Date'))->dateTime(),
                TextColumn::make('what')->label(__('Movement'))->wrap(),
                TextColumn::make('amount')->label(__('Amount')),
                TextColumn::make('credit')->label(__('Credit after')),
                TextColumn::make('source')->label(__('Source')),
                TextColumn::make('transaction')->label(__('Transaction'))->prefix('#'),
                TextColumn::make('by')->label(__('By')),
                TextColumn::make('note')->label(__('Note'))->wrap(),
            ])
            ->emptyStateHeading(__('No movements yet'))
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
