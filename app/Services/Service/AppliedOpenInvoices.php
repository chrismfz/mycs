<?php

namespace App\Services\Service;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\RecordPaymentService;

/**
 * Sweep 10 (S10-STAFF-FREEDOM-2, owner 2026-11-12): a completed upgrade whose invoice is unpaid again (its payment
 * taken back), on a service that is no longer on what the upgrade gave it (staff changed the package back). Paying the
 * invoice changes no package again (ServiceUpgradeService: applied once; staff are told then). Staff are warned when
 * they save such a service. Read-only: nothing is refused or changed.
 */
final class AppliedOpenInvoices
{
    /** @return list<string> what to tell staff after they saved the service */
    public static function warnings(Service $service): array
    {
        return ServiceUpgrade::query()->where('service_id', $service->id)->where('status', ServiceUpgrade::STATUS_COMPLETED)
            ->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_UNPAID)->where('client_id', $service->client_id))
            ->with(['configs', 'invoice.items', 'invoice.adjustmentNotes', 'invoice.transactions'])->orderBy('invoice_id')->get()
            ->filter(fn (ServiceUpgrade $upgrade) => !$upgrade->heldBy($service) && RecordPaymentService::toCents($upgrade->invoice->remaining) > 0)
            ->map(fn (ServiceUpgrade $upgrade) => __('Upgrade invoice #:id is still open and its upgrade was already applied: paying it will not change the package again. Cancel it (or collect it and change the package again).', ['id' => $upgrade->invoice_id]))
            ->unique()->values()->all();
    }
}
