<?php

namespace App\Admin\Resources\IpPoolResource\Pages;

use App\Admin\Resources\IpPoolResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** A pool and its addresses for staff who may only read (review of #505): who holds which, what the cluster shows. */
class ViewIpPool extends ViewRecord
{
    protected static string $resource = IpPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
