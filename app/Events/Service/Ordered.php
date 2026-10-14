<?php

namespace App\Events\Service;

use App\Models\Service;
use App\Services\Order\OrderLine;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A service was ordered (Σ7, docs/orders.md): fired by the PlaceOrderService inside its transaction, after the service,
 * its options and checkout fields are written and before a free one is started. The hook for provisioning «on order»
 * (Σ1) and for storing the product's custom fields from the order form ($line->customFields). A listener that throws
 * rolls the order back.
 */
class Ordered
{
    use Dispatchable;

    public function __construct(public Service $service, public OrderLine $line) {}
}
