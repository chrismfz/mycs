<?php

namespace App\Services\Invoice;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Money\CreditAutoApply;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cancel open invoices the app gives up on (the cron, a cancellation request), each through the model under the
 * payment locks (MT-2b): it gets its cancellation credit note and an audit row, and a payment landing at the same
 * moment is seen. Account credit paid on it goes back to the customer first (MT-1e: credit is also applied in part).
 *
 * With $onlyFor (a service, or one upgrade), line by line (audit sweep SVC-4): an invoice that also bills other services
 * or upgrades loses only its lines and stays open for the rest. Their removal is in the audit (InvoiceItem, deleted).
 * Credit on it keeps paying the rest; only what is more than the rest goes back.
 *
 * A draft is given up on like an open one (finalised later, it would bill what was cancelled).
 *
 * Left open for staff, as a notice the cron mails: an invoice that holds money a gateway or a bank transfer paid (it
 * would sit on a cancelled invoice, neither credited nor refunded); a period on it already delivered (its payment was
 * taken off: a debt); imported WHMCS lines whose item is not known; one that also bills something else with lines
 * that belong to no one (a setup fee or a promotion), or whose rest would come to nothing with the notes staff wrote
 * on it.
 */
final class CancelOpenInvoices
{
    /**
     * @param  iterable<Invoice>  $invoices
     * @param  string  $reason  the cancellation note's description (lang/*\/invoices.php, cancellation_reasons)
     * @param  Service|ServiceUpgrade|null  $onlyFor  cancel only its part of each invoice (a service: its renewals and upgrades)
     * @return array<string, Throwable> what was left open or failed, by invoice («invoice_cancelled #id»)
     *
     * @throws Throwable inside a caller's transaction: a failure (a deadlock rolled the caller's work back already)
     *                   is the caller's, never swallowed here
     */
    public static function handle(iterable $invoices, string $reason, Service|ServiceUpgrade|null $onlyFor = null): array
    {
        $nested = DB::transactionLevel() > 0;
        $failures = [];
        foreach ($invoices as $invoice) {
            try {
                $kept = DB::transaction(fn () => self::cancel((int) $invoice->id, $reason, $onlyFor));
                if ($kept !== null) {
                    $failures["invoice_cancelled #{$invoice->id}"] = new DisplayException("Invoice #{$invoice->id} left open: {$kept}");
                }
            } catch (Throwable $e) {
                if ($nested) {
                    throw $e;
                }
                report($e);
                $failures["invoice_cancelled #{$invoice->id}"] = $e;
            }
        }

        return $failures;
    }

    /** Cancel the invoice, or the lines of $onlyFor on it. Returns why it stays open for staff, or null. */
    private static function cancel(int $id, string $reason, Service|ServiceUpgrade|null $onlyFor): ?string
    {
        // Customer, then invoice: the payment paths' order. A payment that got here first is seen below.
        $invoice = (new RecordPaymentService)->lockInvoice($id);
        if (!in_array($invoice->status, Invoice::OPEN_STATUSES, true)) {
            return null; // paid or cancelled in the meantime: nothing to give up on
        }
        // A payment still processing does not keep it open: when it settles on a cancelled invoice it becomes credit
        // (RecordPaymentService), and on a smaller one the part over becomes credit.
        // What stays on it: a payment refunded, or moved to credit, holds nothing any more (code review of 2c).
        $transactions = $invoice->transactions()->lockForUpdate()->get(['id', 'status', 'is_credit_transaction', 'amount', 'refunded_amount', 'credited_amount']);
        if ($transactions->contains(fn ($transaction) => $transaction->status === InvoiceTransactionStatus::Succeeded && !$transaction->is_credit_transaction
            && RecordPaymentService::toCents($transaction->amount) - RecordPaymentService::toCents($transaction->refunded_amount)
                - RecordPaymentService::toCents($transaction->credited_amount) > 0)) {
            return 'it holds money a gateway or a transfer paid (refund it or move it to credit, then cancel it).';
        }

        $items = $invoice->items()->lockForUpdate()->get();
        // An imported WHMCS line keeps its relid but not its type (the importer maps only Hosting). A setup fee or a
        // promotion of a hosting has that hosting's id, and services keep their WHMCS ids: such a line goes with the
        // hosting it names on this invoice. Any other (a domain, an addon) is not known: nothing is given up on by guess.
        $hostings = $items->where('reference_type', Service::class)->pluck('reference_id')->map(fn ($id) => (int) $id);
        if ($items->contains(fn (InvoiceItem $line) => blank($line->reference_type) && (int) $line->reference_id > 0
            && !$hostings->contains((int) $line->reference_id))) {
            return 'it has imported lines (a domain, an addon) whose item is not known: cancel it, or its lines, by hand.';
        }
        [$own, $others] = [$items, collect()];
        $what = $onlyFor instanceof Service ? "service #{$onlyFor->id}" : "upgrade #{$onlyFor?->id}";
        if ($onlyFor !== null) {
            [$own, $others, $loose] = self::split($items, $onlyFor);
            if ($own->isEmpty()) {
                return null; // nothing of it on this invoice (any more): the rest is not this cancellation's
            }
            if ($others->isNotEmpty() && $loose->isNotEmpty()) {
                return "it also bills something other than {$what}, with lines that belong to neither (remove its lines by hand).";
            }
            if ($others->isEmpty()) {
                $own = $items; // nothing else on it: the whole invoice goes
            }
        }
        // A period already delivered (its payment was taken off after it renewed the service) is a debt, not an
        // open offer: cancelling it would give that period away without anyone deciding so.
        if ($own->contains(fn (InvoiceItem $line) => $line->applied_at !== null)) {
            return 'a period on it was already delivered and its payment taken off: staff decide (collect it, or cancel it by hand).';
        }

        if ($others->isEmpty()) {
            (new CreditAutoApply)->giveBack($invoice);
            $invoice->refresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => $reason]);

