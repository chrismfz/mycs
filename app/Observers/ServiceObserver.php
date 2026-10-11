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
        // Billing cycle changed (e.g. monthly -> yearly) while a renewal invoice is open: its line covers
        // a period of the new cycle, so what the customer pays buys what the invoice says. Its price is
        // staff's to set (Recurring Amount), as in WHMCS. Never over a later live period (one paid ahead,
        // say): the line stops where that one starts, so no day is billed twice.
        if ($service->wasChanged('plan_id')) {
            $service->unsetRelation('plan');
        }
        if ($service->wasChanged('plan_id') && $service->plan !== null && !in_array($service->plan->type, ['one-time', 'free'], true)) {
            $lines = $service->livePeriodLines();
            $lines->filter(fn ($line) => in_array($line->invoice?->status, ['pending', 'draft'], true))
                ->each(function ($line) use ($service, $lines) {
                    $end = $service->periodEnd($line->period_start, $lines);
                    $next = $lines->first(fn ($other) => $other->id !== $line->id && $other->period_start->greaterThan($line->period_start))?->period_start;
                    if ($end !== null && $next !== null && $next->lessThan($end)) {
                        $end = $next;
                    }
                    if ($end !== null && !$end->equalTo($line->period_end)) {
                        $line->forceFill(['period_end' => $end, 'description' => $service->describePeriod($line->period_start, $end)])->save();
                    }
                });
        }

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
