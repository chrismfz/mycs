<?php

namespace App\Observers;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Events\Invoice as InvoiceEvent;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Models\Credit;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Services\Invoice\CreateInvoiceSnapshotService;
use App\Services\Invoice\GrantBoughtCreditService;
use App\Services\Invoice\InvoiceItemReference;
use App\Services\Invoice\ProcessPaidInvoiceService;
use App\Services\Invoice\RecordPaymentService;
use App\Support\AfterCommit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class InvoiceObserver
{
    /**
     * Handle the Invoice "creating" event.
     */
    public function creating(Invoice $invoice): void
    {
        self::refuseUnknownStatus($invoice);
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

        if ($invoice->status === Invoice::STATUS_DRAFT || $invoice->finalize_later) {
            return;
        }

        AfterCommit::event(new InvoiceEvent\Finalized($invoice, $invoice->send_create_email), $invoice);
    }

    /**
     * Handle the Invoice "updating" event.
     */
    public function updating(Invoice $invoice): void
    {
        self::refuseUnknownStatus($invoice);
        // A number given is the invoice's for good (Δ2; adversarial ADV-10 of #238). The importer writes rows directly.
        if ($invoice->isDirty('number') && $invoice->getOriginal('number') !== null && $invoice->getOriginal('number') !== '') {
            throw new DisplayException(__('An invoice keeps the number it was given.'));
        }
        // A cancelled invoice is never marked paid directly, by anyone (docs/payments.md): it renews nothing
        // and its credit note already covers it. Staff reopen it (unpaid) and record the payment.
        if ($invoice->isDirty('status') && $invoice->getOriginal('status') === Invoice::STATUS_CANCELLED
            && $invoice->status === Invoice::STATUS_PAID) {
            throw new DisplayException(__('A cancelled invoice cannot be marked paid: reopen it (unpaid) and record the payment.'));
        }
        // Merged into another invoice (PR E): its lines are there now. Reopened, it would bill nothing.
        if ($invoice->isDirty('status') && $invoice->getOriginal('status') === Invoice::STATUS_CANCELLED
            && $invoice->merged_into_id !== null) {
            throw new DisplayException(__('This invoice was merged into invoice #:id: its lines are there.', ['id' => $invoice->merged_into_id]));
        }
        // Reopened after its period was billed again on another invoice (a cancellation request withdrawn, adversarial
        // D3A-10): the customer would be asked twice for it. Staff cancel the other one first.
        if ($invoice->isDirty('status') && $invoice->getOriginal('status') === Invoice::STATUS_CANCELLED
            && in_array($invoice->status, Invoice::OPEN_STATUSES, true) && ($other = self::billedElsewhere($invoice)) !== null) {
            throw new DisplayException(__('Its period is billed again by invoice #:id: cancel that one first.', ['id' => $other]));
        }
        // Refunded (PR 2c, as WHMCS): all its money went back, to the customer or to their credit. Final: it holds
        // nothing to pay or to apply, and a later payment on it becomes credit (RecordPaymentService).
        if ($invoice->isDirty('status') && $invoice->getOriginal('status') === Invoice::STATUS_REFUNDED) {
            throw new DisplayException(__('A refunded invoice stays refunded: bill the customer on a new invoice.'));
        }
        // Read locked: Invoice::save holds the payment locks for a change to cancelled or refunded.
        if ($invoice->isDirty('status') && $invoice->status === Invoice::STATUS_REFUNDED) {
            $invoice->withLockedBalance();
            $back = (int) $invoice->transactions->where('status', InvoiceTransactionStatus::Succeeded)
                ->sum(fn (InvoiceTransaction $transaction) => RecordPaymentService::toCents($transaction->refunded_amount)
                    + RecordPaymentService::toCents($transaction->credited_amount));
            if ($invoice->getOriginal('status') !== Invoice::STATUS_PAID || self::receivedCents($invoice) !== 0 || $back <= 0) {
                throw new DisplayException(__('An invoice becomes refunded when all its payments were refunded: use Refund on its transactions.'));
            }
        }
        // A cancelled invoice holds no money (audit sweep INV-16, PR 2c): a paid one, or an open one with payments on
        // it, is refunded first (back to the customer, or to their account credit), as the money check expects.
        if ($invoice->isDirty('status') && $invoice->status === Invoice::STATUS_CANCELLED) {
            if ($invoice->getOriginal('status') === Invoice::STATUS_PAID) {
                throw new DisplayException(__('A paid invoice is not cancelled: refund its payments (back, or to account credit).'));
            }
            if (self::receivedCents($invoice->withLockedBalance()) > 0) {
                throw new DisplayException(__('This invoice holds payments: refund them (back, or to account credit) before cancelling it.'));
            }
        }
        // Paid only when nothing is left to pay (docs/money-trail.md, MT-1c; audit B1): staff record the payment (Mark
        // Paid, Add Payment) and the invoice follows, through RecordPaymentService. Marking it paid by its status alone
        // would renew its services and give the credit it sells without any money. Read locked: Invoice::save holds the
        // payment locks for this change.
        // No longer paid: nothing left to apply (BIL-9); paid again, it is marked again.
        if ($invoice->isDirty('status') && $invoice->getOriginal('status') === Invoice::STATUS_PAID && $invoice->status !== Invoice::STATUS_PAID) {
            $invoice->apply_pending_since = null;
        }
        if ($invoice->isDirty('status') && $invoice->status === Invoice::STATUS_PAID
            && $invoice->getOriginal('status') !== Invoice::STATUS_PAID) {
            $remaining = RecordPaymentService::toCents($invoice->withLockedBalance()->remaining);
            if ($remaining > 0) {
                throw new DisplayException(__('An invoice becomes paid when its payment is recorded: use Mark Paid instead.'));
            }
            // Paid over its total: the part over is the customer's, given only by RecordPaymentService::settle (2b).
            if ($remaining < 0 && !RecordPaymentService::settling()) {
                throw new DisplayException(__('More was paid than it bills: close it with «Close as paid», which gives the part over as account credit.'));
            }
            // Its lines are applied after commit; until then (and after a failure there) the cron sees it (BIL-9).
            $invoice->apply_pending_since = now()->toImmutable();
            // The credit it sells is given on paid: only for money that arrived, whatever discount (a note, a negative
            // line) brought its balance to zero.
            if (self::creditToGiveCents($invoice) > self::receivedCents($invoice)) {
                throw new DisplayException(__('A credit purchase becomes paid only when its payment covers the credit it buys.'));
            }
        }
        // Its money belongs to its customer and currency (credit, journal, docs/money-trail.md §8): an invoice with a
        // payment (processing too) keeps both. Staff delete the payment first, then move the invoice. Read locked:
        // Invoice::save holds the payment locks for this change, so a webhook cannot land in between.
        // Its lines renew services of its customer (AUTH-8): moved to another customer, it would renew the first
        // customer's services with the second one's money. Staff make a new invoice instead.
        if ($invoice->isDirty('client_id') && $invoice->items()->whereIn('reference_type', InvoiceItemReference::TYPES)->get()
            ->contains(fn (InvoiceItem $item) => InvoiceItemReference::ownerOf($item->reference_type, (int) $item->reference_id) !== (int) $invoice->client_id)) {
            throw new DisplayException(__('This invoice renews services of its customer: it cannot move to another customer.'));
        }
        if ($invoice->isDirty(['client_id', 'currency_code']) && $invoice->hasPayments(lock: true)) {
            throw new DisplayException(__('An invoice with payments keeps its customer and currency: delete its payments first (a refunded payment keeps the invoice where it is).'));
        }
        event(new InvoiceEvent\Updating($invoice));
    }

    /**
     * Only the statuses MyCS knows (Invoice::STATUSES). One it does not know (the old «pending» from before the fork's rename, a typo) would
     * hide the invoice from every list, payment and cron step that looks for open ones (adversarial review of 2b).
     */
    private static function refuseUnknownStatus(Invoice $invoice): void
    {
        // Not set on create: the column default (draft) fills it.
        $status = $invoice->getAttributes()['status'] ?? null;
        if ($status !== null && !in_array($status, Invoice::STATUSES, true)) {
            throw new \LogicException("Unknown invoice status «{$status}»: one of " . implode(', ', Invoice::STATUSES) . '.');
        }
    }

    /** The credit its lines still have to give (applied lines gave theirs already). Lines read locked. */
    private static function creditToGiveCents(Invoice $invoice): int
    {
        return (int) $invoice->items->where('reference_type', Credit::class)->whereNull('applied_at')
            ->sum(fn (InvoiceItem $item) => max(0, $item->totalCents()));
    }

    /** Money that arrived on the invoice and stays on it: succeeded payments less refunds and what became credit. */
    private static function receivedCents(Invoice $invoice): int
    {
        return (int) $invoice->transactions->where('status', InvoiceTransactionStatus::Succeeded)
            ->sum(fn (InvoiceTransaction $transaction) => RecordPaymentService::toCents($transaction->amount)
                - RecordPaymentService::toCents($transaction->refunded_amount) - RecordPaymentService::toCents($transaction->credited_amount));
    }

    /**
     * Its renewal lines are given up on (Δ3a): marked, so the cron does not bill their periods again. A renewal line with
     * no period (an invoice the WHMCS importer brought, one staff made by hand; adversarial D3A-7) takes the period it
     * would have paid, as a payment gives it one (RenewServiceService), and is marked too.
     */
    private static function givePeriodsUp(Invoice $invoice, bool $onlyDue = false): void
    {
        $loose = InvoiceItem::query()->where('invoice_id', $invoice->id)->where('reference_type', Service::class)
            ->where(fn ($line) => $line->whereNull('period_start')->orWhereNull('period_end'))->orderBy('id')->get();
        foreach ($loose as $line) {
            $service = Service::query()->find($line->reference_id);
            if ($service === null || $service->plan === null || in_array($service->plan->type, ['one-time', 'free'], true) || $service->expires_at === null) {
                continue;
            }
            [, $billed] = $service->billedPeriodLines();
            $start = $service->nextPeriodStart($billed);
            $end = $service->periodEnd($start, $billed);
            // A period the cron has not reached yet stays billable (ADV-4, review A4-5): nothing to mark.
            if ($onlyDue && $start->toDateString() >= Invoice::billedFrom(Service::class)) {
                continue;
            }
            if ($end !== null) {
                // Not a money field: allowed on any invoice (InvoiceItemObserver).
                InvoiceItem::query()->whereKey($line->id)->update(['period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'forgone_at' => now()]);
            }
        }
        // A domain's too (D5b): its next period is billed again unless given up (DomainRenewalService::billedLine).
        self::renewalLines($invoice, domains: true)->whereNull('forgone_at')
            ->when($onlyDue, fn ($lines) => $lines->where(fn ($due) => $due
                ->where(fn ($q) => $q->where('reference_type', Service::class)->whereDate('period_start', '<', Invoice::billedFrom(Service::class)))
                ->orWhere(fn ($q) => $q->where('reference_type', Domain::class)->whereDate('period_start', '<', Invoice::billedFrom(Domain::class)))))
            ->update(['forgone_at' => now()]);
    }

    /** Another live invoice billing one of its periods (the same service, overlapping), if any: its id. */
    private static function billedElsewhere(Invoice $invoice): ?int
    {
        // A domain's too (review A4-2): a period kept billable and billed again by the cron is not billed twice.
        foreach (self::renewalLines($invoice, domains: true)->whereNull('kind')->get() as $line) {
            $other = InvoiceItem::query()->where('reference_type', $line->reference_type)->where('reference_id', $line->reference_id)->whereNull('kind')
                ->where('invoice_id', '!=', $invoice->id)
                ->where('period_start', '<', $line->period_end)->where('period_end', '>', $line->period_start)
                ->whereHas('invoice', fn ($q) => $q->whereNotIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED]))
                ->value('invoice_id');
            if ($other !== null) {
                return (int) $other;
            }
        }

        return null;
    }

    /**
     * Its lines that bill a period of a service (Δ3), and with $domains of a domain (D7a ADV-4).
     *
     * @return Builder<InvoiceItem>
     */
    private static function renewalLines(Invoice $invoice, bool $domains = false): Builder
    {
        return InvoiceItem::query()->where('invoice_id', $invoice->id)
            ->whereIn('reference_type', $domains ? [Service::class, Domain::class] : [Service::class])
            ->whereNotNull('period_start')->whereNotNull('period_end');
    }

    /**
     * Handle the Invoice "updated" event.
     */
    public function updated(Invoice $invoice): void
    {
        // Merged (PR E): it bills nothing any more, so no credit note (which would also spend a credit note number).
        // Never numbered (a proforma or a draft, Δ2): it was never an invoice, so nothing to credit (owner 2026-10-20).
        if ($invoice->status === Invoice::STATUS_CANCELLED && $invoice->getOriginal('status') !== Invoice::STATUS_CANCELLED
            && $invoice->merged_into_id === null && (string) $invoice->number !== '') {
            $invoice->createCancellationCreditNote($invoice->cancellation_reason);
        }

        // Δ3 (decided 2026-10-15): a renewal cancelled (by staff, the API) or refunded is a period not billed: its lines
        // are marked, and the cron does not bill that period again (the service falls to the suspension and termination
        // rules). Reopened, the period is billed again by this invoice. Cancelled as a consequence of its service ending
        // or changing cycle (CancelOpenInvoices), nothing is marked: a service that goes on is billed again.
        // Nobody chose (null): a cancelled renewal of a period the cron would not bill yet (beyond its days before) is kept
        // billable, the cron bills it at its time; one within them is given up, as Δ3a (owner, 2026-10-17, D7a ADV-4:
        // invoiced or paid early then taken back must not end in a suspension).
        if ($invoice->isDirty('status') && in_array($invoice->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED], true)
            && $invoice->keep_periods_billable !== true) {
            self::givePeriodsUp($invoice, onlyDue: $invoice->keep_periods_billable === null && $invoice->status === Invoice::STATUS_CANCELLED);
        }
        if ($invoice->isDirty('status') && $invoice->getOriginal('status') === Invoice::STATUS_CANCELLED
            && in_array($invoice->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_DRAFT], true)) {
            self::renewalLines($invoice, domains: true)->update(['forgone_at' => null]);
        }

        // Reopened: the cancellation's credit note no longer applies, the invoice is payable again
        // (docs/payments.md). Refund notes and other adjustments stay as they are.
        if ($invoice->getOriginal('status') === Invoice::STATUS_CANCELLED
            && in_array($invoice->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_DRAFT], true)) {
            $invoice->adjustmentNotes()->where('is_cancellation', true)
                ->where('status', AdjustmentNoteStatus::Active->value)->get()
                ->each(fn (AdjustmentNote $note) => $note->update(['status' => AdjustmentNoteStatus::Voided]));
            $invoice->unsetRelation('adjustmentNotes');
        }

        if ($invoice->getOriginal('status') === Invoice::STATUS_DRAFT && $invoice->status === Invoice::STATUS_UNPAID) {
            $this->createSnapshot($invoice);
            event(new InvoiceEvent\Created($invoice));

            AfterCommit::event(new InvoiceEvent\Finalized($invoice, $invoice->send_create_email), $invoice);

            // Finalized with nothing to pay (a free service, or its payments cover it exactly): closed as paid, as a
            // zero invoice is (2b). Never one paid over its total: that would move a payment to credit for whoever may
            // edit invoices (staff close it with «Close as paid»). After the commit; a failure leaves it open for staff.
            $id = $invoice->id;
            DB::afterCommit(function () use ($id) {
                try {
                    (new RecordPaymentService)->settle($id, withoutCredit: true);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        if ($invoice->isDirty('status') && $invoice->status == 'paid') {
            app(GrantBoughtCreditService::class)->handle($invoice);

            DB::afterCommit(function () use ($invoice) {
                // Clears apply_pending_since once applied. A failure leaves it for the cron (BIL-9), which applies it
                // again and fires Paid then: the payment is recorded, so whoever paid (a webhook, staff) is not told
                // it failed and does not pay again.
                try {
                    app(ProcessPaidInvoiceService::class)->handle($invoice);
                } catch (Throwable $e) {
                    report($e);

                    return;
                }
                // Its listeners (snapshot, mails, extensions) never undo the payment: one that fails is reported, and
                // the callbacks after this one still run (EVT-2).
                try {
                    event(new InvoiceEvent\Paid($invoice));
                } catch (Throwable $e) {
                    report($e);
                }
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
        // Where a merge went (PR E, code review CR-7): kept, with the invoice its lines are on.
        if ($invoice->merged_into_id !== null || Invoice::query()->where('merged_into_id', $invoice->id)->exists()) {
            throw new DisplayException(__('This invoice is part of a merge: it is kept, so the merge can be traced.'));
        }
        // It records a period given up on (Δ3a, adversarial D3A-6): deleted, the cron would bill that period again.
        if ($invoice->recordsForgonePeriod()) {
            throw new DisplayException(__('This cancelled invoice records a service period given up on: it is kept (reopen it to bill that period again).'));
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
