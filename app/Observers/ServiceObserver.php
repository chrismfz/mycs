<?php

namespace App\Observers;

use App\Events\Service as ServiceEvent;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

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
        if ($service->isDirty('status') && $service->status !== Service::STATUS_SUSPENDED) {
            $service->dunning_suspended_at = null;
        }
        if ($service->isDirty('coupon_id')) {
            // Cycles count from a period boundary: the first period not billed yet on a started service;
            // a pending one starts counting when it is activated.
            $started = $service->expires_at !== null && $service->status !== Service::STATUS_PENDING;
            $service->coupon_started_at = $service->coupon_id && $started ? $service->nextPeriodStart() : null;
            $service->coupon_expired_at = null;
        }
        if ($service->coupon_id && $service->coupon_started_at === null
            && $service->getOriginal('status') === Service::STATUS_PENDING && $service->status === Service::STATUS_ACTIVE) {
            $service->coupon_started_at = CarbonImmutable::today();
        }
        if ($service->isDirty('product_id')) {
            $service->unsetRelation('product');
        }
        if ($service->isDirty('plan_id')) {
            $service->unsetRelation('plan'); // a loaded relation still holds the old plan
        }
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
        if ($service->wasChanged('plan_id')) {
            $service->unsetRelation('plan');
            $service->refreshOpenPeriods();
        }

        // After commit: listeners (Stripe, Discord, ...) call out over the network; they must not run
        // inside a renewal's row lock, nor roll a committed renewal back by failing.
        // The changes are captured now: at commit isDirty() is false and a later save has replaced getChanges().
        $changes = $service->getChanges();
        $original = array_intersect_key($service->getRawOriginal(), $changes);
        DB::afterCommit(function () use ($service, $changes, $original) {
            // The callbacks of one commit run in a row: a listener that throws would skip the other services'
            // (e.g. their subscription cancellation, cron termination). Reported, never propagated.
            try {
                event(new ServiceEvent\Updated($service, $changes, $original));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Handle the Service "deleted" event.
     */
    public function deleted(Service $service): void
    {
        // Its option values go with it (a morph: no foreign key would take them): left behind, they held the values
        // they point at as «in use» for ever (DATA-8).
        $service->configs()->delete();
        event(new ServiceEvent\Deleted($service));
    }
}
