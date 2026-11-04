<?php

namespace App\Services\Service;

use App\Helpers\NotificationHelper;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\ServiceUpgrade\CancelPendingUpgrades;
use App\Support\AfterCommit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Addons follow their parent (docs/addons.md, owner 2026-11-04), whoever changed it (the cron, a payment, staff, a
 * cancellation): suspended with it, back with it, ended with it. What the parent's server action did covers its machine,
 * so following it asks nothing of a module: an addon's own server actions are staff's tasks (ExtensionHelper), and the
 * only one following makes is undoing a suspension staff did by hand for the addon's own invoice.
 *
 * Under the parent's lock (ServiceObserver::updated runs inside the save), each addon locked in turn: parent, addon, then
 * their invoices, the order every money path takes.
 */
final class AddonCascade
{
    /** The parent's status changed from $was: its addons follow. */
    public static function followParent(Service $parent, ?string $was): void
    {
        if ($parent->parent_service_id !== null) {
            return; // one level only
        }
        DB::transaction(function () use ($parent, $was) {
            match (true) {
                $parent->status === Service::STATUS_SUSPENDED => self::suspend($parent),
                $parent->status === Service::STATUS_ACTIVE && $was === Service::STATUS_SUSPENDED => self::unsuspend($parent),
                $parent->isEnded() => self::end($parent),
                default => null,
            };
        });
    }

    /** Running ones are suspended with it; one already suspended (its own invoice, staff) keeps its reason. */
    private static function suspend(Service $parent): void
    {
        foreach (self::locked($parent, [Service::STATUS_ACTIVE]) as $addon) {
            $addon->forceFill(['status' => Service::STATUS_SUSPENDED, 'suspend_reason' => Service::SUSPEND_REASON_PARENT, 'dunning_suspended_at' => null])->save();
        }
    }

    /**
     * Those suspended with it are back; one suspended for its own reason stays. One staff had suspended by hand before
     * (its last task of the two a suspension) gets its unsuspension as a task.
     */
    private static function unsuspend(Service $parent): void
    {
        foreach (self::locked($parent, [Service::STATUS_SUSPENDED]) as $addon) {
            if ($addon->suspend_reason !== Service::SUSPEND_REASON_PARENT) {
                continue;
            }
            $addon->forceFill(['status' => Service::STATUS_ACTIVE])->save();
            if (self::suspendedByHand($addon)) {
                AfterCommit::job(new UnsuspendJob($addon));
            }
        }
    }

    /**
     * Ended with it, as it ended (terminated or cancelled), with the rest an end does (CronJob, Service::markTerminated):
     * pending upgrades and open invoices cancelled, one holding money left open for staff, stock back.
     */
    private static function end(Service $parent): void
    {
        $reason = __('invoices.cancellation_reasons.parent_ended');
        $left = [];
        foreach (self::locked($parent, Service::PAYING_STATUSES) as $addon) {
            $addon->forceFill(['status' => $parent->status])->save();
            $left += CancelPendingUpgrades::forService($addon, $reason);
            $left += CancelOpenInvoices::handle(CancelOpenInvoices::onServiceEnd($addon->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get()), $reason, $addon);
            if ($addon->product?->stock !== null) {
                $addon->product->increment('stock', $addon->quantity);
            }
        }
        self::tellStaff($parent, $left);
    }

    /**
     * The parent's request at the end of its period is its addons' too: they renew no more (RenewalBilling skips a
     * service with a request), their open invoices go now, and each ends on its own due date, or with the parent if that
     * comes first. An immediate request needs none: the parent ending ends them (end()). One with a request of its own
     * keeps it.
     */
    public static function requestWithParent(ServiceCancellation $request): void
    {
        if ($request->with_parent || $request->type !== 'end_of_period') {
            return;
        }
        $addons = Service::query()->where('parent_service_id', $request->service_id)->whereIn('status', Service::PAYING_STATUSES)
            ->whereDoesntHave('cancellation')->orderBy('id')->pluck('id');
        foreach ($addons as $id) {
            (new ServiceCancellation(['service_id' => $id, 'type' => 'end_of_period', 'reason' => (string) $request->reason]))
                ->forceFill(['with_parent' => true])->save();
        }
    }

    /** The parent's request withdrawn (staff deleted it): those made with it go too, for a service still running. */
    public static function withdrawWithParent(ServiceCancellation $request): void
    {
        if ($request->with_parent) {
            return;
        }
        ServiceCancellation::query()->where('with_parent', true)
            ->whereHas('service', fn ($query) => $query->where('parent_service_id', $request->service_id)->whereIn('status', Service::PAYING_STATUSES))
            ->get()->each(fn (ServiceCancellation $addonRequest) => $addonRequest->delete());
    }

    /**
     * @param  list<string>  $statuses
     * @return Collection<int, Service>
     */
    private static function locked(Service $parent, array $statuses)
    {
        return Service::query()->where('parent_service_id', $parent->id)->whereIn('status', $statuses)->orderBy('id')->lockForUpdate()->get();
    }

    /** Its last suspension or unsuspension task for staff was a suspension: done by hand on the machine. */
    private static function suspendedByHand(Service $addon): bool
    {
        return ModuleAction::query()->where('service_id', $addon->id)->where('subject', ModuleAction::SUBJECT_BY_HAND)
            ->whereIn('action', ['suspend', 'unsuspend'])->latest('id')->value('action') === 'suspend';
    }

    /** @param  array<string, Throwable>  $left  invoices left open or failed: logged, and mailed to staff */
    private static function tellStaff(Service $parent, array $left): void
    {
        if ($left === []) {
            return;
        }
        foreach ($left as $reason) {
            report($reason);
        }
        $lines = e(implode("\n", array_map(fn ($key, $reason) => "{$key}: {$reason->getMessage()}", array_keys($left), $left)));
        $id = $parent->id;
        DB::afterCommit(function () use ($id, $lines) {
            try {
                NotificationHelper::sendSystemEmailNotification('Addons ended with their service: needs staff attention', <<<HTML
                    Service #{$id} ended, and its addons with it. These were left for staff:<br>
                    <pre>{$lines}</pre>
                    HTML);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
