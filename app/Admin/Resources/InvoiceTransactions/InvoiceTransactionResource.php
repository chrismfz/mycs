<?php

namespace App\Admin\Resources\InvoiceTransactions;

use App\Admin\Clusters\InvoiceCluster;
use App\Admin\Resources\InvoiceTransactions\Pages\ListInvoiceTransactions;
use App\Admin\Resources\InvoiceTransactions\Tables\InvoiceTransactionsTable;
use App\Models\InvoiceTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class InvoiceTransactionResource extends Resource
{
    protected static ?string $model = InvoiceTransaction::class;

    // One name for the page, WHMCS's, in the top menu, the sidebar and its title (owner 2026-10-21).
    protected static ?string $navigationLabel = 'Transactions';

    protected static ?string $pluralModelLabel = 'Transactions';

    protected static ?string $modelLabel = 'Transaction';

    protected static ?string $cluster = InvoiceCluster::class;

    protected static string|BackedEnum|null $navigationIcon = 'ri-wallet-2-line';

    protected static string|BackedEnum|null $activeNavigationIcon = 'ri-wallet-2-fill';

    protected static ?string $recordTitleAttribute = 'transaction_id';

    public static function table(Table $table): Table
    {
        return InvoiceTransactionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoiceTransactions::route('/'),
        ];
    }
}
