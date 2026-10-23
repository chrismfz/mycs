<?php

namespace App\Admin\Resources\HttpLogResource\Pages;

use App\Admin\Concerns\ShowsLogs;
use App\Admin\Resources\HttpLogResource;
use Filament\Resources\Pages\ListRecords;

class ListHttpLogs extends ListRecords
{
    use ShowsLogs;

    protected static string $resource = HttpLogResource::class;
}
