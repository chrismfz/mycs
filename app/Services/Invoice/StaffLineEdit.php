<?php

namespace App\Services\Invoice;

use App\Exceptions\DisplayException;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Staff change an invoice's lines (the admin form, the API): under the payment locks (customer, then invoice), so a
 * payment landing at the same moment is serialised with the change (sweep Π2.11). An invoice with payments on it changes
 * as any other (owner 2026-11-10, docs/staff-freedom.md): a line added or raised just owes more; one taken off or lowered
 * below what was paid leaves it paid over, and staff are told what to do (balanceNotice). Nothing is closed, credited or
 * renewed on its own. The app's own changes have their rules (ChangeOpenLine, CancelOpenInvoices).
 */
final class StaffLineEdit
{
    /**
     * @template T
     *
     * @param  Closure(Invoice): T  $change  runs with the invoice locked
     * @return T
     *
     * @throws DisplayException when it changes amounts on an invoice Ekdosi issued a document for
     */
    public static function run(mixed $invoiceId, bool $changesAmounts, Closure $change): mixed
    {
        return DB::transaction(function () use ($invoiceId, $changesAmounts, $change) {
            $invoice = (new RecordPaymentService)->lockInvoice($invoiceId);
            // A locking read after the locks (final review of #459): the observer's plain read would miss a document
            // Ekdosi wrote back while this waited.
            if ($changesAmounts && EkdosiDocument::activeOn($invoice->id, lock: true)) {
                throw new DisplayException(__('This invoice has a fiscal document from Ekdosi: its lines stay as they are. Cancel the document there first.'));
            }

            return $change($invoice);
        });
    }

    /**
     * What staff are told after its amounts changed, or null when its money and its total still agree: it owes on while
     * paid, or holds more than it bills. Read fresh (call it after the change, under the locks or after them).
     */
    public static function balanceNotice(mixed $invoiceId): ?string
    {
        $invoice = Invoice::query()->with(['items', 'transactions', 'adjustmentNotes'])->find($invoiceId);
        if ($invoice === null || !in_array($invoice->status, [...Invoice::OPEN_STATUSES, Invoice::STATUS_PAID], true)) {
            return null;
        }
        $remaining = RecordPaymentService::toCents($invoice->remaining);
        $figures = ['amount' => number_format(abs($remaining) / 100, 2, '.', ''), 'currency' => $invoice->currency_code];
        $paid = $invoice->status === Invoice::STATUS_PAID;

        return match (true) {
            $paid && $remaining > 0 => __('This invoice stays paid and now owes :amount :currency: record the payment on it («Add payment»), or leave it. The periods it already gave stay given: nothing is renewed or taken back.', $figures),
            $paid && $remaining < 0 => __('This invoice stays paid and is now paid :amount :currency over its total: «Close as paid» gives the part over to the customer as account credit. The periods it already gave stay given: nothing is renewed or taken back.', $figures),
            $remaining < 0 => __('This invoice is now paid :amount :currency over its total: «Close as paid» closes it and gives the part over to the customer as account credit.', $figures),
            $remaining === 0 && $invoice->hasPayments() && $invoice->items->isNotEmpty() => __('Its payments now cover this invoice: «Close as paid» closes it.'),
            default => null,
        };
    }
}
