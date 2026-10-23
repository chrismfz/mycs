<?php

namespace App\Admin\Resources\TicketQuarantineResource\Pages;

use App\Admin\Concerns\ShowsLogs;
use App\Admin\Resources\TicketQuarantineResource;
use Filament\Resources\Pages\ListRecords;

class ListTicketQuarantine extends ListRecords
{
    use ShowsLogs;

    protected static string $resource = TicketQuarantineResource::class;
}
