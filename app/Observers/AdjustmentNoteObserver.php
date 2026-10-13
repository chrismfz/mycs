<?php

namespace App\Observers;

use App\Enums\AdjustmentNoteStatus;
use App\Events\AdjustmentNote as AdjustmentNoteEvent;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;

class AdjustmentNoteObserver
{
    /**
     * Handle the AdjustmentNote "creating" event.
     */
    public function creating(AdjustmentNote $adjustmentNote): void
    {
        event(new AdjustmentNoteEvent\Creating($adjustmentNote));
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
    }

    /** Append-only: a note goes only with its invoice (an invoice without payments, deleted by staff). */
    public function deleting(AdjustmentNote $adjustmentNote): void
    {
        throw new DisplayException(__('An adjustment note is never deleted: void it instead.'));
    }
}
