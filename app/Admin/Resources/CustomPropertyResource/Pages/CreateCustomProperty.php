<?php

namespace App\Admin\Resources\CustomPropertyResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\CustomPropertyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomProperty extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = CustomPropertyResource::class;
}
