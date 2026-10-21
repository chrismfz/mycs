<?php

namespace App\Admin\Resources\OfferResource\Pages;

use App\Admin\Resources\OfferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOffers extends ListRecords
{
    protected static string $resource = OfferResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
