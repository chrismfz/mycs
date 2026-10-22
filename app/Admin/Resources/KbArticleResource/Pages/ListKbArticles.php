<?php

namespace App\Admin\Resources\KbArticleResource\Pages;

use App\Admin\Resources\KbArticleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKbArticles extends ListRecords
{
    protected static string $resource = KbArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
