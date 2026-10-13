<?php

namespace App\Listeners;

use App\Enums\InvoiceTransactionStatus;
use App\Events\InvoiceTransaction\Created;
use App\Events\InvoiceTransaction\Updated;
use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Support\Facades\DB;

class InvoiceTransactionCreatedListener
{
    /**
     * Handle the event.
     */
    public function handle(Created|Updated $event): void
    {
        // Only money that arrived pays an invoice: a failed or processing transaction never does.
        // No status set on the model means the column default (succeeded).
        if (($event->invoiceTransaction->status ?? InvoiceTransactionStatus::Succeeded) !== InvoiceTransactionStatus::Succeeded) {
            return;
        }
        // Only an open invoice becomes paid (docs/payments.md): a cancelled one has nothing left to pay
        // (its credit note covers it), so any transaction, even 0.00, would turn it paid and renew.
        // Locking reads only: inside a payment's transaction a plain read returns the snapshot of the
        // transaction's first read and may miss a payment or a cancellation committed meanwhile.
        // Customer, then invoice (RecordPaymentService::lockInvoice): settle() below takes them in that order too.
        DB::transaction(function () use ($event) {
            $invoiceId = $event->invoiceTransaction->invoice_id;
            if (!Invoice::query()->whereKey($invoiceId)->exists()) {
                return;
            }
            $invoice = (new RecordPaymentService)->lockInvoice($invoiceId);
            if (!in_array($invoice->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_DRAFT], true)) {
                return;
            }
            $remaining = RecordPaymentService::toCents($invoice->withLockedBalance()->remaining);
            if ($remaining === 0 || ($remaining < 0 && $invoice->status === Invoice::STATUS_DRAFT)) {
                if ($remaining === 0) {
                    $invoice->status = Invoice::STATUS_PAID;
                    $invoice->save();
                }

                return;
            }
            if ($remaining < 0) {
                // Paid over its total (staff brought it down and a payment landed before they closed it): closed with
                // the part over as credit (2b, code review). Refused (a credit purchase, no customer): left to staff.
                try {
                    (new RecordPaymentService)->settle($invoice);
                } catch (DisplayException $e) {
                    report($e);
                }
            }
        });
    }
}
