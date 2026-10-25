<?php

namespace App\Services\Invoice;

use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ServiceUpgrade;
use App\Services\Money\PaymentsLog;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * A change to a line of an open invoice made by the app (a new recurring amount, an upgrade's price), under the
 * payment locks (docs/money-trail.md §2: service, if the caller holds it, then customer, invoice, lines). An invoice
 * paid, cancelled, or with a payment on it (processing too) keeps its line: a lower price would leave a payment above
 * what is owed, a higher one would reopen what was paid (audit sweep R9 of #68).
 */
class ChangeOpenLine
{
    /** @param  Closure(InvoiceItem): mixed  $change  fills the locked line (its return is ignored); saved if anything changed */
    public static function handle(InvoiceItem $line, Closure $change): bool
    {
        return DB::transaction(function () use ($line, $change) {
            $invoice = (new RecordPaymentService)->lockInvoice($line->invoice_id);
            if (!in_array($invoice->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_DRAFT], true)) {
                return false;
            }
            // Ekdosi issued its fiscal document: the line stays as issued (R2-2 of #459).
            if (EkdosiDocument::activeOn($invoice->id, lock: true)) {
                PaymentsLog::warning('A line of an invoice with a fiscal document from Ekdosi keeps its price: the app would have changed it.', [
                    'invoice_id' => $invoice->id, 'invoice_item_id' => $line->getKey(),
                ]);

                return false;
            }
            if ($invoice->hasPayments(lock: true)) {
                // Kept at its price: staff decide (a note, or a refund of the part over).
                PaymentsLog::warning('A line of an invoice with a payment on it keeps its price: the app would have changed it.', [
                    'invoice_id' => $invoice->id, 'invoice_item_id' => $line->getKey(),
                ]);

                return false;
            }
            $locked = InvoiceItem::query()->whereKey($line->getKey())->lockForUpdate()->first();
            if ($locked === null || (int) $locked->invoice_id !== (int) $invoice->id) {
                return false;
            }
            $change($locked);
            if (!$locked->isDirty()) {
                return false;
            }
            $locked->save();
            $line->setRawAttributes($locked->getAttributes(), true);
            // Down to nothing to pay (a price now free): closed as paid, as a zero invoice is (2b). Its payments are
            // none (above), so nothing becomes credit. Not an upgrade's: one repriced to nothing (a cheaper target)
            // would be applied without its downgrade credit and without anyone deciding it; staff or the customer do.
            if (!InvoiceItem::query()->where('invoice_id', $invoice->id)->where('reference_type', ServiceUpgrade::class)->exists()) {
                (new RecordPaymentService)->settle($invoice->id);
            }

            return true;
        });
    }
}
