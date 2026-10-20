<?php

namespace App\Admin\Resources\TicketDepartmentResource\Pages;

use App\Admin\Resources\TicketDepartmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTicketDepartment extends EditRecord
{
    protected static string $resource = TicketDepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [TicketDepartmentResource::testAction(), DeleteAction::make()];
    }
}
