<?php

namespace App\Admin\Resources\KbArticleResource\Pages;

use App\Admin\Resources\KbArticleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKbArticle extends EditRecord
{
    protected static string $resource = KbArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return KbArticleResource::withHtmlSource($data);
    }
}
