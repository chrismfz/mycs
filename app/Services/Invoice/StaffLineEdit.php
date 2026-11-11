<?php

namespace App\Services\Invoice;

use App\Exceptions\DisplayException;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Staff change an invoice's lines (the admin form, the API): under the payment locks (customer, then invoice), so a
 * payment landing at the same moment is serialised with the change (sweep Π2.11). On an invoice with a payment on it
 * (owner 2026-11-10, docs/staff-freedom.md) prices change and free-text lines come and go: raised, it owes more; lowered
 * below what was paid, it is paid over and staff are told what to do (balanceNotice). Lines that bill a service or a
 * domain stay on it (refuseReferencedLine). Nothing is closed, credited or renewed on its own. The app's own changes have their rules (ChangeOpenLine, CancelOpenInvoices).
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
     * The one rule of staff's line edits on an invoice that holds money or staff's word (Invoice::holdsMoneyOrWord; the cut
     * of 2026-11-10 after three review rounds found credit made from service lines removed or added again): any line's
     * price, quantity, taxed and description change, down to 0.00; lines that reference nothing are added and removed; a
     * line that references anything (a service, a domain, an upgrade, account credit) is neither added nor removed. The
     * same whether it is paid or set back to unpaid with its payment on it. An invoice with no payment, not paid by hand,
     * is free as before. Call under the payment locks.
     *
     * @param  'add'|'remove'  $what
     */
    public static function refuseReferencedLine(Invoice $invoice, ?string $referenceType, string $what): void
    {
        if ($referenceType === null || $referenceType === '' || !$invoice->holdsMoneyOrWord()) {
            return;
        }
        throw new DisplayException($what === 'remove'
            ? __('This invoice has a payment on it (or was marked paid by hand): a line of a service, a domain or account credit is not removed from it. Set its price to 0.00 instead.')
            : __('This invoice has a payment on it (or was marked paid by hand): a line of a service, a domain or account credit is not added to it. Make a new invoice for it.'));
    }

    /**
     * What staff are told after its amounts changed, or null when its money and its total still agree: it owes on while
     * paid, or holds more than it bills. Read fresh (call it after the change, under the locks or after them).
     */
    public static function balanceNotice(mixed $invoiceId): ?string
    {
        $invoice = Invoice::query()->with(['items', 'transactions', 'adjustmentNotes'])->whereKey($invoiceId)->first();
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
