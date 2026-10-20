<?php

namespace App\Admin\Resources\CannedReplyResource\Pages;

use App\Admin\Resources\CannedReplyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCannedReplies extends ManageRecords
{
    protected static string $resource = CannedReplyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
