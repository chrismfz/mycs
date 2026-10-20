<?php

namespace App\Admin\Resources\ClientGroupResource\Pages;

use App\Admin\Resources\ClientGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditClientGroup extends EditRecord
{
    protected static string $resource = ClientGroupResource::class;

    protected function getHeaderActions(): array
    {
        // Its members leave it first and lose its discount, as for a move (ClientGroupObserver::deleting).
        return [DeleteAction::make()];
    }
}
