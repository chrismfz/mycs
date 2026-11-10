<?php

namespace App\Services\Service;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Service;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Money\PaymentsLog;
use App\Services\ServiceUpgrade\CancelPendingUpgrades;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a service, whatever its status (audit sweep DATA-2, SVC-7, AUTH-9): one way for every path (its page,
 * the bulk actions, the order's services, the API). As WHMCS, a record staff remove, e.g. an account already
 * terminated on the server (WHM): with $terminate the server's account goes first (not for a pending one: it was
 * never created), and if the server refuses nothing is deleted (the username would be lost with a live account).
 *
 * Not while the cancellation of its open invoices would leave one open (money a gateway or a transfer paid on it, net of
 * refunds and credit; a line it cannot judge): staff settle it first, or it would bill a service that is gone (R4/R5,
 * N1/N2 of #82). Checked before anything outside the database. Its gateway subscription is cancelled first (it would
 * keep charging, and its payments would find no service).
 *
 * Then, under the service's lock (service → upgrade → customer → invoice, docs/money-trail.md §2): its unpaid lines and
 * pending upgrades are cancelled, also one the cron billed a moment ago; its options and properties go; a service that
 * had not ended gives its stock back. Paid lines stay, as the history of what was paid.
 *
 * Not while an open termination or task for staff is in Pending Module Actions (it would go with the service by cascade):
 * staff close it first. One its own termination leaves (by hand) keeps the service, terminated, with the task open.
 *
 * A main service with addons still running (owner 2026-11-10, as WHMCS; docs/staff-freedom.md): with $withAddons, what
 * staff confirmed on its page, they are deleted with it; without it the delete is refused, as before (the API's default).
 * Each addon is checked with these same rules before anything is touched, and one that refuses (money on its invoice, an
 * open task) stops the whole delete, named. The main service's account goes first (a server that refuses keeps them all:
 * its machine covers its addons, so no addon gets a task of its own); then, in one transaction, the service is locked,
 * its addons by id, then each one's upgrades, customer and invoices (docs/money-trail.md §2): the addons go, then the
 * service, or nothing does. Ended addons stay as services of their own (the foreign key clears their parent).
 */
class DeleteService
{
    public function handle(Service $service, bool $terminate = false, bool $withAddons = false): void
    {
        $addons = self::runningAddons($service);
        if ($addons->isNotEmpty() && !$withAddons) {
            throw new DisplayException(__('services.addons.delete_parent'));
        }
        // Before anything outside the database (the server, the gateway): nothing is touched when one refuses.
        if (!$this->check($service, $addons)) {
            return; // already deleted (a double click, two calls): nothing outside the database is touched
        }
        // Those with something done outside the database: the server's account, a subscription.
        $outside = [];
        if ($terminate && !$service->isEnded() && $service->status !== Service::STATUS_PENDING && $service->hasServerActions()) {
            ExtensionHelper::terminateServer($service); // throws when the server refuses: nothing is deleted
            $outside[] = $service;
        }
        foreach ([$service, ...$addons] as $one) {
            if ($this->endSubscription($one) && !in_array($one, $outside, true)) {
                $outside[] = $one;
            }
        }

        try {
            $this->deleteUnderLock($service, self::ids($addons));
        } catch (DisplayException $e) {
            // Money landed between the check and now (D2 of #82), after the account or the subscription went: the
            // service is not left running without them. Marked terminated (its open invoices and its addons with it),
            // staff told.
            foreach ($outside as $one) {
                $one->markTerminated();
            }
            if (in_array($service, $outside, true)) {
                throw new DisplayException(__('The service was terminated but not deleted: :reason', ['reason' => $e->getMessage()]));
            }
            throw $e;
        }
    }

