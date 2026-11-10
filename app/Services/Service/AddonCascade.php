<?php

namespace App\Services\Service;

use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\ServiceUpgrade\CancelPendingUpgrades;
use App\Support\AfterCommit;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
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
            $addon->setRelation('parent', $parent); // its hold is read without a query per addon (F3 of #692)
            self::bringBack($addon);
        }
    }

    /** One suspended with its parent, its parent running again (locked by the caller, parent first). */
    private static function bringBack(Service $addon): void
    {
        if ($addon->status !== Service::STATUS_SUSPENDED || $addon->suspend_reason !== Service::SUSPEND_REASON_PARENT) {
            return;
        }
        // Overdue on its own past Suspend Days, with an open invoice of its own (one that does not bill its parent): it
        // stays suspended, now for that, as the cron would have suspended it, and its server is asked for it (a task: the
        // parent's suspension covered it). Its period on its parent's invoice, or none billed while it was suspended with
        // it, follows its parent: back with it (owner 2026-11-08, it shares its parent's due date).
        if ($addon->expires_at !== null && self::ownOpenInvoice($addon) && $addon->stillOverdue(CarbonImmutable::parse($addon->expires_at))) {
            $addon->forceFill(['suspend_reason' => 'Overdue on Payment', 'dunning_suspended_at' => now()])->save();
            AfterCommit::job(new SuspendJob($addon));

            return;
        }
        $addon->forceFill(['status' => Service::STATUS_ACTIVE])->save();
        if (self::suspendedByHand($addon)) {
            AfterCommit::job(new UnsuspendJob($addon));
        }
    }

    /** An open invoice that bills it and not its parent. */
    private static function ownOpenInvoice(Service $addon): bool
    {
        return $addon->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)
            ->whereDoesntHave('items', fn ($line) => $line->where('reference_type', Service::class)->where('reference_id', $addon->parent_service_id))
            ->exists();
    }

    /**
     * Its payment saw its parent suspended and left it suspended with it; read again once it committed, parent locked
     * first: a parent that came back meanwhile (its own payment, staff) brings it back too (code review 6 of #634).
     */
    public static function settle(int $addonId): void
    {
        DB::transaction(function () use ($addonId) {
            $parentId = Service::query()->whereKey($addonId)->value('parent_service_id');
            $parent = $parentId === null ? null : Service::query()->whereKey($parentId)->lockForUpdate()->first();
            $addon = Service::query()->whereKey($addonId)->lockForUpdate()->first();
            if ($parent?->status === Service::STATUS_ACTIVE && $addon !== null) {
                $addon->setRelation('parent', $parent); // locked, read once (F3 of #692)
                self::bringBack($addon);
            }
        });
    }

    /**
     * Ended with it, as it ended (terminated or cancelled), with the rest an end does (CronJob, Service::markTerminated):
     * pending upgrades and open invoices cancelled, one holding money left open for staff, stock back.
     *
     * Their open invoices are cancelled as one with their service's part of them (REV-779-1), once all of them have ended:
     * every way a service ends gives up on its own open invoices too (the cron, a request, staff, a delete), a moment
     * after this. Addon by addon, credit on their common renewal paid what was left of it at each step: the addon's line
     * gone, the customer's credit paid the renewal of a service that was ending, and nobody was told.
     */
    private static function end(Service $parent): void
    {
        $reason = __('invoices.cancellation_reasons.parent_ended');
        $left = [];
        $addons = self::locked($parent, Service::PAYING_STATUSES);
        if ($addons->isEmpty()) {
            return;
        }
        $open = collect();
        foreach ($addons as $addon) {
            // Paid past today: that time ends with its parent, its money kept; staff told, to refund or credit it (code
            // review 4 of #634), as the cron leaves a parent paid ahead to them.
            $paidAhead = $addon->expires_at !== null && $addon->status !== Service::STATUS_PENDING
                && CarbonImmutable::parse($addon->expires_at)->startOfDay()->greaterThan(CarbonImmutable::today());
            $addon->forceFill(['status' => $parent->status])->save();
            $own = CancelPendingUpgrades::forService($addon, $reason);
            $open = $open->concat(CancelOpenInvoices::onServiceEnd($addon->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get(), $addon));
            if ($paidAhead) {
                $own['paid_ahead'] = new DisplayException("Addon #{$addon->id} was paid until {$addon->expires_at->toDateString()}: refund or credit the unused time if due.");
            }
            foreach ($own as $key => $why) {
                $left["addon #{$addon->id} {$key}"] = $why;
            }
            if ($addon->product?->stock !== null) {
                $addon->product->increment('stock', $addon->quantity);
            }
        }
        $left += CancelOpenInvoices::handle($open->unique('id')->sortBy('id')->values(), $reason, $parent, array_values($addons->all()));
        self::tellStaff($parent, $left);
    }

    /**
     * An addon that starts or comes back (its creation done, paid, staff) while its parent is suspended is suspended with
     * it: back when its parent is (ADV-634-7).
     */
    public static function keepUnderParent(Service $addon): void
    {
        DB::transaction(function () use ($addon) {
            if (Service::query()->whereKey($addon->parent_service_id)->value('status') !== Service::STATUS_SUSPENDED) {
                return;
            }
            $locked = Service::query()->whereKey($addon->id)->lockForUpdate()->first();
            if ($locked?->status === Service::STATUS_ACTIVE) {
                $locked->forceFill(['status' => Service::STATUS_SUSPENDED, 'suspend_reason' => Service::SUSPEND_REASON_PARENT, 'dunning_suspended_at' => null])->save();
                $addon->setRawAttributes($locked->getAttributes(), true);
            }
        });
    }

    /**
     * Fastened to a service that already has its request at the end of its period: the request is its too, so it is not
     * billed past its parent's end (ADV-634-9).
     */
    public static function fastened(Service $addon): void
    {
        if ($addon->isEnded() || $addon->cancellation()->exists()) {
            return;
        }
        $request = ServiceCancellation::query()->where('service_id', $addon->parent_service_id)->where('type', 'end_of_period')->first();
        if ($request !== null) {
            self::copyRequest($addon->id, (string) $request->reason);
        }
    }

    /**
     * The parent moved to another product: its addons stay (docs/addons.md); those that no longer apply to it
     * (`addon_targets`) are told to staff, who decide (keep, cancel, or move them).
     */
    public static function productChanged(Service $parent): void
    {
        $parent->unsetRelation('product');
        $off = Service::query()->where('parent_service_id', $parent->id)->whereIn('status', Service::PAYING_STATUSES)->with('product')->orderBy('id')->get()
            ->reject(fn (Service $addon) => $addon->product === null || $addon->product->appliesTo($parent));
        if ($off->isEmpty()) {
            return;
        }
        $list = e($off->map(fn (Service $addon) => "#{$addon->id} " . ($addon->product->name ?? ''))->implode("\n"));
        $id = $parent->id;
        $product = e((string) ($parent->product->name ?? ''));
        DB::afterCommit(function () use ($id, $product, $list) {
            try {
                NotificationHelper::sendSystemEmailNotification("Service #{$id}: addons that no longer apply", <<<HTML
                    Service #{$id} is now on {$product}. These addons of it do not apply to that product (Setup → Product Addons,
                    «Applies to»): they stay as they are; keep, cancel or change them.<br>
                    <pre>{$list}</pre>
                    HTML);
            } catch (Throwable $e) {
                report($e);
            }
        });
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
            self::copyRequest((int) $id, (string) $request->reason);
        }
    }

    /**
     * Its copy of the parent's request. One the addon got meanwhile (its own, a double submit) is kept: never a failure that
     * would leave the parent's request without its events (code review 7 of #634), in a savepoint of its own.
     */
    private static function copyRequest(int $addonId, string $reason): void
    {
        try {
            DB::transaction(fn () => (new ServiceCancellation(['service_id' => $addonId, 'type' => 'end_of_period', 'reason' => $reason]))
                ->forceFill(['with_parent' => true])->save());
        } catch (UniqueConstraintViolationException) {
            // it has its own request already
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
