<?php

namespace App\Observers;

use App\Events\ServiceCancellation as ServiceCancellationEvent;
use App\Models\ServiceCancellation;
use App\Services\Service\AddonCascade;

class ServiceCancellationObserver
{
    /**
     * Handle the ServiceCancellation "created" event.
     */
    public function created(ServiceCancellation $serviceCancellation): void
    {
        AddonCascade::requestWithParent($serviceCancellation); // its addons' too (docs/addons.md)
        event(new ServiceCancellationEvent\Created($serviceCancellation));
    }

    /**
     * Handle the ServiceCancellation "updated" event.
     */
    public function updated(ServiceCancellation $serviceCancellation): void
    {
        event(new ServiceCancellationEvent\Updated($serviceCancellation));
    }

    /**
     * Handle the ServiceCancellation "deleted" event.
     */
    public function deleted(ServiceCancellation $serviceCancellation): void
    {
        AddonCascade::withdrawWithParent($serviceCancellation);
        event(new ServiceCancellationEvent\Deleted($serviceCancellation));
    }
}
