<?php

namespace App\Admin\Resources\UserResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = UserResource::class;
}
