<?php

namespace App\Services\Invoice;

use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Service\RenewServiceService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Throwable;

class ProcessPaidInvoiceService
{
    /**
     * Apply a paid invoice: renew its services, do its upgrades. Each line is idempotent (applied_at, the upgrade's
     * status, read under locks), so the cron may run it again on an invoice whose apply_pending_since is still set.
     */
    public function handle(Invoice $invoice): void
    {
        // The marker this run applies: a newer one (paid -> pending -> paid meanwhile) is left for its own run.
        $marker = Invoice::query()->whereKey($invoice->getKey())->value('apply_pending_since');
        $failures = [];
        $invoice->items->each(function ($item) use (&$failures) {
            try {
                // A domain's renewal line (D5b) is left as it is: its applied_at stays null until its registrar renews it
                // (D5b-2 looks for paid domain lines not applied). Not this invoice's marker (review 1: that one would be
                // retried, and its Paid event sent again, every day).
                if ($item->reference_type == Domain::class) {
                    return;
                }
                if ($item->reference_type == Service::class) {
                    $service = $item->reference;
                    if (!$service || !($service instanceof Service)) {
                        return;
                    }
                    (new RenewServiceService)->handle($service, $item);
                } elseif ($item->reference_type == ServiceUpgrade::class) {
                    // ServiceUpgradeService locks the service and the upgrade and reads it again: the cron may apply the
                    // same invoice again (BIL-9); an upgrade is done once, and never on a service that no longer runs.
                    $serviceUpgrade = ServiceUpgrade::query()->find($item->reference_id);
                    if ($serviceUpgrade !== null) {
                        (new ServiceUpgradeService)->handle($serviceUpgrade);
                    }
                }
            } catch (Throwable $e) {
                report($e);
                $failures[] = $e;
            }
        });
        if ($failures !== []) {
            throw $failures[0];
        }
        // Applied (a line with nothing to apply counts too: a cancelled service, a one-time plan). Quietly: no invoice event.
        if ($marker !== null) {
            Invoice::query()->whereKey($invoice->getKey())->where('apply_pending_since', $marker)->update(['apply_pending_since' => null]);
        }
        $invoice->apply_pending_since = null;
        $invoice->syncOriginalAttribute('apply_pending_since');
    }
}
