<?php

namespace App\Admin\Resources\EmailLogResource\Pages;

use App\Admin\Concerns\ShowsLogs;
use App\Admin\Resources\EmailLogResource;
use Filament\Resources\Pages\ListRecords;

class ListEmailLogs extends ListRecords
{
    use ShowsLogs;

    protected static string $resource = EmailLogResource::class;
}
