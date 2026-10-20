<?php

namespace App\Admin\Resources\CannedReplyCategoryResource\Pages;

use App\Admin\Resources\CannedReplyCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCannedReplyCategories extends ManageRecords
{
    protected static string $resource = CannedReplyCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
