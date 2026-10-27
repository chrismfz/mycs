<?php

namespace App\Observers;

use App\Enums\AdjustmentNoteStatus;
use App\Events\AdjustmentNote as AdjustmentNoteEvent;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Services\Ekdosi\EkdosiRequest;

class AdjustmentNoteObserver
{
    /**
     * Handle the AdjustmentNote "creating" event.
     */
    public function creating(AdjustmentNote $adjustmentNote): void
    {
        self::refuseStaffNoteOnPaidInvoice($adjustmentNote);
        event(new AdjustmentNoteEvent\Creating($adjustmentNote));
    }

    /**
     * A paid invoice keeps its total (MT-1c): staff notes are written or voided while it is open, else it stays paid
     * with a balance (or with money owed back). A refund's and a cancellation's notes follow their own events.
     */
    private static function refuseStaffNoteOnPaidInvoice(AdjustmentNote $note): void
    {
        if ($note->isVoidableByStaff() && in_array(Invoice::query()->whereKey($note->invoice_id)->value('status'), Invoice::FROZEN_STATUSES, true)) {
            throw new DisplayException(__('A paid invoice keeps its total: set it back to unpaid to change its adjustment notes.'));
        }
        // Nor one Ekdosi issued its document for (final review of #459): its total is the document's.
        if ($note->isVoidableByStaff() && EkdosiDocument::activeOn($note->invoice_id)) {
            throw new DisplayException(__('This invoice has a fiscal document from Ekdosi: its total stays as issued. Cancel the document there first.'));
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
            self::refuseStaffNoteOnPaidInvoice($adjustmentNote);
        }
    }

    /** Append-only: a note goes only with its invoice (an invoice without payments, deleted by staff). */
    public function deleting(AdjustmentNote $adjustmentNote): void
    {
        throw new DisplayException(__('An adjustment note is never deleted: void it instead.'));
    }
}
