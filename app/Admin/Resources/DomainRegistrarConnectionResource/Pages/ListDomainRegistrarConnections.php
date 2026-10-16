<?php

namespace App\Admin\Resources\DomainRegistrarConnectionResource\Pages;

use App\Admin\Resources\DomainRegistrarConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDomainRegistrarConnections extends ListRecords
{
    protected static string $resource = DomainRegistrarConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
