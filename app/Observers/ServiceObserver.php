<?php

namespace App\Observers;

use App\Events\Service as ServiceEvent;
use App\Exceptions\DisplayException;
use App\Models\BillingAgreement;
use App\Models\Service;
use App\Services\Service\ServerSelector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ServiceObserver
{
    public function creating(Service $service): void
    {
        self::refuseUnknownStatus($service);
    }

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
    /**
     * A service is billed only to a payment method of its own account (docs/accounts.md, PR 2a): a login with access
     * to several accounts must not put one account's card on another's renewals.
     */
    public function saving(Service $service): void
    {
        if ($service->status === Service::STATUS_ACTIVE && $service->activated_at === null) {
            $service->activated_at = now(); // its first start (Σ1-b1): the only one that moves its dates
        }
        if ($service->billing_agreement_id === null || !$service->isDirty(['billing_agreement_id', 'client_id'])
            || BillingAgreement::withTrashed()->whereKey($service->billing_agreement_id)->where('client_id', $service->client_id)->exists()) {
            return;
        }
        if (!$service->isDirty('billing_agreement_id')) {
            $service->billing_agreement_id = null; // moved to another account: the old account's card stays behind

            return;
        }

        throw new DisplayException(__('This payment method belongs to another account.'));
    }

    public function updating(Service $service): void
    {
        if ($service->isDirty('status')) {
            self::refuseUnknownStatus($service); // a row that already holds one stays editable otherwise
        }
        if ($service->isDirty('status') && $service->status !== Service::STATUS_SUSPENDED) {
            $service->dunning_suspended_at = null;
            // Not suspended any more: its reason goes (WHMCS clears suspendreason on unsuspend).
            if ($service->getOriginal('status') === Service::STATUS_SUSPENDED && !$service->isDirty('suspend_reason')) {
                $service->suspend_reason = null;
            }
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
            self::keepServerOfProductsModule($service);
        }
        if ($service->isDirty('plan_id')) {
            $service->unsetRelation('plan'); // a loaded relation still holds the old plan
        }
        if ($service->isDirty('status') && $service->getOriginal('status') === Service::STATUS_PENDING
            && $service->status === Service::STATUS_ACTIVE && !$service->isDirty('expires_at') && !self::$renewing
            && $service->getOriginal('activated_at') === null) {
            // Its first start, with no date of staff's in the same save: the period counts from today (Σ1-b). A later
            // pending → active (a status corrected by staff) moves nothing (review R2).
            $service->datesOnStart();

            return;
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
     * A new product of another module (an upgrade, staff, the API) takes the service to a server of its module: the old
     * server would get the new product's settings, a module it does not speak (review F4). Of the same module the
     * account stays where it is (WHMCS). A server set in the same save is staff's choice and kept.
     */
    private static function keepServerOfProductsModule(Service $service): void
    {
        if ($service->server_id === null || $service->isDirty('server_id')) {
            return;
        }
        $module = $service->product?->server?->extension;
        if ($service->server?->extension === $module) {
            return;
        }
        $service->server_id = $service->product === null ? null : ServerSelector::forNewAccount($service->product)?->id;
        $service->unsetRelation('server');
    }

    /**
     * Handle the Service "updated" event.
     */
    public function updated(Service $service): void
    {
        if ($service->wasChanged('status') && $service->order_id !== null) {
            $service->order?->refreshStatus(); // a pending order follows its services (Σ1-b2)
        }
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
     * Only the statuses MyCS knows (Service::STATUSES). One it does not know (a typo, WHMCS's «Fraud» not mapped)
     * would leave the service out of every step that looks for running or ended ones.
     */
    private static function refuseUnknownStatus(Service $service): void
    {
        // Not set on create: the column default (pending) fills it.
        $status = $service->getAttributes()['status'] ?? null;
        if ($status !== null && !in_array($status, Service::STATUSES, true)) {
            throw new \LogicException("Unknown service status «{$status}»: one of " . implode(', ', Service::STATUSES) . '.');
        }
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
