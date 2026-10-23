<?php

namespace App\Admin\Resources\NotificationTemplateResource\Pages;

use App\Admin\Concerns\TwoColumnForm;
use App\Admin\Resources\NotificationTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNotificationTemplate extends CreateRecord
{
    use TwoColumnForm;

    protected static string $resource = NotificationTemplateResource::class;
}
