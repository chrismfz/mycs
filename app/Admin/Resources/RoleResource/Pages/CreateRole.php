<?php

namespace App\Admin\Resources\RoleResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = RoleResource::class;
}
