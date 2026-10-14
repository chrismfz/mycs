<?php

namespace App\Services\Order;

use App\Models\Invoice;
use App\Models\Order;

final class PlacedOrder
{
    /** @param  Invoice|null  $invoice  null when nothing was due: the services are already active */
    public function __construct(
        public readonly Order $order,
        public readonly ?Invoice $invoice,
    ) {}
}
