<?php

namespace App\Admin\Resources\McpOauthGrantResource\Pages;

use App\Admin\Resources\McpOauthGrantResource;
use Filament\Resources\Pages\ListRecords;

class ListMcpOauthGrants extends ListRecords
{
    protected static string $resource = McpOauthGrantResource::class;
}
