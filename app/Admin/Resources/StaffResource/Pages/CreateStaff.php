<?php

namespace App\Admin\Resources\StaffResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\StaffResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStaff extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = StaffResource::class;
}
