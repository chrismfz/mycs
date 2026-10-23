<?php

namespace App\Admin\Resources\Audits\Pages;

use App\Admin\Concerns\ShowsLogs;
use App\Admin\Resources\Audits\AuditResource;
use Filament\Resources\Pages\ListRecords;

class ListAudits extends ListRecords
{
    use ShowsLogs;

    protected static string $resource = AuditResource::class;
}
