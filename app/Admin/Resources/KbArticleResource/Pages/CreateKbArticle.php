<?php

namespace App\Admin\Resources\KbArticleResource\Pages;

use App\Admin\Resources\KbArticleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKbArticle extends CreateRecord
{
    protected static string $resource = KbArticleResource::class;
}
