<?php

namespace App\Services\Service;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Money\PaymentsLog;
use App\Services\ServiceUpgrade\CancelPendingUpgrades;
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
 */
class DeleteService
{
    public function handle(Service $service, bool $terminate = false): void
    {
        // Before anything outside the database (the server, the gateway): what the cancellation would leave open, with
        // its own rules and net amounts, run once and rolled back (N1/N2 of #82). Nothing is touched when it refuses.
        $left = $this->dryRun($service);
        if ($left === null) {
            return; // already deleted (a double click, two calls): nothing outside the database is touched
        }
        if ($left !== []) {
            throw new DisplayException(__('This service cannot be deleted yet: :reasons', [
                'reasons' => implode(' ', array_map(fn (\Throwable $reason) => $reason->getMessage(), $left)),
            ]));
        }
        $outside = false; // something done outside the database: the server's account, the gateway's subscription
        if ($terminate && !$service->isEnded() && $service->status !== Service::STATUS_PENDING && $service->provisioningServer() !== null) {
            ExtensionHelper::terminateServer($service); // throws when the server refuses: nothing is deleted
            $outside = true;
        }
        if ($service->subscription_id && !$service->isEnded()) {
            $outside = true;
        }
        if ($service->subscription_id && !$service->isEnded() && !ExtensionHelper::cancelSubscription($service)) {
            // No gateway knows the id (an imported or legacy one): deleted anyway, staff check it at the gateway.
            PaymentsLog::warning('Service deleted with a subscription no gateway cancelled: check it at the gateway.', [
                'service_id' => $service->id, 'subscription_id' => $service->subscription_id,
            ]);
        }

        try {
            $this->deleteUnderLock($service);
        } catch (DisplayException $e) {
            // Money landed between the check and now (D2 of #82), after the account or the subscription went: the
            // service is not left running without them. Marked terminated (its open invoices with it), staff told.
            if ($outside) {
                $service->markTerminated();
                throw new DisplayException(__('The service was terminated but not deleted: :reason', ['reason' => $e->getMessage()]));
            }
            throw $e;
        }
    }

    private function deleteUnderLock(Service $service): void
    {
        DB::transaction(function () use ($service) {
            $locked = $this->cancelUnderLock($service);
            if ($locked === null) {
                return;
            }
            if (!$locked->isEnded() && $locked->product?->stock !== null) {
                $locked->product->increment('stock', $locked->quantity);
            }
            $locked->properties()->delete();
            $locked->delete(); // its options go in ServiceObserver::deleted
        });
    }

    /** @return array<string, \Throwable>|null what the cancellation would leave open, nothing kept; null: no service */
    private function dryRun(Service $service): ?array
    {
        $left = [];
        DB::beginTransaction();
        try {
            $found = $this->cancelUnderLock($service, $left) !== null;
        } finally {
            DB::rollBack();
        }

        return $found ? $left : null;
    }

    /**
     * Under the service's lock (service → upgrade → customer → invoice): its pending upgrades and unpaid lines cancelled,
     * also one the cron billed a moment ago. Anything left open (money on it, a line it cannot judge) refuses the delete.
     *
     * @param  array<string, \Throwable>|null  $left  filled instead of refusing (the dry run)
     */
    private function cancelUnderLock(Service $service, ?array &$left = null): ?Service
    {
        $locked = Service::query()->whereKey($service->id)->lockForUpdate()->first();
        if ($locked === null) {
            return null;
        }
        $reason = __('invoices.cancellation_reasons.service_deleted');
        $kept = CancelPendingUpgrades::forService($locked, $reason);
        $kept += CancelOpenInvoices::handle($locked->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get(), $reason, $locked);
        if ($left !== null) {
            $left = $kept;
        } elseif ($kept !== []) {
            throw new DisplayException(__('This service cannot be deleted yet: :reasons', [
                'reasons' => implode(' ', array_map(fn (\Throwable $reason) => $reason->getMessage(), $kept)),
            ]));
        }

        return $locked;
    }
}
