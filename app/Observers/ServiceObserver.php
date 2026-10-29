<?php

namespace App\Observers;

use App\Events\Service as ServiceEvent;
use App\Exceptions\DisplayException;
use App\Models\Contact;
use App\Models\IpAddress;
use App\Models\Order;
use App\Models\Service;
use App\Services\Service\OptionTasks;
use App\Services\Service\ServerSelector;
use App\Support\DunningWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
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
        if ($service->order_id !== null && in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED, ...Service::ENDED_STATUSES], true)) {
            self::refreshOrders($service); // made already started (staff, the API): its order may have nothing waiting
        }
        event(new ServiceEvent\Created($service));
    }

    /** Set while RenewServiceService saves: it has applied the paid periods already. */
    public static bool $renewing = false;

    /**
     * Staff moving the Next Due Date (expires_at) forward onto or into periods that are already paid
     * (e.g. comping an unpaid month before a paid one) picks those periods up, as a payment would.
     * Moving it back is a correction and is left as set.
     */
    public function saving(Service $service): void
    {
        if ($service->status === Service::STATUS_ACTIVE && $service->activated_at === null) {
            $service->activated_at = now(); // its first start (Σ1-b1): the only one that moves its dates
        }
        self::keepContactInAccount($service);
        // A price saved holds its account's group discount as it is now (reviews of #272): a recalculation, an order, an
        // upgrade, staff's edit. Group repricing sets its own (ClientGroupPricing).
        if ($service->isDirty('price') && !$service->isDirty('group_percent')) {
            $service->group_percent = $service->client?->groupPercent() ?? '0';
        }
    }

    /**
     * A related contact is one of the service's own account; moved to another account, it stays behind (as the card).
     * One newly set is an active one (review 4); one archived since it was set stays.
     */
    private static function keepContactInAccount(Service $service): void
    {
        if ($service->contact_id === null || !$service->isDirty(['contact_id', 'client_id'])) {
            return;
        }
        $own = Contact::query()->whereKey($service->contact_id)->where('client_id', $service->client_id);
        if ($service->isDirty('contact_id') ? (clone $own)->whereNull('archived_at')->exists() : $own->exists()) {
            return;
        }
        if (!$service->isDirty('contact_id')) {
            $service->contact_id = null;

            return;
        }

        throw new DisplayException(__('This contact is not available.'));
    }

    public function updating(Service $service): void
    {
        if ($service->isDirty('status')) {
            self::refuseUnknownStatus($service); // a row that already holds one stays editable otherwise
        }
        if ($service->isDirty('status') && $service->getOriginal('status') === Service::STATUS_PENDING && $service->server_id === null
            && (int) $service->whmcs_server_id > 0) {
            // A pending order from WHMCS started here without a server (accepted without its creation, or set active by
            // staff): its account, if any, was made here, not left on WHMCS's server (R2-3 of #530).
            $service->whmcs_server_id = null;
        }
        if ($service->isDirty('status') && $service->status !== Service::STATUS_SUSPENDED) {
            // Back to active from a suspension for non-payment, with a suspension window set: not suspended again the same
            // day (the hourly run would, within the hour, of a service staff just unsuspended: review of #411). A hold
            // through today, as «Do not suspend until» shows it; tomorrow the rule applies as ever. Without a window the
            // cron runs once a day, as before.
            if ($service->status === Service::STATUS_ACTIVE && $service->getOriginal('dunning_suspended_at') !== null && !DunningWindow::always()
                && ($service->suspend_hold_until === null || Carbon::parse($service->suspend_hold_until)->lt(Carbon::today()))) {
                $service->suspend_hold_until = Carbon::today();
            }
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

    /** A pending order follows its services (Σ1-b2): its own, and the one a service moved from. */
    private static function refreshOrders(Service $service, mixed $previousOrderId = null): void
    {
        foreach (array_unique(array_filter([$service->order_id, $previousOrderId])) as $orderId) {
            Order::query()->find($orderId)?->refreshStatus();
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
        if ($service->wasChanged(['status', 'order_id'])) {
            self::refreshOrders($service, $service->wasChanged('order_id') ? $service->getOriginal('order_id') : null);
        }
        // Its options staff do by hand (OptionTasks): given when it starts, taken back when it ends.
        if ($service->wasChanged('status')) {
            $was = $service->getOriginal('status');
            $running = in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true);
            // It starts, or staff bring back one that ended (its IP was taken back): given (reviews of #296).
            if ($running && ($was === Service::STATUS_PENDING || in_array($was, Service::ENDED_STATUSES, true))) {
                OptionTasks::changed($service, [], OptionTasks::on($service));
            } elseif (!in_array($was, Service::ENDED_STATUSES, true) && $was !== Service::STATUS_PENDING && $service->isEnded()) {
                OptionTasks::changed($service, OptionTasks::on($service), []);
            }
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
     * Its addresses of the IP pools go back, released now (ADV-505-1): one deleted with it waits its turn as any other
     * released, never first in line while still set on the old VM. The foreign key refuses a delete that skips this.
     */
    public function deleting(Service $service): void
    {
        IpAddress::query()->where('service_id', $service->id)
            ->update(['service_id' => null, 'assigned_at' => null, 'released_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Handle the Service "deleted" event.
     */
    public function deleted(Service $service): void
    {
        self::refreshOrders($service); // the one that kept its order pending may be gone (review R4)
        // Its option values go with it (a morph: no foreign key would take them): left behind, they held the values
        // they point at as «in use» for ever (DATA-8).
        $service->configs()->delete();
        event(new ServiceEvent\Deleted($service));
    }
}
