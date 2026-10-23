<?php

namespace App\Admin\Resources\PaymentGatewayEventResource\Pages;

use App\Admin\Concerns\ShowsLogs;
use App\Admin\Resources\PaymentGatewayEventResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentGatewayEvents extends ListRecords
{
    use ShowsLogs;

    protected static string $resource = PaymentGatewayEventResource::class;
}
