<?php

namespace App\Services\ServiceUpgrade;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Support\Facades\DB;

/**
 * A pending upgrade given up on: its service ended (audit sweep SVC-6, as WHMCS), or its period passed unpaid (BIL-11).
 * On an end, also the open invoices of its completed upgrades (UPGRADES-3).
 *
 * Locks: service, upgrade, then customer and invoice (docs/money-trail.md §2, the order ServiceUpgradeService takes),
 * and the decision is made under them: a payment that lands meanwhile is either seen (paid: left to
 * ServiceUpgradeService, which applies it or refuses it and tells staff) or comes after (on a cancelled invoice it
 * becomes credit). The upgrade is cancelled only when its part of its invoice is: an invoice kept open for staff (money
 * a gateway paid) keeps the upgrade pending with it, so a payment of the rest is never kept for nothing.
 */
class CancelPendingUpgrades
{
    /**
     * Its service ended: its pending upgrades, and the open invoices of its completed ones.
     *
     * @return array<string, \Throwable> invoices left open or failed, as CancelOpenInvoices returns them
     */
    public static function forService(Service $service, string $reason): array
    {
        return self::pending($service, $reason) + self::shortfalls($service, $reason);
    }

    /**
     * Only its pending upgrades: a service that still runs (a request at the end of its period) keeps a completed
     * upgrade's shortfall, the days it runs on the upgraded package (R-BILLING-FIXES-2). It goes when the service ends.
     *
     * @return array<string, \Throwable>
     */
    public static function pending(Service $service, string $reason): array
    {
        $left = [];
        foreach (ServiceUpgrade::query()->where('service_id', $service->id)->where('status', ServiceUpgrade::STATUS_PENDING)->pluck('id') as $id) {
            $left += self::one((int) $id, $reason)[1];
        }

        return $left;
    }

    /**
     * The open invoices of its completed upgrades (billShortfall: the rest an upgrade owed after a renewal paid before
     * it): they name the upgrade, not the service, so the service's own invoices never held them, and they outlived the
     * service it ended (sweep UPGRADES-3). They go as its own do on an end, «keep overdue invoices» included.
     *
     * @return array<string, \Throwable>
     */
    private static function shortfalls(Service $service, string $reason): array
    {
        return DB::transaction(function () use ($service, $reason) {
            Service::query()->whereKey($service->getKey())->lockForUpdate()->first();
            $left = [];
            foreach (ServiceUpgrade::query()->where('service_id', $service->id)->where('status', ServiceUpgrade::STATUS_COMPLETED)->orderBy('id')->get() as $upgrade) {
                $open = Invoice::query()->whereIn('status', Invoice::OPEN_STATUSES)
                    ->whereHas('items', fn ($line) => $line->where('reference_type', ServiceUpgrade::class)->where('reference_id', $upgrade->id))
                    ->orderBy('id')->get();
                if ($open->isNotEmpty()) {
                    $left += CancelOpenInvoices::handle(CancelOpenInvoices::onServiceEnd($open), $reason, $upgrade);
                }
            }

            return $left;
        });
    }

    /** @return array{0: bool, 1: array<string, \Throwable>} whether it was cancelled, and what was left open */
    public static function one(int $id, string $reason): array
    {
        return DB::transaction(function () use ($id, $reason) {
            $serviceId = ServiceUpgrade::query()->whereKey($id)->value('service_id');
            if ($serviceId !== null) {
                Service::query()->whereKey($serviceId)->lockForUpdate()->first();
            }
            $upgrade = ServiceUpgrade::query()->whereKey($id)->lockForUpdate()->first();
            if ($upgrade === null || $upgrade->status !== ServiceUpgrade::STATUS_PENDING) {
                return [false, []];
            }
            if ($upgrade->invoice_id !== null && Invoice::query()->whereKey($upgrade->invoice_id)->exists()) {
                $invoice = (new RecordPaymentService)->lockInvoice($upgrade->invoice_id);
                if ($invoice->status === Invoice::STATUS_PAID) {
                    return [false, []];
                }
                if (in_array($invoice->status, Invoice::OPEN_STATUSES, true)) {
                    $left = CancelOpenInvoices::handle([$invoice], $reason, $upgrade);
                    if ($left !== []) {
                        return [false, $left];
                    }
                }
            }
            $upgrade->update(['status' => ServiceUpgrade::STATUS_CANCELLED]);

            return [true, []];
        });
    }
}
