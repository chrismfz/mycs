<?php

namespace App\Admin\Resources\ServerGroupResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\ServerGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServerGroup extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = ServerGroupResource::class;
}
