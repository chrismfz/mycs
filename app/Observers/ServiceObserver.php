<?php

namespace App\Observers;

use App\Events\Service as ServiceEvent;
use App\Models\Service;
use Carbon\CarbonImmutable;

class ServiceObserver
{
    /**
     * Handle the Service "created" event.
     */
    public function created(Service $service): void
    {
        event(new ServiceEvent\Created($service));
    }

    /**
     * Staff moving the Next Due Date (expires_at) onto the start of periods that are already paid
     * (e.g. comping an unpaid month before a paid one) picks those periods up. Only periods that
     * start exactly there, and only when the date moves forward: moving it back is a correction.
     */
    public function updating(Service $service): void
    {
        if (!$service->isDirty('expires_at') || $service->expires_at === null
            || $service->plan === null || in_array($service->plan->type, ['one-time', 'free'], true)) {
            return;
        }

        // Only a move forward: moving the date back (even onto the start of a paid period) is a correction.
        $original = $service->getOriginal('expires_at');
        if ($original !== null && !$service->paidUntilBase()->greaterThan(CarbonImmutable::parse($original)->startOfDay())) {
            return;
        }

        $paid = $service->paidThrough($service->expires_at, exact: true);
        if ($paid->greaterThan($service->paidUntilBase())) {
            $service->expires_at = $paid->toMutable();
        }
    }

    /**
     * Handle the Service "updated" event.
     */
    public function updated(Service $service): void
    {
        event(new ServiceEvent\Updated($service));
    }

    /**
     * Handle the Service "deleted" event.
     */
    public function deleted(Service $service): void
    {
        event(new ServiceEvent\Deleted($service));
    }
}
