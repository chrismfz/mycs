<?php

namespace App\Admin\Resources\TaxRateResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\TaxRateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTaxRate extends EditRecord
{
    use TwoColumnForm;

    protected static string $resource = TaxRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
