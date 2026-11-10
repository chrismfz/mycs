<?php

namespace App\Observers;

use App\Enums\AdjustmentNoteStatus;
use App\Events\AdjustmentNote as AdjustmentNoteEvent;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Ekdosi\EkdosiRequest;

class AdjustmentNoteObserver
{
    /**
     * Handle the AdjustmentNote "creating" event.
     */
    public function creating(AdjustmentNote $adjustmentNote): void
    {
        self::refuseStaffNoteOnFinalInvoice($adjustmentNote);
        self::refuseStaffNoteOnCreditPurchase($adjustmentNote);
        event(new AdjustmentNoteEvent\Creating($adjustmentNote));
    }

    /**
     * A refunded invoice keeps its total (PR 2c: final). A paid one takes staff's notes (owner 2026-11-10,
     * docs/staff-freedom.md): it stays paid, and its page shows what it then owes or holds over. A refund's and a
     * cancellation's notes follow their own events.
     */
    private static function refuseStaffNoteOnFinalInvoice(AdjustmentNote $note): void
    {
        if ($note->isVoidableByStaff() && Invoice::query()->whereKey($note->invoice_id)->value('status') === Invoice::STATUS_REFUNDED) {
            throw new DisplayException(__('A refunded invoice keeps its total: its adjustment notes stay as they are.'));
        }
        // Nor one Ekdosi issued its document for (final review of #459): its total is the document's.
        if ($note->isVoidableByStaff() && EkdosiDocument::activeOn($note->invoice_id)) {
            throw new DisplayException(__('This invoice has a fiscal document from Ekdosi: its total stays as issued. Cancel the document there first.'));
        }
    }

    /**
     * A credit purchase («Add funds») gives the credit its lines say, for the money that arrived (InvoiceObserver): with
     * a note on it, the payment of what is left never covers the credit and the invoice cannot become paid
     * (S6-MONEY-LEDGER-1). No staff note that lowers it: staff change the amount of its line (StaffLineEdit, while it has no payment), or cancel it and make a new one.
     * Only while it still has credit to give: one that gave its credit (charged back since, written off by a note)
     * and a note that raises it are as before.
     */
    private static function refuseStaffNoteOnCreditPurchase(AdjustmentNote $note): void
    {
        if ($note->isVoidableByStaff() && bccomp((string) $note->amount, '0', 4) < 0
            && InvoiceItem::query()->where('invoice_id', $note->invoice_id)->where('reference_type', Credit::class)->whereNull('applied_at')->exists()) {
            throw new DisplayException(__('An invoice that buys account credit takes no adjustment note or discount: change the amount of its line instead (or cancel it and make a new one).'));
        }
    }

    /** A note changes what is owed: a request to Ekdosi (E5) was for the total as it stood (R3-1 of #478). */
    public function created(AdjustmentNote $adjustmentNote): void
    {
        EkdosiRequest::forget((int) $adjustmentNote->invoice_id, 'adjusted');
    }

    public function updated(AdjustmentNote $adjustmentNote): void
    {
        if ($adjustmentNote->wasChanged('status')) {
            EkdosiRequest::forget((int) $adjustmentNote->invoice_id, 'adjusted');
        }
    }

    /**
     * Append-only (docs/money-trail.md, MT-1c; audit INV-9): a note is never edited, only voided or unvoided. A wrong
     * note is voided and a new one written. A refund's note follows its transaction (refunded_amount): never voided.
     */
    public function updating(AdjustmentNote $adjustmentNote): void
    {
        if (array_diff(array_keys($adjustmentNote->getDirty()), ['status', 'updated_at']) !== []) {
            throw new DisplayException(__('An adjustment note is never edited: void it and write a new one.'));
        }
        if ($adjustmentNote->isDirty('status') && $adjustmentNote->is_refund
            && $adjustmentNote->status !== AdjustmentNoteStatus::Active) {
            throw new DisplayException(__('A refund\'s adjustment note follows its refund: it is never voided.'));
        }
        if ($adjustmentNote->isDirty('status')) {
            self::refuseStaffNoteOnFinalInvoice($adjustmentNote);
        }
        // Voiding a note on a credit purchase puts its amount right; bringing one back does not.
        if ($adjustmentNote->isDirty('status') && $adjustmentNote->status === AdjustmentNoteStatus::Active) {
            self::refuseStaffNoteOnCreditPurchase($adjustmentNote);
        }
    }

    /** Append-only: a note goes only with its invoice (an invoice without payments, deleted by staff). */
    public function deleting(AdjustmentNote $adjustmentNote): void
    {
        throw new DisplayException(__('An adjustment note is never deleted: void it instead.'));
    }
}
