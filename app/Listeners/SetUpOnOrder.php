<?php

namespace App\Listeners;

use App\Events\Service\Ordered;
use App\Jobs\Server\CreateJob;
use App\Models\Product;
use App\Models\Service;

/**
 * Auto Setup «as soon as an order is placed» (Σ1-b, WHMCS): the account is made before payment (a one-time plan: on
 * payment). Run inside the order's
 * transaction: the create is queued after its commit; a product without a module starts at once. Either way, unpaid,
 * its dates count from the order (Service::datesOnStart), so an unpaid first invoice suspends and terminates it.
 */
class SetUpOnOrder
{
    public function handle(Ordered $event): void
    {
        $service = $event->service->fresh(); // its status is the column's default (pending): not on the model just made
        if ($service === null || $service->product?->autosetup !== Product::AUTOSETUP_ORDER || $service->status !== Service::STATUS_PENDING) {
            return;
        }
        // A one-time plan has no due date: once running, nothing would chase its unpaid invoice (no suspension, and the
        // unpaid-order cancellation takes pending services only). It is set up on payment (review R1).
        if ($service->plan?->type === 'one-time') {
            return;
        }
        if ($service->provisioningServer() !== null) {
            CreateJob::dispatch($service)->afterCommit();

            return;
        }
        $service->status = Service::STATUS_ACTIVE;
        $service->save();
        $event->service->setRawAttributes($service->getAttributes(), true);
    }
}
