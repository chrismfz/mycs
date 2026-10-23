<?php

namespace App\Admin\Clusters;

use App\Models\Invoice;
use App\Support\AdminBadge;
use Filament\Clusters\Cluster;

class InvoiceCluster extends Cluster
{
    protected static string|\BackedEnum|null $navigationIcon = 'ri-receipt-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-receipt-fill';

    public static string|\UnitEnum|null $navigationGroup = 'Administration';

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
