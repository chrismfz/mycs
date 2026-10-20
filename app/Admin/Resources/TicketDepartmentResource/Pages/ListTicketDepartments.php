<?php

namespace App\Admin\Resources\TicketDepartmentResource\Pages;

use App\Admin\Resources\TicketDepartmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTicketDepartments extends ListRecords
{
    protected static string $resource = TicketDepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