    /**
     * Its addons still running (pending, active, suspended), by id: what its delete takes with it once staff confirm.
     *
     * @return Collection<int, Service>
     */
    public static function runningAddons(Service $service): Collection
    {
        return Service::query()->where('parent_service_id', $service->id)->whereIn('status', Service::PAYING_STATUSES)
            ->with('product')->orderBy('id')->get();
    }

    /** What the confirmation names: «#12 Extra IPv4 ×2, #13 Backup». */
    public static function addonList(Service $service): string
    {
        return self::runningAddons($service)->map(fn (Service $addon) => "#{$addon->id} " . ($addon->product->name ?? '')
            . ((int) $addon->quantity > 1 ? " ×{$addon->quantity}" : ''))->implode(', ');
    }

    /**
     * What refuses its delete, checked with nothing kept: what the cancellation would leave open, with its own rules and
     * net amounts, run once and rolled back (N1/N2 of #82); an open termination or task for staff, which would go with it
     * by cascade and nobody would see the account still on the server (sweep3 L-DATA-INTEGRITY-2). Its running addons
     * with it, each by the same rules: one that refuses is named.
     *
     * @param  Collection<int, Service>  $addons
     * @return bool false: it is deleted already
     */
    private function check(Service $service, Collection $addons): bool
    {
        $left = $this->dryRun($service, self::ids($addons));
        if ($left === null) {
            return false;
        }
        if (ModuleAction::openTaskKeeps($service->id)) {
            throw new DisplayException(__('services.delete_open_task'));
        }
        foreach ($addons as $addon) {
            if (ModuleAction::openTaskKeeps($addon->id)) {
                throw self::named($addon, __('services.delete_open_task'));
            }
        }
        if ($left !== []) {
            $why = self::leftOpen($left);
            // An invoice that bills an addon and not the service itself: the addon's, so the addon is named.
            $invoices = array_map('intval', preg_filter('/^invoice_cancelled #(\d+)$/', '$1', array_keys($left)));
            $billed = InvoiceItem::query()->whereIn('invoice_id', $invoices)->where('reference_type', Service::class)->pluck('reference_id')
                ->map(fn ($id) => (int) $id);
            $addon = $billed->contains((int) $service->id) ? null : $addons->first(fn (Service $addon) => $billed->contains((int) $addon->id));

            throw $addon !== null ? self::named($addon, $why) : new DisplayException($why);
        }

        return true;
    }

    /** @param  array<string, \Throwable>  $left */
    private static function leftOpen(array $left): string
    {
        return __('This service cannot be deleted yet: :reasons', [
            'reasons' => implode(' ', array_map(fn (\Throwable $reason) => $reason->getMessage(), $left)),
        ]);
    }

    /** An addon's refusal stops its main service's delete, naming the addon. */
    private static function named(Service $addon, string $reason): DisplayException
    {
        return new DisplayException(__('services.addons.delete_addon_refused', [
            'id' => $addon->id, 'addon' => (string) ($addon->product->name ?? ''), 'reason' => $reason,
        ]));
    }

    /**
     * @param  Collection<int, Service>  $addons
     * @return list<int>
     */
    private static function ids(Collection $addons): array
    {
        return array_values($addons->map(fn (Service $addon) => (int) $addon->id)->all());
    }

    /** Its gateway subscription is cancelled (it would keep charging, its payments finding no service); true: it had one. */
    private function endSubscription(Service $service): bool
    {
        if ($service->isEnded()) {
            return false;
        }
        ExtensionHelper::cancelEndedSubscriptions($service); // one that may bill again (ADV-607-1)
        if (!$service->subscription_id) {
            return false;
        }
        if (!ExtensionHelper::cancelSubscription($service)) {
            // No gateway knows the id (an imported or legacy one): deleted anyway, staff check it at the gateway.
            PaymentsLog::warning('Service deleted with a subscription no gateway cancelled: check it at the gateway.', [
                'service_id' => $service->id, 'subscription_id' => $service->subscription_id,
            ]);
        }

        return true;
    }

