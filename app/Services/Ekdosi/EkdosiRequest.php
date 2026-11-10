<?php

namespace App\Services\Ekdosi;

use App\Admin\Resources\InvoiceResource;
use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use App\Support\Actor;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Events\AuditCustom;

/** «Send to Ekdosi» (E5, docs/ekdosi-bridge.md): what the app does to a request staff made (App\Admin\Actions\SendToEkdosi). */
final class EkdosiRequest
{
    /**
     * The request is gone, as the app decided (cancelled, refunded, set back to unpaid, moved, another currency, its lines
     * or notes changed, its document issued): cleared in the database (it was written by a query; a model may predate it) and in the invoice's audit with
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
        if ($why !== 'issued') {
            self::tellStaff($invoice, $why);
        }
    }

    /**
     * Ekdosi may hold the copy it read while the request stood, and a person issues from it there: staff are told to
     * archive it, once (there is a request to withdraw only once), when the change is committed (REV-778-3). Staff at the
     * keyboard see it on their screen; anyone else's change (the cron, the customer, the API) is mailed to staff.
     */
    private static function tellStaff(Invoice $invoice, string $why): void
    {
        $reason = __(match (true) {
            $why === 'lines_changed' => 'its lines changed',
            $why === 'adjusted' => 'a credit or debit note',
            $why === 'moved' => 'another customer or currency',
            str_starts_with($why, 'status_') => 'now ' . substr($why, 7),
            default => $why,
        });
        $message = __('Invoice :invoice was sent to Ekdosi and has changed since (:reason): archive its copy in Ekdosi\'s inbox, and send it again when it is ready.',
            ['invoice' => '#' . ($invoice->number ?: $invoice->id), 'reason' => $reason]);
        $atKeyboard = Auth::guard('staff')->check() && !Actor::impersonating(); // in «Login as client» the panel's notice is not shown
        DB::afterCommit(fn () => rescue(fn () => $atKeyboard
            ? Notification::make()->warning()->persistent()->title(__('Request to Ekdosi withdrawn'))->body($message)->send()
            : NotificationHelper::sendSystemEmailNotification('Request to Ekdosi withdrawn: invoice #' . $invoice->id,
                e($message) . '<br><a href="' . e(InvoiceResource::getUrl('edit', ['record' => $invoice->id], panel: 'admin')) . '">#' . $invoice->id . '</a>')));
    }
}
