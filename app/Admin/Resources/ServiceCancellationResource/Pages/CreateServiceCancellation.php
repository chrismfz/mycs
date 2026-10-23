<?php

namespace App\Admin\Resources\ServiceCancellationResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\ServiceCancellationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceCancellation extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = ServiceCancellationResource::class;
}
