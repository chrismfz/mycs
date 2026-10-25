<?php

namespace App\Admin\Resources\ChatNotificationRuleResource\Pages;

use App\Admin\Resources\ChatNotificationRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChatNotificationRules extends ListRecords
{
    protected static string $resource = ChatNotificationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
