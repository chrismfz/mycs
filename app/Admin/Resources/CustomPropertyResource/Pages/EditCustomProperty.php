<?php

namespace App\Admin\Resources\CustomPropertyResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\CustomPropertyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomProperty extends EditRecord
{
    use TwoColumnForm;

    protected static string $resource = CustomPropertyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
