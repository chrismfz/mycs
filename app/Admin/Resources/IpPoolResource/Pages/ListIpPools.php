<?php

namespace App\Admin\Resources\IpPoolResource\Pages;

use App\Admin\Resources\IpPoolResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIpPools extends ListRecords
{
    protected static string $resource = IpPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
