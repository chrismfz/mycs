<?php

namespace App\Admin\Resources\ProductAddonResource\Pages;

use App\Admin\Resources\ProductAddonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductAddons extends ListRecords
{
    protected static string $resource = ProductAddonResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
