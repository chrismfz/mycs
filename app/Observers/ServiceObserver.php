<?php

namespace App\Observers;

use App\Events\Service as ServiceEvent;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ServiceObserver
{
    /**
     * Handle the Service "created" event.
     */
    public function created(Service $service): void
    {
        event(new ServiceEvent\Created($service));
    }

    /** Set while RenewServiceService saves: it has applied the paid periods already. */
    public static bool $renewing = false;

    /**
     * Staff moving the Next Due Date (expires_at) forward onto or into periods that are already paid
     * (e.g. comping an unpaid month before a paid one) picks those periods up, as a payment would.
     * Moving it back is a correction and is left as set.
     */
    public function updating(Service $service): void
    {
        if (self::$renewing || !$service->isDirty('expires_at') || $service->expires_at === null
            || $service->plan === null || in_array($service->plan->type, ['one-time', 'free'], true)) {
            return;
        }

        $original = $service->getOriginal('expires_at');
        if ($original !== null && !$service->paidUntilBase()->greaterThan(CarbonImmutable::parse($original)->startOfDay())) {
            return;
        }

        $paid = $service->paidThrough($service->expires_at);
        if ($paid->greaterThan($service->paidUntilBase())) {
            $service->expires_at = $paid->toMutable();
        }
    }

    /**
     * Handle the Service "updated" event.
     */
    public function updated(Service $service): void
    {
        // After commit: listeners (Stripe, Discord, ...) call out over the network; they must not run
        // inside a renewal's row lock, nor roll a committed renewal back by failing.
        DB::afterCommit(fn () => event(new ServiceEvent\Updated($service)));
    }

    /**
     * Handle the Service "deleted" event.
     */
    public function deleted(Service $service): void
    {
        event(new ServiceEvent\Deleted($service));
    }
}
