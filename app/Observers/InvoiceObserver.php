<?php

namespace App\Observers;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Events\Invoice as InvoiceEvent;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\CreateInvoiceSnapshotService;
use App\Services\Invoice\GrantBoughtCreditService;
use App\Services\Invoice\ProcessPaidInvoiceService;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Support\Facades\DB;

class InvoiceObserver
{
    /**
     * Handle the Invoice "creating" event.
     */
    public function creating(Invoice $invoice): void
    {
        // Created unpaid (MT-1c): paid follows a recorded payment. The WHMCS importer writes its rows directly.
        if ($invoice->status === Invoice::STATUS_PAID) {
            throw new DisplayException(__('An invoice is created unpaid: it becomes paid when its payment is recorded.'));
        }
        event(new InvoiceEvent\Creating($invoice));
    }

    /**
     * Handle the Invoice "created" event.
     */
    public function created(Invoice $invoice): void
    {
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            $this->createSnapshot($invoice);
        }

        event(new InvoiceEvent\Created($invoice));

        if ($invoice->status === Invoice::STATUS_DRAFT) {
            return;
        }

        $sendEmail = $invoice->send_create_email;

        dispatch(function () use ($invoice, $sendEmail) {
            event(new InvoiceEvent\Finalized($invoice, $sendEmail));
        })->afterResponse();
    }

    /**
     * Handle the Invoice "updating" event.
     */
    public function updating(Invoice $invoice): void
    {
        // A cancelled invoice is never marked paid directly, by anyone (docs/payments.md): it renews nothing
        // and its credit note already covers it. Staff reopen it (pending) and record the payment.
        if ($invoice->isDirty('status') && $invoice->getOriginal('status') === Invoice::STATUS_CANCELLED
            && $invoice->status === Invoice::STATUS_PAID) {
            throw new DisplayException(__('A cancelled invoice cannot be marked paid: reopen it (pending) and record the payment.'));
        }
        // Paid only when nothing is left to pay (docs/money-trail.md, MT-1c; audit B1): staff record the payment (Mark
        // Paid, Add Payment) and the invoice follows, through RecordPaymentService. Marking it paid by its status alone
        // would renew its services and give the credit it sells without any money. Read locked: Invoice::save holds the
        // payment locks for this change.
        if ($invoice->isDirty('status') && $invoice->status === Invoice::STATUS_PAID
            && $invoice->getOriginal('status') !== Invoice::STATUS_PAID) {
            if (RecordPaymentService::toCents($invoice->withLockedBalance()->remaining) > 0) {
                throw new DisplayException(__('An invoice becomes paid when its payment is recorded: use Mark Paid instead.'));
            }
            // The credit it sells is given on paid: only for money that arrived, whatever discount (a note, a negative
            // line) brought its balance to zero.
            if (self::creditToGiveCents($invoice) > self::receivedCents($invoice)) {
                throw new DisplayException(__('A credit purchase becomes paid only when its payment covers the credit it buys.'));
            }
        }
        // Its money belongs to its customer and currency (credit, journal, docs/money-trail.md §8): an invoice with a
        // payment (processing too) keeps both. Staff delete the payment first, then move the invoice. Read locked:
        // Invoice::save holds the payment locks for this change, so a webhook cannot land in between.
        if ($invoice->isDirty(['user_id', 'currency_code']) && $invoice->hasPayments(lock: true)) {
            throw new DisplayException(__('An invoice with payments keeps its customer and currency: delete its payments first (a refunded payment keeps the invoice where it is).'));
        }
        event(new InvoiceEvent\Updating($invoice));
    }

    /** The credit its lines still have to give (applied lines gave theirs already). Lines read locked. */
    private static function creditToGiveCents(Invoice $invoice): int
    {
        return (int) $invoice->items->where('reference_type', Credit::class)->whereNull('applied_at')
            ->sum(fn (InvoiceItem $item) => max(0, RecordPaymentService::toCents($item->price) * (int) $item->quantity));
    }

    /** Money that arrived on the invoice and stays on it: succeeded payments less refunds and what became credit. */
    private static function receivedCents(Invoice $invoice): int
    {
        return (int) $invoice->transactions->where('status', InvoiceTransactionStatus::Succeeded)
            ->sum(fn (InvoiceTransaction $transaction) => RecordPaymentService::toCents($transaction->amount)
                - RecordPaymentService::toCents($transaction->refunded_amount) - RecordPaymentService::toCents($transaction->credited_amount));
    }

    /**
     * Handle the Invoice "updated" event.
     */
    public function updated(Invoice $invoice): void
    {
        if ($invoice->status === Invoice::STATUS_CANCELLED && $invoice->getOriginal('status') !== Invoice::STATUS_CANCELLED) {
            $invoice->createCancellationCreditNote($invoice->cancellation_reason);
        }

        // Reopened: the cancellation's credit note no longer applies, the invoice is payable again
        // (docs/payments.md). Refund notes and other adjustments stay as they are.
        if ($invoice->getOriginal('status') === Invoice::STATUS_CANCELLED
            && in_array($invoice->status, [Invoice::STATUS_PENDING, Invoice::STATUS_DRAFT], true)) {
            $invoice->adjustmentNotes()->where('is_cancellation', true)
                ->where('status', AdjustmentNoteStatus::Active->value)->get()
                ->each(fn (AdjustmentNote $note) => $note->update(['status' => AdjustmentNoteStatus::Voided]));
            $invoice->unsetRelation('adjustmentNotes');
        }

        if ($invoice->getOriginal('status') === Invoice::STATUS_DRAFT && $invoice->status === Invoice::STATUS_PENDING) {
            $this->createSnapshot($invoice);
            event(new InvoiceEvent\Created($invoice));

            $sendEmail = $invoice->send_create_email;

            dispatch(function () use ($invoice, $sendEmail) {
                event(new InvoiceEvent\Finalized($invoice, $sendEmail));
            })->afterResponse();
        }

        if ($invoice->isDirty('status') && $invoice->status == 'paid') {
            app(GrantBoughtCreditService::class)->handle($invoice);

            DB::afterCommit(function () use ($invoice) {
                app(ProcessPaidInvoiceService::class)->handle($invoice);
                event(new InvoiceEvent\Paid($invoice));
            });
        }
        event(new InvoiceEvent\Updated($invoice));
    }

    /**
     * An invoice with money on it is never deleted (docs/payments.md): its transactions would go by cascade,
     * with no event, leaving the credit they created and losing the gateway ids that stop a replayed webhook.
     * A processing payment counts too: when it succeeds it has no invoice to land on. Staff cancel it, or delete its transactions first (each through RecordPaymentService::deleteTransaction).
     */
    public function deleting(Invoice $invoice): void
    {
        if ($invoice->hasPayments(lock: true)) {
            throw new DisplayException(__('An invoice with payments cannot be deleted: cancel it, or delete its transactions first.'));
        }
    }

    /**
     * Handle the Invoice "deleted" event.
     */
    public function deleted(Invoice $invoice): void
    {
        event(new InvoiceEvent\Deleted($invoice));
    }

    /**
     * Create a snapshot of the invoice's user data at the time of finalization.
     */
    private function createSnapshot(Invoice $invoice): void
    {
        app(CreateInvoiceSnapshotService::class)->handle($invoice);
    }
}
