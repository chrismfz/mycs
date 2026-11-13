<?php

namespace App\Admin\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;

class Services extends Cluster
{
    protected static string|\BackedEnum|null $navigationIcon = 'ri-archive-stack-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-archive-stack-fill';

    public static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'services';

    // Its pages as tabs above the list, not a column on the left (owner 2026-11-13: small screens); a narrow screen
    // shows them as one select, as the client's pages.
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
