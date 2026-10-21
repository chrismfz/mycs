<?php

namespace App\Admin\Resources\BulkMailResource\Pages;

use App\Admin\Resources\BulkMailResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBulkMails extends ListRecords
{
    protected static string $resource = BulkMailResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
