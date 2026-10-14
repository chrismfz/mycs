<?php

namespace MyCS\Extensions\Others\Affiliates\Admin\Resources\AffiliateResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use MyCS\Extensions\Others\Affiliates\Admin\Resources\AffiliateResource;

class ListAffiliates extends ListRecords
{
    protected static string $resource = AffiliateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
