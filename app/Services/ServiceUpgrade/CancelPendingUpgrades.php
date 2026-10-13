<?php

namespace App\Services\ServiceUpgrade;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\CancelOpenInvoices;
use Illuminate\Support\Facades\DB;

/**
 * A service that ends takes its pending upgrades with it (audit sweep SVC-6, as WHMCS): paid later, an upgrade would
 * change a dead service and give stock back a second time. Each upgrade is cancelled, and its part of its open invoice
 * (an invoice that bills something else too keeps the rest). A paid one is left alone: ServiceUpgradeService refuses
 * it, and its money is the staff's to refund or credit.
 */
class CancelPendingUpgrades
{
    /** @return array<string, \Throwable> invoices left open or failed, as CancelOpenInvoices returns them */
    public static function forService(Service $service, string $reason): array
    {
        $left = [];
        // Service, then upgrade: the order ServiceUpgradeService takes (the caller holds the service's lock or not).
        $upgrades = DB::transaction(fn () => ServiceUpgrade::query()->where('service_id', $service->id)
            ->where('status', ServiceUpgrade::STATUS_PENDING)->lockForUpdate()->get());
        foreach ($upgrades as $upgrade) {
            if ($upgrade->invoice?->status === Invoice::STATUS_PAID) {
                continue;
            }
            $upgrade->update(['status' => ServiceUpgrade::STATUS_CANCELLED]);
            if ($upgrade->invoice !== null && in_array($upgrade->invoice->status, Invoice::OPEN_STATUSES, true)) {
                $left += CancelOpenInvoices::handle([$upgrade->invoice], $reason, $upgrade);
            }
        }

        return $left;
    }
}
