<?php

namespace App\Admin\Resources\TicketDepartmentResource\Pages;

use App\Admin\Resources\TicketDepartmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTicketDepartment extends CreateRecord
{
    protected static string $resource = TicketDepartmentResource::class;
}
