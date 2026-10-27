<?php

namespace App\Admin\Resources\InvoiceResource\RelationManagers;

use App\Models\Invoice;
use App\Services\Money\MoneyHistory;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * «Κινήσεις» (docs/money-trail.md §3, MT-1d): everything that moved money on this invoice, from the journal, and its
 * status changes, from the audit. Read only: corrections are made where the money is (payments, notes, credit).
 */
class MoneyEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'moneyEvents';

    /** A payment recorded from the page's header: render again, with its movement. */
    #[On(TransactionsRelationManager::PAYMENTS_CHANGED)]
    public function paymentsChanged(): void {}

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Movements');
    }

    /** Like the Transactions tab: payments, credit balances, who and the staff reasons. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->hasPermission('admin.invoice_transactions.viewAny') && parent::canViewForRecord($ownerRecord, $pageClass);
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
            // Rows are arrays (journal and audit together), not models: nothing to open on click.
            ->recordAction(null)
            ->recordUrl(null)
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
