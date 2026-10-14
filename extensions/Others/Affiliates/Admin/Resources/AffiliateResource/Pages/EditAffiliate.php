<?php

namespace MyCS\Extensions\Others\Affiliates\Admin\Resources\AffiliateResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use MyCS\Extensions\Others\Affiliates\Admin\Resources\AffiliateResource;

class EditAffiliate extends EditRecord
{
    protected static string $resource = AffiliateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
