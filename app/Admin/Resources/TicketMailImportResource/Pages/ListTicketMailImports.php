<?php

namespace App\Admin\Resources\TicketMailImportResource\Pages;

use App\Admin\Concerns\ShowsLogs;
use App\Admin\Resources\TicketMailImportResource;
use Filament\Resources\Pages\ListRecords;

class ListTicketMailImports extends ListRecords
{
    use ShowsLogs;

    protected static string $resource = TicketMailImportResource::class;
}
