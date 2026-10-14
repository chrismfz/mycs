<?php

namespace App\Admin\Resources\ServerGroupResource\Pages;

use App\Admin\Resources\ServerGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServerGroups extends ListRecords
{
    protected static string $resource = ServerGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
