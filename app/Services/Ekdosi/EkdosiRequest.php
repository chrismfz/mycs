<?php

namespace App\Services\Ekdosi;

use App\Admin\Resources\InvoiceResource;
use App\Helpers\NotificationHelper;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Support\Actor;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
     * A PAID invoice with the bridge on and no document yet is in Ekdosi's inbox (`paid_unfiled`) with no request at all,
     * and a person issues from the copy read there. Staff may change its lines and notes now (owner 2026-11-10), so they
     * are told the same thing as for a withdrawn request (REV-780-8): once per invoice and request, when the change is
     * committed. Not an account with «ignore invoicing»: the feed never lists it.
     */
    public static function changedInInbox(int $invoiceId, string $why): void
    {
        if (!config('settings.ekdosi_enabled')) {
            return;
        }
        $invoice = Invoice::query()->with('client')->whereKey($invoiceId)->where('status', Invoice::STATUS_PAID)->first();
        if ($invoice === null || $invoice->client?->ignore_invoicing || EkdosiDocument::query()->where('invoice_id', $invoiceId)->where('state', EkdosiDocument::ACTIVE)->exists()) {
            return;
        }
        // Once per invoice for a while (a Save changes several lines), by a short cache key: never for a process's lifetime,
        // which in a queue worker is days (review 2 of #780).
        if (Cache::add("ekdosi.inbox.told.{$invoiceId}", true, 300)) {
            self::tellStaff($invoice, $why, inbox: true);
        }
    }

    /**
     * Ekdosi may hold the copy it read while the request stood, and a person issues from it there: staff are told to
     * archive it, once (there is a request to withdraw only once), when the change is committed (REV-778-3). Staff at the
     * keyboard see it on their screen; anyone else's change (the cron, the customer, the API) is mailed to staff.
     */
    private static function tellStaff(Invoice $invoice, string $why, bool $inbox = false): void
    {
        $reason = __(match (true) {
            $why === 'lines_changed' => 'its lines changed',
            $why === 'adjusted' => 'a credit or debit note',
            $why === 'moved' => 'another customer or currency',
            str_starts_with($why, 'status_') => 'now ' . substr($why, 7),
            default => $why,
        });
        $message = $inbox
            ? __('Invoice :invoice is paid and in Ekdosi\'s inbox, and has changed (:reason): archive its copy in Ekdosi\'s inbox and fetch it again before issuing.',
                ['invoice' => '#' . ($invoice->number ?: $invoice->id), 'reason' => $reason])
            : __('Invoice :invoice was sent to Ekdosi and has changed since (:reason): archive its copy in Ekdosi\'s inbox, and send it again when it is ready.',
                ['invoice' => '#' . ($invoice->number ?: $invoice->id), 'reason' => $reason]);
        $title = $inbox ? 'Paid invoice changed, check Ekdosi\'s inbox' : 'Request to Ekdosi withdrawn';
        $atKeyboard = Auth::guard('staff')->check() && !Actor::impersonating(); // in «Login as client» the panel's notice is not shown
        DB::afterCommit(fn () => rescue(fn () => $atKeyboard
            ? Notification::make()->warning()->persistent()->title(__($title))->body($message)->send()
            : NotificationHelper::sendSystemEmailNotification($title . ': invoice #' . $invoice->id,
                e($message) . '<br><a href="' . e(InvoiceResource::getUrl('edit', ['record' => $invoice->id], panel: 'admin')) . '">#' . $invoice->id . '</a>')));
    }
}
