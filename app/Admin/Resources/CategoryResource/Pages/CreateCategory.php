<?php

namespace App\Admin\Resources\CategoryResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\CategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = CategoryResource::class;
}
