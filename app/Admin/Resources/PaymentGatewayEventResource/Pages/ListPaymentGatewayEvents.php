<?php

namespace App\Admin\Resources\PaymentGatewayEventResource\Pages;

use App\Admin\Resources\PaymentGatewayEventResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentGatewayEvents extends ListRecords
{
    protected static string $resource = PaymentGatewayEventResource::class;
}
