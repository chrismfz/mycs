<?php

namespace App\Admin\Resources\LoginAttemptResource\Pages;

use App\Admin\Concerns\ShowsLogs;
use App\Admin\Resources\LoginAttemptResource;
use Filament\Resources\Pages\ListRecords;

class ListLoginAttempts extends ListRecords
{
    use ShowsLogs;

    protected static string $resource = LoginAttemptResource::class;
}
