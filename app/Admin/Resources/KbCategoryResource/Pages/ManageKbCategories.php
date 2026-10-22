<?php

namespace App\Admin\Resources\KbCategoryResource\Pages;

use App\Admin\Resources\KbCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageKbCategories extends ManageRecords
{
    protected static string $resource = KbCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
