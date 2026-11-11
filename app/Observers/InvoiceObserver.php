<?php

namespace App\Observers;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Events\Invoice as InvoiceEvent;
use App\Exceptions\CreditPurchaseNotCovered;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Models\Credit;
use App\Models\Domain;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Services\Domains\DomainNameHold;
use App\Services\Ekdosi\EkdosiRequest;
use App\Services\Invoice\CreateInvoiceSnapshotService;
use App\Services\Invoice\GrantBoughtCreditService;
use App\Services\Invoice\InvoiceItemReference;
use App\Services\Invoice\InvoiceReaddress;
use App\Services\Invoice\ProcessPaidInvoiceService;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\UndoRefund;
use App\Services\Payment\SavedMethodCharge;
use App\Support\AfterCommit;
use Carbon\CarbonImmutable;
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
        // Its preferred payment method, as WHMCS's default gateway; one given (a checkout's gateway, staff) stays.
        $invoice->preferred_gateway_id ??= Invoice::preferredGatewayFor($invoice->client_id);
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

        AfterCommit::event(new InvoiceEvent\Finalized($invoice, $invoice->send_create_email, $invoice->create_email_by_staff), $invoice);
    }

    /**
     * Handle the Invoice "updating" event.
     */
    public function updating(Invoice $invoice): void
    {
        self::refuseUnknownStatus($invoice);
        // Ekdosi issued its document in this account's ΑΦΜ and currency (final review of #459): it stays with them.
        if ($invoice->isDirty(['client_id', 'currency_code']) && EkdosiDocument::activeOn($invoice->id, lock: true)) {
            throw new DisplayException(__('This invoice has a fiscal document from Ekdosi: it stays with its customer and currency. Cancel the document there first.'));
        }
        // A number given is the invoice's for good (Δ2; adversarial ADV-10 of #238). The importer writes rows directly.
        if ($invoice->isDirty('number') && $invoice->getOriginal('number') !== null && $invoice->getOriginal('number') !== '') {
            throw new DisplayException(__('An invoice keeps the number it was given.'));
        }
        // A cancelled invoice is never marked paid directly, by anyone (docs/payments.md): it renews nothing
        // and it is settled (its credit note, or never issued: a proforma, a draft). Staff reopen it (unpaid) and record the
        // payment.
        if ($invoice->isDirty('status') && $invoice->getOriginal('status') === Invoice::STATUS_CANCELLED
            && $invoice->status === Invoice::STATUS_PAID) {
            throw new DisplayException(__('A cancelled invoice cannot be marked paid: reopen it (unpaid) and record the payment.'));
        }
        // Merged into another invoice (PR E): its lines are there now. Reopened (owner 2026-11-10: a merge is undone as in
        // WHMCS), it is an empty invoice like any other and no longer «merged into» anything: the link goes with it.
        if ($invoice->isDirty('status') && $invoice->getOriginal('status') === Invoice::STATUS_CANCELLED
            && $invoice->merged_into_id !== null) {
            $invoice->merged_into_id = null;
        }
        // Reopened after its period was billed again on another invoice (a cancellation request withdrawn, adversarial
        // D3A-10): the customer would be asked twice for it. Staff cancel the other one first.
        if ($invoice->isDirty('status') && $invoice->getOriginal('status') === Invoice::STATUS_CANCELLED
            && in_array($invoice->status, Invoice::OPEN_STATUSES, true) && ($other = self::billedElsewhere($invoice)) !== null) {
            throw new DisplayException(__('Its period is billed again by invoice #:id: cancel that one first.', ['id' => $other]));
        }
        // Open again (reopened, a payment deleted) only while its lines' items are still its customer's: one moved to
        // another customer since (MoveToClient) would be renewed by the first customer's invoice (AUTH-8; adversarial
        // R2-2 of #357). Staff bill the new customer on a new invoice.
        if ($invoice->isDirty('status') && !in_array($invoice->getOriginal('status'), Invoice::OPEN_STATUSES, true)
            && in_array($invoice->status, Invoice::OPEN_STATUSES, true)
            // Not a chargeback's paid to unpaid: the bank took the money, so it is always recorded and staff are told
            // (S6-MONEY-LEDGER-2; Invoice::owedAgainAfterChargeback).
            && !$invoice->becomingOwedAfterChargeback()
            && self::billsItemOfAnother($invoice)) {
            throw new DisplayException(__('This invoice bills an item that moved to another customer: it is not opened again. Bill that customer on a new invoice.'));
        }
        // Refunded (PR 2c, as WHMCS): all its money went back, to the customer or to their credit. Final: it holds
        // nothing to pay or to apply, and a later payment on it becomes credit (RecordPaymentService).
        // But for staff undoing one of its refunds (UndoRefund, owner 2026-11-10): it holds money again.
        if ($invoice->isDirty('status') && $invoice->getOriginal('status') === Invoice::STATUS_REFUNDED && !UndoRefund::undoing()) {
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
            // A saved card charged and its outcome not here yet (sweep2 F-STAFF-ADMIN-MONEY-2), as delete, merge and a move
            // refuse it: cancelled, the money that lands becomes credit while the period is billed again and charged again.
            // A cancellation as a consequence of its service goes on (CancelOpenInvoices: the money becomes credit). Only a
            // saved-card charge whose outcome is not here: a gateway payment recorded as processing (a customer's SEPA) does
            // not keep the invoice open, and becomes credit when it settles (PaymentGuardsAdversarialTest).
            if (!$invoice->cancelled_as_consequence && SavedMethodCharge::holding($invoice)->isNotEmpty()) {
                throw new DisplayException(__('A card payment of this invoice is on its way or not settled: wait for it before cancelling the invoice.'));
            }
        }
        // Paid only when nothing is left to pay (docs/money-trail.md, MT-1c; audit B1): staff record the payment (Mark
        // Paid, Add Payment) and the invoice follows, through RecordPaymentService. No code path marks it paid by its
        // status alone; staff may, on an unpaid invoice, saying so ($paid_without_payment, owner 2026-11-10: its services
        // renew, as they meant, and no payment row is made up). Never the credit it sells: a credit purchase needs its
        // money, below. Read locked: Invoice::save holds the payment locks for this change.
        // No longer paid: nothing left to apply (BIL-9); paid again, it is marked again.
        if ($invoice->isDirty('status') && $invoice->getOriginal('status') === Invoice::STATUS_PAID && $invoice->status !== Invoice::STATUS_PAID) {
            $invoice->apply_pending_since = null;
            $invoice->paid_by_staff_at = null; // «paid by hand» is a fact of a paid invoice only
        }
        if ($invoice->isDirty('status') && $invoice->status === Invoice::STATUS_PAID
            && $invoice->getOriginal('status') !== Invoice::STATUS_PAID) {
            $remaining = RecordPaymentService::toCents($invoice->withLockedBalance()->remaining);
            if ($remaining > 0 && !($invoice->paid_without_payment && $invoice->getOriginal('status') === Invoice::STATUS_UNPAID)) {
                throw new DisplayException(__('An invoice becomes paid when its payment is recorded: use Mark Paid instead.'));
            }
            // The fact, kept on it: whatever must not take «paid» for money reads it (REV-780-3, -6).
            $invoice->paid_by_staff_at = $remaining > 0 ? CarbonImmutable::now() : null;
            // Paid over its total: the part over is the customer's, given only by RecordPaymentService::settle (2b).
            if ($remaining < 0 && !RecordPaymentService::settling()) {
                throw new DisplayException(__('More was paid than it bills: close it with «Close as paid», which gives the part over as account credit.'));
            }
            // When (WHMCS `datepaid`, ROADMAP 6): the date of its last payment, as staff gave it (a transfer recorded
            // later; R7 of #275), else now (closed by a note). Paid again after a reopening: again.
            $last = $invoice->transactions()->where('status', InvoiceTransactionStatus::Succeeded)->max('created_at');
            $invoice->paid_at = $last !== null ? CarbonImmutable::parse($last) : CarbonImmutable::now();
            // Its lines are applied after commit; until then (and after a failure there) the cron sees it (BIL-9).
            $invoice->apply_pending_since = now()->toImmutable();
            // The credit it sells is given on paid: only for money that arrived, whatever discount (a note, a negative
            // line) brought its balance to zero.
            if (self::creditToGiveCents($invoice) > self::receivedCents($invoice)) {
                throw new CreditPurchaseNotCovered(__('A credit purchase becomes paid only when its payment covers the credit it buys.'));
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
        // A closed one (cancelled, paid, refunded) is an issued document of its customer: reopened first, in the same save
        // too (ADV-554-R2-1/2/3: moved closed, then reopened, it billed the first customer's tax).
        // Nor moved and marked paid in one save: «paid» was checked against the first customer's tax, and the lines of a paid
        // invoice are retaxed now that they change (owner 2026-11-10; it was refused by the lines before).
        if ($invoice->isDirty('client_id') && (($invoice->isDirty('status') && $invoice->status === Invoice::STATUS_PAID)
            || (!in_array($invoice->getOriginal('status'), Invoice::OPEN_STATUSES, true) && !in_array($invoice->status, Invoice::OPEN_STATUSES, true)))) {
            throw new DisplayException(__('A closed invoice keeps its customer: set it back to unpaid first (a refunded one never moves).'));
        }
        // A credit or debit note staff wrote is a fixed gross amount under the first customer's tax, numbered to them: void
        // it first, as MoveToClient asks (ADV-554-R3-1: retaxed under it, the invoice went below zero). A cancellation's own
        // note is voided by the reopen in the same save.
        if ($invoice->isDirty('client_id') && $invoice->adjustmentNotes()->where('status', AdjustmentNoteStatus::Active->value)
            ->where(fn ($note) => $note->whereNull('is_cancellation')->orWhere('is_cancellation', false))->exists()) {
            throw new DisplayException(__('This invoice has a credit or debit note: void it before moving the invoice.'));
        }
        // A saved card charged and its webhook not here yet: that money is the first customer's (ADV-554-2), as MoveToClient
        // refuses it.
        if ($invoice->isDirty(['client_id', 'currency_code']) && SavedMethodCharge::onItsWay($invoice)) {
            throw new DisplayException(__('A card payment of this invoice is on its way: wait for it before moving the invoice.'));
        }
        // Its preferred method the new account's own, never the first one's card's gateway; one billed apart for an item
        // paid another way keeps that way (as MoveToClient, R3-2 of #539, ADV-554-3).
        // Not one staff chose in the same save (ADV-554-R2-4).
        if ($invoice->isDirty('client_id') && $invoice->client_id !== null && !$invoice->isDirty('preferred_gateway_id')) {
            $invoice->preferred_gateway_id = $invoice->preferred_gateway_id !== null
                && $invoice->items()->where('payment_gateway_id', $invoice->preferred_gateway_id)->exists()
                ? $invoice->preferred_gateway_id : Invoice::preferredGatewayFor((int) $invoice->client_id);
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

    /** A line of it bills a service or domain that is another customer's now (MoveToClient), or deleted. */
    public static function billsItemOfAnother(Invoice $invoice): bool
    {
        return $invoice->items()->whereIn('reference_type', InvoiceItemReference::TYPES)->get()
            ->contains(fn (InvoiceItem $item) => InvoiceItemReference::ownerOf($item->reference_type, (int) $item->reference_id) !== (int) $invoice->client_id);
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
            ->where(fn ($line) => $line->whereNull('period_start')->orWhereNull('period_end'))
            // A charge of the service (an imported setup fee, promotion, addon or upgrade) bills no period.
            ->where(fn ($line) => $line->whereNull('kind')->orWhere('kind', '!=', InvoiceItem::KIND_CHARGE))->orderBy('id')->get();
        foreach ($loose as $line) {
            $service = Service::query()->find($line->reference_id);
            if ($service === null || $service->plan === null || in_array($service->plan->type, ['one-time', 'free'], true) || $service->expires_at === null) {
                continue;
            }
            [$live, $billed] = $service->billedPeriodLines();
            // Its service has a live line with a period (the importer wrote its last paid line and its open renewal,
            // WhmcsLinePeriods; or it renewed here since): this line with none is not the period to come, which that
            // chain bills. It is the past (an older invoice paid in WHMCS), or a period nothing here can name: nothing is
            // given up for it, and the cron bills what is due (owner 2026-11-11; before, a refund of an older WHMCS
            // invoice gave up the NEXT period). A service with no period line at all goes on as before.
            if ($live->isNotEmpty()) {
                continue;
            }
            $start = $service->nextPeriodStart($billed);
            $end = $service->periodEnd($start, $billed);
            // A period the cron has not reached yet stays billable (ADV-4, review A4-5): nothing to mark.
            if ($onlyDue && $start->toDateString() >= Invoice::billedFrom(Service::class, $start, $end)) {
                continue;
            }
            if ($end !== null) {
                // Not a money field: allowed on any invoice (InvoiceItemObserver).
                InvoiceItem::query()->whereKey($line->id)->update(['period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'forgone_at' => now()]);
            }
        }
        // A domain's too (D5b): its next period is billed again unless given up (DomainRenewalService::billedLine).
        // Each line by its own period (Invoice::billedFrom: a month's, or any other's; WG-14, R660b-1).
        $due = self::renewalLines($invoice, domains: true)->whereNull('forgone_at')->get(['id', 'reference_type', 'period_start', 'period_end'])
            ->filter(fn (InvoiceItem $line) => !$onlyDue
                || $line->period_start->toDateString() < Invoice::billedFrom((string) $line->reference_type, $line->period_start, $line->period_end))
            ->pluck('id')->all();
        if ($due !== []) {
            InvoiceItem::query()->whereKey($due)->update(['forgone_at' => now()]);
        }
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

    /** A draft cancelled, or a proforma cancelled before it was paid (it never took a number): never an invoice. */
    private static function neverIssued(Invoice $invoice): bool
    {
        if ($invoice->getOriginal('status') === Invoice::STATUS_DRAFT) {
            return true;
        }
        $number = Invoice::query()->whereKey($invoice->getKey())->value('number');

        return config('settings.invoice_proforma', false) && !config('settings.invoice_number_is_id', false)
            && (string) $number === '' && (string) $invoice->number === '';
    }

    /**
     * Handle the Invoice "updated" event.
     */
    public function updated(Invoice $invoice): void
    {
        // «Send to Ekdosi» (E5) asked for this invoice as it stood: cancelled, refunded, made a draft, moved to another
        // account or currency, the request is gone, and one reopened is not sent again unasked (ADV-2/ADV-4, R2-4 of #478).
        // After the row is written, under its lock: a request landing meanwhile sees the new status (R2-5).
        // Any change of status but its payment (unpaid → paid): a paid one set back to unpaid would be issued on credit, which
        // staff never confirmed (R3-3).
        if ($invoice->wasChanged(['client_id', 'currency_code'])
            || ($invoice->wasChanged('status') && !($invoice->getOriginal('status') === Invoice::STATUS_UNPAID && $invoice->status === Invoice::STATUS_PAID))) {
            EkdosiRequest::forget((int) $invoice->id, $invoice->wasChanged('status') ? 'status_' . $invoice->status : 'moved');
        }
        // Another customer (staff's edit, the API, MoveToClient): taxed and addressed as the new one's (sweep 9γ, owner
        // 2026-10-30), after its Ekdosi request is gone as «moved» (code review F4 of #554). Only an open one moves (or one
        // reopened in the same save), refused in updating() with one with payments, a card charge on its way, or lines
        // renewing the first one's services. Its lines read again afterwards: a cancellation in the same save notes the
        // new total (F2).
        if ($invoice->wasChanged('client_id') && $invoice->client_id !== null) {
            $invoice->unsetRelation('client');
            InvoiceReaddress::retax($invoice);
            InvoiceReaddress::readdress($invoice);
            $invoice->unsetRelation('items');
        }
        // Merged (PR E): it bills nothing any more, so no credit note (which would also spend a credit note number).
        // Never issued (owner 2026-10-20, Δ2): a draft, or a proforma still without its number; nothing to credit. An
        // issued one keeps its note, numbered or not (number = id with the column empty, an import; its number read from
        // the database, not from a copy loaded before it was given: adversarial ADV-1/2 of #241).
        if ($invoice->status === Invoice::STATUS_CANCELLED && $invoice->getOriginal('status') !== Invoice::STATUS_CANCELLED
            && $invoice->merged_into_id === null && !self::neverIssued($invoice)) {
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
        // Refunded no more (staff undid its refund, UndoRefund): the periods its refund gave up are its own again.
        if ($invoice->isDirty('status') && $invoice->getOriginal('status') === Invoice::STATUS_REFUNDED) {
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

            AfterCommit::event(new InvoiceEvent\Finalized($invoice, $invoice->send_create_email, $invoice->create_email_by_staff), $invoice);

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

        // No longer paid (refunded, charged back, set back to unpaid): a domain order not sent, or an expired domain's
        // renewal, held its name by this payment (D-25): it holds it no more (ADV-DNH-4), after this commit.
        if ($invoice->isDirty('status') && $invoice->getOriginal('status') === Invoice::STATUS_PAID && $invoice->status !== Invoice::STATUS_PAID) {
            self::markAppliedBeforeMyCs($invoice);
            $domainIds = $invoice->items()->where('reference_type', Domain::class)->pluck('reference_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            if ($domainIds !== []) {
                DB::afterCommit(function () use ($domainIds) {
                    foreach ($domainIds as $domainId) {
                        try {
                            DomainNameHold::release($domainId); // busy: the cron's domains_superseded step
                        } catch (Throwable $e) {
                            report($e);
                        }
                    }
                });
            }
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
     * Reopened (set back to unpaid, its payment deleted, charged back): a renewal line it paid before MyCS kept applied_at
     * (a WHMCS invoice imported paid: no period, never applied here, ImportFromWhmcs::lineReference) is marked applied, as
     * of when the invoice was paid. Paid again, it is then what a native line is (RenewServiceService::paidAgain): no
     * second period for the same money (sweep4 E-SERVICE-1), and a service suspended for non-payment meanwhile is treated
     * as on any payment. Not at the import: an imported line stays without applied_at (ADV-582-4, MoneyCheck).
     *
     * Only a line whose period was given already: never one MyCS still has to apply (apply_pending_since, BIL-9), one of
     * a service that has ended (its payment bought nothing: staff are told when it is paid again), or one whose service
     * does not run past the invoice's due date (nothing shows it was renewed). Left alone, a line renews on its next
     * payment as before: when in doubt the customer's payment buys its period.
     */
    private static function markAppliedBeforeMyCs(Invoice $invoice): void
    {
        $due = $invoice->due_at;
        if ($invoice->getOriginal('apply_pending_since') !== null || $due === null) {
            return;
        }
        $at = $invoice->getOriginal('paid_at') ?? CarbonImmutable::now();
        InvoiceItem::query()->where('invoice_id', $invoice->id)->where('reference_type', Service::class)->whereNull('kind')
            ->whereNull('applied_at')->whereNull('period_start')->whereNull('period_end')->get(['id', 'reference_id'])
            ->each(function (InvoiceItem $line) use ($due, $at) {
                $service = Service::query()->find((int) $line->reference_id);
                if ($service === null || $service->isEnded() || $service->expires_at === null
                    || !CarbonImmutable::parse($service->expires_at)->startOfDay()->greaterThan(CarbonImmutable::parse($due)->startOfDay())) {
                    return;
                }
                InvoiceItem::query()->whereKey($line->id)->update(['applied_at' => $at]);
            });
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
        // A charge of a saved method not settled (taken and its webhook not here yet, or of unknown outcome): deleted, the
        // payment would have no invoice to land on and the charge's record would go with it (predeploy S3).
        if (SavedMethodCharge::onItsWay($invoice)) {
            throw new DisplayException(__('A payment for this invoice is on its way or not settled: it cannot be deleted until it is.'));
        }
        // Marked paid by staff with no payment (REV-780): it gave its periods, and it took its number. Deleted, those
        // periods would read as paid before MyCS and their days could come back as credit. Kept while it is so.
        if ($invoice->paid_by_staff_at !== null) {
            throw new DisplayException(__('This invoice was marked paid by staff with no payment and gave its periods: it is kept. Set it back to unpaid first if it was a mistake.'));
        }
        // Ekdosi issued a fiscal document from it: the invoice is that document's record (E2; the key refuses it anyway).
        if (EkdosiDocument::query()->where('invoice_id', $invoice->id)->lockForUpdate()->exists()) {
            throw new DisplayException(__('This invoice has a fiscal document from Ekdosi: it is kept. Cancel or refund it instead.'));
        }
        // It records a period given up on (Δ3a, adversarial D3A-6): deleted, the cron would bill that period again.
        if ($invoice->recordsForgonePeriod()) {
            throw new DisplayException(__('This cancelled invoice records a service period given up on: it is kept (reopen it to bill that period again).'));
        }
        // Either side of a merge is deleted like any other invoice (owner 2026-11-10; they were kept to trace the merge,
        // code review CR-7): the invoices merged into this one no longer point at it (MariaDB's key does it; SQLite has none).
        Invoice::query()->where('merged_into_id', $invoice->id)->update(['merged_into_id' => null]);
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
