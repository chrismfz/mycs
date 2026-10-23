<?php

namespace App\Admin\Resources\ClientGroupResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\ClientGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateClientGroup extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = ClientGroupResource::class;
}
