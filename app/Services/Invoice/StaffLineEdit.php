<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Staff change an invoice's lines (the admin form, the API): under the payment locks (customer, then invoice), so a
 * payment landing at the same moment is serialised with the change (sweep Π2.11). An open invoice with payments on it
 * (processing too) keeps its amounts, as Blesta does: a line taken off or lowered would leave a payment over what is
 * owed on an invoice still unpaid. Staff refund the payment first (back, or to account credit), or give a discount
 * with a note; descriptions still change. The app's own changes have their rules (ChangeOpenLine, CancelOpenInvoices).
 */
final class StaffLineEdit
{
    /**
     * @template T
     *
     * @param  Closure(Invoice): T  $change  runs with the invoice locked
     * @return T
     *
     * @throws DisplayException when it changes amounts on an invoice with payments
     */
    public static function run(mixed $invoiceId, bool $changesAmounts, Closure $change): mixed
    {
        return DB::transaction(function () use ($invoiceId, $changesAmounts, $change) {
            $invoice = (new RecordPaymentService)->lockInvoice($invoiceId);
            if ($changesAmounts) {
                self::refuseWithPayments($invoice);
                // A locking read after the locks (final review of #459): the observer's plain read would miss a document
                // Ekdosi wrote back while this waited.
                if (EkdosiDocument::activeOn($invoice->id, lock: true)) {
                    throw new DisplayException(__('This invoice has a fiscal document from Ekdosi: its lines stay as they are. Cancel the document there first.'));
                }
            }

            return $change($invoice);
        });
    }

    /**
     * Call under the payment locks. A paid or refunded invoice keeps its lines anyway (InvoiceItemObserver).
     *
     * @param  string|null  $statusAfter  the status the same save gives it (a reopening), if any
     */
    public static function refuseWithPayments(Invoice $invoice, ?string $statusAfter = null): void
    {
        $open = in_array($invoice->status, Invoice::OPEN_STATUSES, true) || in_array($statusAfter, Invoice::OPEN_STATUSES, true);
        if ($open && self::holdsMoney($invoice)) {
            throw new DisplayException(__('This invoice has payments: its lines keep their amounts. Refund the payment first (back, or to account credit), or give a discount with a note.'));
        }
    }

    /**
     * Money on it: a payment processing, or succeeded ones less what was refunded or moved to credit (adversarial A2/A3:
     * once refunded in full, as the refusal tells staff to, its lines change again). Read locked under the payment
     * locks; unlocked for the form, which only shows it (InvoiceResource::linesKeepAmounts()).
     */
    public static function holdsMoney(Invoice $invoice, bool $lock = true): bool
    {
        $payments = $invoice->transactions()->whereIn('status', [InvoiceTransactionStatus::Succeeded->value, InvoiceTransactionStatus::Processing->value]);

        return ($lock ? $payments->lockForUpdate() : $payments)->get(['id', 'status', 'amount', 'refunded_amount', 'credited_amount'])
            ->contains(fn ($payment) => $payment->status === InvoiceTransactionStatus::Processing
                || RecordPaymentService::toCents($payment->amount) - RecordPaymentService::toCents($payment->refunded_amount)
                    - RecordPaymentService::toCents($payment->credited_amount) > 0);
    }
}
