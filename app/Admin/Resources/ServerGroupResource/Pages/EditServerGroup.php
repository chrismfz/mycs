<?php

namespace App\Admin\Resources\ServerGroupResource\Pages;

use App\Admin\Resources\ServerGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServerGroup extends EditRecord
{
    protected static string $resource = ServerGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
