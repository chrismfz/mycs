<?php

namespace App\Admin\Clusters;

use App\Models\Invoice;
use App\Support\AdminBadge;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;

class InvoiceCluster extends Cluster
{
    protected static string|\BackedEnum|null $navigationIcon = 'ri-receipt-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-receipt-fill';

    public static string|\UnitEnum|null $navigationGroup = 'Administration';

    // Its pages (Invoices, Transactions, gateway transactions) as tabs above the list, not a column on the left (owner
    // 2026-11-13: small screens), as the Services cluster.
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static ?string $navigationLabel = 'Invoices';

    public static function getNavigationBadge(): ?string
    {
        return AdminBadge::label('invoices.unpaid', fn () => Invoice::query()->where('status', Invoice::STATUS_UNPAID)->count());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
