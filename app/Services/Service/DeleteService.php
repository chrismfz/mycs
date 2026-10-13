<?php

namespace App\Services\Service;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\ServiceUpgrade\CancelPendingUpgrades;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a service, whatever its status (audit sweep DATA-2, SVC-7, AUTH-9): one way for every path (its page,
 * the bulk actions, the order's services, the API). As WHMCS, a record staff remove, e.g. an account already
 * terminated on the server (WHM): with $terminate the server's account goes first, and if the server refuses nothing
 * is deleted (the username would be lost with a live account behind it).
 *
 * What would point at nothing goes with it: its unpaid lines and pending upgrades are cancelled (an invoice the
 * customer could pay for a service that is gone; one holding money stays open for staff, as CancelOpenInvoices keeps
 * it), its options and properties are deleted, and a service that had not ended gives its stock back. Paid lines stay,
 * as the history of what was paid (the pages and emails read them without their service).
 */
class DeleteService
{
    /** @return array<string, \Throwable> invoices left open for staff, as CancelOpenInvoices returns them */
    public function handle(Service $service, bool $terminate = false): array
    {
        if ($terminate && !$service->isEnded() && $service->product?->server_id !== null) {
            ExtensionHelper::terminateServer($service); // throws when the server refuses: nothing is deleted
        }

        $reason = __('invoices.cancellation_reasons.service_deleted');
        $left = CancelPendingUpgrades::forService($service, $reason);
        $left += CancelOpenInvoices::handle($service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get(), $reason, $service);

        DB::transaction(function () use ($service) {
            $locked = Service::query()->whereKey($service->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }
            if (!$locked->isEnded() && $locked->product?->stock !== null) {
                $locked->product->increment('stock', $locked->quantity);
            }
            $locked->properties()->delete();
            $locked->delete(); // its options go in ServiceObserver::deleted
        });

        return $left;
    }
}
