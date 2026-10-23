<?php

namespace App\Admin\Resources\AdjustmentNoteResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\AdjustmentNoteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAdjustmentNote extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = AdjustmentNoteResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