            return null;
        }

        // What stays must still be something to pay: its lines with the notes staff wrote on it (a goodwill credit).
        $notes = (int) $invoice->adjustmentNotes()->where('status', AdjustmentNoteStatus::Active->value)->lockForUpdate()->get()
            ->sum(fn (AdjustmentNote $note) => RecordPaymentService::toCents($note->amount));
        if (self::cents($others) + $notes <= 0) {
            return "what it bills besides {$what} comes to nothing (cancel it by hand).";
        }
        $credited = (int) $invoice->transactions()->where('is_credit_transaction', true)
            ->where('status', InvoiceTransactionStatus::Succeeded->value)->get()
            ->sum(fn ($payment) => RecordPaymentService::toCents($payment->amount) - RecordPaymentService::toCents($payment->refunded_amount)
                - RecordPaymentService::toCents($payment->credited_amount));
        $own->each(fn (InvoiceItem $line) => $line->delete());

        // Credit the customer put on it pays the rest, as far as it went: only what is now more than the rest goes
        // back (all of it back, then the rest paid again with no more than it was, so the journal shows both).
        if ($credited > 0 && RecordPaymentService::toCents($invoice->refresh()->withLockedBalance()->remaining) <= 0) {
            (new CreditAutoApply)->giveBack($invoice);
            (new RecordPaymentService)->payWithCredit($invoice->id, upToCents: $credited);
        }

        return null;
    }

    /**
     * The invoice's lines: those of $onlyFor (a service's renewals and upgrades, or one upgrade's), those of other
     * services or upgrades (anything with a reference), and lines with no reference (a setup fee, a promotion, an
     * imported WHMCS Promo/Setup line).
     *
     * @param  Collection<int, InvoiceItem>  $items
     * @return array{0: Collection<int, InvoiceItem>, 1: Collection<int, InvoiceItem>, 2: Collection<int, InvoiceItem>}
     */
    private static function split(Collection $items, Service|ServiceUpgrade $onlyFor): array
    {
        $upgrades = $onlyFor instanceof Service
            ? ServiceUpgrade::query()->where('service_id', $onlyFor->id)->pluck('id')->map(fn ($id) => (int) $id)
            : collect([(int) $onlyFor->id]);
        // A service's own: its renewals, its upgrades, and an imported setup fee or promotion naming it (a relid).
        $own = $items->filter(fn (InvoiceItem $line) => ($onlyFor instanceof Service && (int) $line->reference_id === (int) $onlyFor->id
            && ($line->reference_type === Service::class || blank($line->reference_type)))
            || ($line->reference_type === ServiceUpgrade::class && $upgrades->contains((int) $line->reference_id)));
        $loose = $items->filter(fn (InvoiceItem $line) => blank($line->reference_type) && (int) $line->reference_id <= 0);

        return [$own->values(), $items->diffKeys($own)->diffKeys($loose)->values(), $loose->values()];
    }

    /** @param  Collection<int, InvoiceItem>  $lines */
    private static function cents(Collection $lines): int
    {
        return (int) $lines->sum(fn (InvoiceItem $line) => $line->totalCents());
    }
}
