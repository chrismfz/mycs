<?php

namespace App\Admin\Resources\KbArticleResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\KbArticleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKbArticle extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = KbArticleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return KbArticleResource::withHtmlSource($data);
    }
}
