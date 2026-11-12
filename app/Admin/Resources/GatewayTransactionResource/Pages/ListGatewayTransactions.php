<?php

namespace App\Admin\Resources\GatewayTransactionResource\Pages;

use App\Admin\Resources\GatewayTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListGatewayTransactions extends ListRecords
{
    protected static string $resource = GatewayTransactionResource::class;
}
