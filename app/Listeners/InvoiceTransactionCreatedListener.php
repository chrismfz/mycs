<?php

namespace App\Listeners;

use App\Events\InvoiceTransaction\Created;
use App\Events\InvoiceTransaction\Updated;
use App\Models\Invoice;

class InvoiceTransactionCreatedListener
{
    /**
     * Handle the event.
     */
    public function handle(Created|Updated $event): void
    {
        $invoice = $event->invoiceTransaction->invoice;
        // Only an open invoice becomes paid (docs/payments.md): a cancelled one has nothing left to pay
        // (its credit note covers it), so any transaction, even 0.00, would turn it paid and renew.
        if (in_array($invoice->status, [Invoice::STATUS_PENDING, Invoice::STATUS_DRAFT], true) && $invoice->remaining <= 0) {
            $invoice->status = Invoice::STATUS_PAID;
            $invoice->save();
        }
    }
}
