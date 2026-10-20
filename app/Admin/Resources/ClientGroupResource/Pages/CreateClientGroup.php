<?php

namespace App\Admin\Resources\ClientGroupResource\Pages;

use App\Admin\Resources\ClientGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClientGroup extends CreateRecord
{
    protected static string $resource = ClientGroupResource::class;
}
