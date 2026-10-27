<?php

namespace App\Services\Ekdosi;

use App\Models\Invoice;
use Illuminate\Support\Facades\Auth;
use OwenIt\Auditing\Events\AuditCustom;

/** «Send to Ekdosi» (E5, docs/ekdosi-bridge.md): what the app does to a request staff made (App\Admin\Actions\SendToEkdosi). */
final class EkdosiRequest
{
    /**
     * The request is gone, as the app decided (cancelled, refunded, set back to unpaid, moved, another currency, its lines
     * or notes changed, merged into, its document issued): cleared in the database (it was written by a query; a model may predate it) and in the invoice's audit with
     * why (R2-1 of #478). Nothing when there was none.
     */
    public static function forget(int $invoiceId, string $why): void
    {
        // A plain read first: the write (a row lock on the invoice) only where there is a request, rare, so the hot paths
        // that add lines take no new lock (round 3 of #478).
        if (!Invoice::query()->whereKey($invoiceId)->whereNotNull('ekdosi_requested_at')->exists()) {
            return;
        }
        if (Invoice::query()->whereKey($invoiceId)->whereNotNull('ekdosi_requested_at')
            ->update(['ekdosi_requested_at' => null, 'ekdosi_requested_by' => null]) === 0) {
            return;
        }
        $invoice = Invoice::query()->find($invoiceId);
        if ($invoice === null) {
            return;
        }
        $invoice->auditEvent = 'ekdosi_request_withdrawn';
        $invoice->isCustomEvent = true;
        $invoice->auditCustomOld = ['ekdosi_requested' => true];
        $invoice->auditCustomNew = ['ekdosi_requested' => false, 'reason' => $why, 'staff_id' => Auth::guard('staff')->id()];
        event(new AuditCustom($invoice));
    }
}
