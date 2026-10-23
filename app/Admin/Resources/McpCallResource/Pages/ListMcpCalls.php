<?php

namespace App\Admin\Resources\McpCallResource\Pages;

use App\Admin\Concerns\ShowsLogs;
use App\Admin\Resources\McpCallResource;
use Filament\Resources\Pages\ListRecords;

class ListMcpCalls extends ListRecords
{
    use ShowsLogs;

    protected static string $resource = McpCallResource::class;
}
