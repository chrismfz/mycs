<?php

namespace App\Services\Invoice;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Collection;

/**
 * An open invoice that changed customer (staff's edit, the API, MoveToClient): addressed and taxed as the new one's
 * (sweep 9γ, owner 2026-10-30: keep it simple). What was the first customer's is refused before (InvoiceObserver: one with
 * payments, one renewing the first customer's services); what is left is billed to the right one.
 */
final class InvoiceReaddress
{
    /**
     * Its lines' tax decided again for the new customer (a company in the EU, another country: ADV-1 of MoveToClient).
     *
     * @param  Collection<int, InvoiceItem>|null  $lines  its lines (all when null)
     */
    public static function retax(Invoice $invoice, ?Collection $lines = null): void
    {
        $lines ??= $invoice->items()->get();
        foreach ($lines as $line) {
            $line->refresh();
            $line->setRelation('invoice', $invoice);
            $line->forceFill(LineTax::decide($line))->save();
        }
    }

    /** Its snapshot (taken when it was issued, the first customer's name and tax) taken again for the new one. */
    public static function readdress(Invoice $invoice): void
    {
        $snapshot = $invoice->snapshot()->first();
        if ($snapshot === null) {
            return;
        }
        $snapshot->delete();
        $invoice->unsetRelation('snapshot');
        app(CreateInvoiceSnapshotService::class)->handle($invoice);
    }
}
