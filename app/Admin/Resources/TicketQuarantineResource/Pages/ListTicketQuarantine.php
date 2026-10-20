<?php

namespace App\Admin\Resources\TicketQuarantineResource\Pages;

use App\Admin\Resources\TicketQuarantineResource;
use Filament\Resources\Pages\ListRecords;

class ListTicketQuarantine extends ListRecords
{
    protected static string $resource = TicketQuarantineResource::class;
}
