<?php

namespace App\Admin\Resources\DomainRegistrarLogResource\Pages;

use App\Admin\Concerns\ShowsLogs;
use App\Admin\Resources\DomainRegistrarLogResource;
use Filament\Resources\Pages\ListRecords;

class ListDomainRegistrarLogs extends ListRecords
{
    use ShowsLogs;

    protected static string $resource = DomainRegistrarLogResource::class;
}
