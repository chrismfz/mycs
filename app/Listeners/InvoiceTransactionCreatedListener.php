<?php

namespace App\Listeners;

use App\Enums\InvoiceTransactionStatus;
use App\Events\InvoiceTransaction\Created;
use App\Events\InvoiceTransaction\Updated;
use App\Models\Invoice;
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
        DB::transaction(function () use ($event) {
            $invoice = Invoice::query()->whereKey($event->invoiceTransaction->invoice_id)->lockForUpdate()->first();
            if ($invoice !== null && in_array($invoice->status, [Invoice::STATUS_PENDING, Invoice::STATUS_DRAFT], true)
                && $invoice->withLockedBalance()->remaining <= 0) {
                $invoice->status = Invoice::STATUS_PAID;
                $invoice->save();
            }
        });
    }
}
