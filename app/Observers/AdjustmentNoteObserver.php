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
use App\Services\Invoice\UndoRefund;

class AdjustmentNoteObserver
{
    /**
     * Handle the AdjustmentNote "creating" event.
     */
    public function creating(AdjustmentNote $adjustmentNote): void
    {
        self::refuseStaffNoteOnFinalInvoice($adjustmentNote);
        self::refuseStaffNoteOnCreditPurchase($adjustmentNote);
        self::refuseOwing($adjustmentNote, 0, self::counted($adjustmentNote));
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
        // Nor one paid before the cut-over: its document was issued through WHMCS (REV-780-7).
        if ($note->isVoidableByStaff() && EkdosiDocument::issuedBeforeCutOver($note->invoice_id)) {
            throw new DisplayException(EkdosiDocument::cutOverRefusal());
        }
    }

    /** What the note adds to its invoice's total now, in cents: nothing while voided. */
    private static function counted(AdjustmentNote $note, bool $original = false): int
    {
        $status = $original ? $note->getOriginal('status') : ($note->status ?? AdjustmentNoteStatus::Active);
        $status = $status instanceof AdjustmentNoteStatus ? $status : AdjustmentNoteStatus::tryFrom((string) $status);

        return $status === AdjustmentNoteStatus::Active ? (int) round((float) ($original ? $note->getOriginal('amount') : $note->amount) * 100) : 0;
    }

    /** A staff note that makes a paid invoice owe is staff's word for what it owes (Invoice::owesByStaffsWord). */
    private static function refuseOwing(AdjustmentNote $note, int $before, int $after): void
    {
        if ($note->isVoidableByStaff()) {
            Invoice::owesByStaffsWord($note->invoice_id, $after - $before);
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
        if ($adjustmentNote->isVoidableByStaff()) {
            EkdosiRequest::changedInInbox((int) $adjustmentNote->invoice_id, 'adjusted');
        }
    }

    public function updated(AdjustmentNote $adjustmentNote): void
    {
        if ($adjustmentNote->wasChanged(['status', 'amount'])) {
            EkdosiRequest::forget((int) $adjustmentNote->invoice_id, 'adjusted');
            if ($adjustmentNote->isVoidableByStaff()) {
                EkdosiRequest::changedInInbox((int) $adjustmentNote->invoice_id, 'adjusted');
            }
        }
    }

    /** What staff may correct on a note they wrote (owner 2026-11-10): its text and its amount (the type follows the sign). */
    private const EDITABLE = ['description', 'amount', 'type'];

    /**
     * A note staff wrote is theirs to correct (owner 2026-11-10, docs/staff-freedom.md; it was append-only, MT-1c / audit
     * INV-9): its description and amount change, and it is voided or unvoided, while its invoice is not refunded and has
     * no fiscal document from Ekdosi. Each change is in the note's audit, as a void is. Its invoice and number stay; a
     * refund's note follows its transaction (refunded_amount) and a cancellation's its invoice: never edited by hand.
     */
    public function updating(AdjustmentNote $adjustmentNote): void
    {
        $dirty = array_diff(array_keys($adjustmentNote->getDirty()), ['updated_at']);
        if (array_diff($dirty, ['status', ...self::EDITABLE]) !== []) {
            throw new DisplayException(__('An adjustment note keeps its invoice and its number: only its description and amount are edited.'));
        }
        if (array_intersect($dirty, self::EDITABLE) !== [] && !$adjustmentNote->isVoidableByStaff() && !UndoRefund::undoing()) {
            throw new DisplayException(__('This note follows a refund or a cancellation: it is not edited by hand.'));
        }
        // Voided only with its refund, when staff undo that (UndoRefund, owner 2026-11-10).
        if ($adjustmentNote->isDirty('status') && $adjustmentNote->is_refund
            && $adjustmentNote->status !== AdjustmentNoteStatus::Active && !UndoRefund::undoing()) {
            throw new DisplayException(__('A refund\'s adjustment note follows its refund: it is never voided.'));
        }
        if ($adjustmentNote->isDirty('status') && $adjustmentNote->status === AdjustmentNoteStatus::Active && UndoRefund::undone($adjustmentNote)) {
            throw new DisplayException(__('invoices.refund_undo.note_stays_voided'));
        }
        if ($dirty !== []) {
            self::refuseStaffNoteOnFinalInvoice($adjustmentNote);
            self::refuseOwing($adjustmentNote, self::counted($adjustmentNote, original: true), self::counted($adjustmentNote));
        }
        // Voiding a note on a credit purchase puts its amount right; bringing one back, or lowering it further, does not.
        if ($adjustmentNote->isDirty(['status', 'amount']) && $adjustmentNote->status === AdjustmentNoteStatus::Active) {
            self::refuseStaffNoteOnCreditPurchase($adjustmentNote);
        }
    }

    /**
     * Staff delete a note they wrote, under the same rule as an edit (owner 2026-11-10; a gap in the notes' numbers is
     * fine: they are no fiscal documents). A refund's or a cancellation's note goes only with its invoice.
     */
    public function deleting(AdjustmentNote $adjustmentNote): void
    {
        if (!$adjustmentNote->isVoidableByStaff()) {
            throw new DisplayException(__('This note follows a refund or a cancellation: it is not deleted by hand.'));
        }
        self::refuseStaffNoteOnFinalInvoice($adjustmentNote);
        self::refuseOwing($adjustmentNote, self::counted($adjustmentNote), 0);
    }

    public function deleted(AdjustmentNote $adjustmentNote): void
    {
        EkdosiRequest::forget((int) $adjustmentNote->invoice_id, 'adjusted');
        EkdosiRequest::changedInInbox((int) $adjustmentNote->invoice_id, 'adjusted');
    }
}
