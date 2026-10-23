<?php

namespace App\Admin\Resources\OfferResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\OfferResource;
use App\Models\Offer;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOffer extends EditRecord
{
    use TwoColumnForm;

    protected static string $resource = OfferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')->label(__('Open the link'))->icon('ri-external-link-line')->url(fn (Offer $record) => $record->url(), shouldOpenInNewTab: true),
            DeleteAction::make(),
        ];
    }
}