    /** @param  list<int>  $confirmed  its running addons staff confirmed, deleted with it */
    private function deleteUnderLock(Service $service, array $confirmed = []): void
    {
        DB::transaction(function () use ($service, $confirmed) {
            $held = $this->cancelUnderLock($service, $confirmed);
            if ($held === null) {
                return;
            }
            [$locked, $addons] = $held;
            // Its termination just left a task for staff (a machine with no API, Proxmox «Terminate: stop», an addon), or
            // one opened meanwhile: kept, terminated, with the task open (PROVISIONING-5, F-PROVISIONING-OPS-2); its
            // addons with it (ended by AddonCascade), none of them deleted.
            if (ModuleAction::openTaskKeeps($locked->id)) {
                throw new DisplayException(__('services.delete_open_task'));
            }
            foreach ($addons as $addon) {
                if (ModuleAction::openTaskKeeps($addon->id)) {
                    throw self::named($addon, __('services.delete_open_task'));
                }
            }
            foreach ([...$addons, $locked] as $one) {
                if (!$one->isEnded() && $one->product?->stock !== null) {
                    $one->product->increment('stock', $one->quantity);
                }
                $one->properties()->delete();
                $one->delete(); // its options go in ServiceObserver::deleted
            }
        });
    }

    /**
     * @param  list<int>  $confirmed
     * @return array<string, \Throwable>|null what the cancellation would leave open, nothing kept; null: no service
     */
    private function dryRun(Service $service, array $confirmed): ?array
    {
        $left = [];
        DB::beginTransaction();
        try {
            $found = $this->cancelUnderLock($service, $confirmed, $left) !== null;
        } finally {
            DB::rollBack();
        }

        return $found ? $left : null;
    }

    /**
     * Under the service's lock (service → upgrade → customer → invoice): its pending upgrades and unpaid lines cancelled,
     * also one the cron billed a moment ago. Anything left open (money on it, a line it cannot judge) refuses the delete.
     *
     * With running addons (those staff confirmed; another one fastened meanwhile refuses): the service, its addons by id,
     * then the customer, before anything is read (docs/money-trail.md §2, as ChangeCycle), and their open invoices are
     * cancelled as one. Line by line, credit on their common renewal would pay what is left of it at each step: the
     * addon's line gone, the credit renews a service that is deleted a moment later (as REV2-KISS-1).
     *
     * @param  list<int>  $confirmed
     * @param  array<string, \Throwable>|null  $left  filled instead of refusing (the dry run)
     * @return array{0: Service, 1: Collection<int, Service>}|null
     */
    private function cancelUnderLock(Service $service, array $confirmed, ?array &$left = null): ?array
    {
        $locked = Service::query()->whereKey($service->id)->lockForUpdate()->first();
        if ($locked === null) {
            return null;
        }
        $addons = Service::query()->where('parent_service_id', $locked->id)->whereIn('status', Service::PAYING_STATUSES)
            ->with('product')->orderBy('id')->lockForUpdate()->get();
        if (array_diff(self::ids($addons), $confirmed) !== []) {
            throw new DisplayException(__('services.addons.delete_parent'));
        }
        if ($addons->isNotEmpty()) {
            Client::lock((int) $locked->client_id);
        }
        $reason = __('invoices.cancellation_reasons.service_deleted');
        $kept = [];
        foreach ([$locked, ...$addons] as $one) {
            $kept += CancelPendingUpgrades::forService($one, $reason);
        }
        $open = collect([$locked, ...$addons])->flatMap(fn (Service $one) => $one->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get())
            ->unique('id')->sortBy('id')->values();
        $kept += CancelOpenInvoices::handle($open, $reason, $locked, array_values($addons->all()));
        if ($left !== null) {
            $left = $kept;
        } elseif ($kept !== []) {
            throw new DisplayException(self::leftOpen($kept));
        }

        return [$locked, $addons];
    }
}
