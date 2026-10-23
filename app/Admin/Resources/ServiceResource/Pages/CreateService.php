<?php

namespace App\Admin\Resources\ServiceResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\ServiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateService extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = ServiceResource::class;
}
