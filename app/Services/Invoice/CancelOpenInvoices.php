<?php

namespace App\Services\Invoice;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Money\CreditAutoApply;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cancel open invoices the app gives up on (the cron, a cancellation request), each through the model under the
 * payment locks (MT-2b): it gets its cancellation credit note (when it was issued, #241) and an audit row, and a payment landing at the same
 * moment is seen. Account credit paid on it goes back to the customer first (MT-1e: credit is also applied in part).
 *
 * With $onlyFor (a service, one upgrade, or a domain that is lost: D7, its renewal and stage fee lines), line by line (audit sweep SVC-4): an invoice that also bills other services
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
     * @param  Service|ServiceUpgrade|Domain|null  $onlyFor  cancel only its part of each invoice (a service: its renewals and upgrades; a domain: its renewals and stage fees)
     * @return array<string, Throwable> what was left open or failed, by invoice («invoice_cancelled #id»)
     *
     * @throws Throwable inside a caller's transaction: a failure (a deadlock rolled the caller's work back already)
     *                   is the caller's, never swallowed here
     */
    public static function handle(iterable $invoices, string $reason, Service|ServiceUpgrade|Domain|null $onlyFor = null): array
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

    /**
     * The open invoices an ended service (terminated, or cancelled at its customer's request) gives up on (Δ3, decision
     * 4 of 2026-10-15): all of them, as WHMCS does today at both companies. With «keep overdue invoices of ended
     * services» on, an unpaid one overdue more than the days set stays as it is, a debt: its reminders go on, and paid
     * later it renews nothing (an ended service is never revived, RenewServiceService). A draft was never sent: it goes.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return Collection<int, Invoice>
     */
    public static function onServiceEnd(Collection $invoices): Collection
    {
        if (!config('settings.cronjob_end_keeps_overdue', false)) {
            return $invoices;
        }
        $cutoff = CarbonImmutable::today()->subDays(max(0, (int) config('settings.cronjob_end_keeps_overdue_days', 0)));
        $kept = $invoices->filter(fn (Invoice $invoice) => $invoice->status === Invoice::STATUS_UNPAID
            && CarbonImmutable::parse($invoice->due_at ?? $invoice->created_at)->startOfDay()->lessThan($cutoff));
        // Marked (in the caller's transaction): still payable from the client area and by credit, not an anomaly for
        // the money check, and paid it tells nobody to refund it.
        if ($kept->isNotEmpty()) {
            Invoice::query()->whereKey($kept->pluck('id'))->whereNull('kept_as_debt_at')->update(['kept_as_debt_at' => now()]);
        }

        return $invoices->diffKeys($kept)->values();
    }

    /** Cancel the invoice, or the lines of $onlyFor on it. Returns why it stays open for staff, or null. */
    private static function cancel(int $id, string $reason, Service|ServiceUpgrade|Domain|null $onlyFor): ?string
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

        // Ekdosi issued its fiscal document for it (on credit): cancelled here, nobody would issue its credit note there
        // (R2 of #459). Staff decide: the document cancelled in Ekdosi, then this.
        if (EkdosiDocument::activeOn($invoice->id, lock: true)) {
            return 'Ekdosi issued its fiscal document: cancel the document there first, then this invoice by hand.';
        }
        $items = $invoice->items()->lockForUpdate()->get();
        // An imported WHMCS line keeps its relid; the importer types a hosting's lines (its setup fee, promotion, addon
        // and upgrade too: a charge of the service), not a domain's. One with no type that names a hosting on this
        // invoice goes with it (services keep their WHMCS ids). Any other (a domain) is not known: nothing is given up on by guess.
        $hostings = $items->where('reference_type', Service::class)->pluck('reference_id')->map(fn ($id) => (int) $id);
        if ($items->contains(fn (InvoiceItem $line) => blank($line->reference_type) && (int) $line->reference_id > 0
            && !$hostings->contains((int) $line->reference_id))) {
            return 'it has imported lines (a domain, an addon) whose item is not known: cancel it, or its lines, by hand.';
        }
        [$own, $others] = [$items, collect()];
        $what = match (true) {
            $onlyFor instanceof Service => "service #{$onlyFor->id}",
            $onlyFor instanceof Domain => "domain {$onlyFor->fqdn}",
            default => "upgrade #{$onlyFor?->id}",
        };
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
        // A domain renewed (or restored) at its registrar on this invoice before it was paid («Renew now», D-1; review F1 /
        // adversarial ADV-1 of #175), or whose charge has no answer yet: we paid the registry for it, it is owed.
        if ($onlyFor instanceof Domain && DomainRegistrarLog::query()->where('domain_id', $onlyFor->id)->where('invoice_id', $invoice->id)
            ->whereIn('action', ['renew', 'restore'])->whereIn('status', [DomainRegistrarLog::STATUS_OK, DomainRegistrarLog::STATUS_PENDING])->exists()) {
            return "{$what} was renewed at its registrar on it (or the charge has no answer yet): it is owed, staff decide.";
        }
        // A period already delivered (its payment was taken off after it renewed the service) is a debt, not an
        // open offer: cancelling it would give that period away without anyone deciding so.
        if ($own->contains(fn (InvoiceItem $line) => $line->applied_at !== null)) {
            return 'a period on it was already delivered and its payment taken off: staff decide (collect it, or cancel it by hand).';
        }

        if ($others->isEmpty()) {
            (new CreditAutoApply)->giveBack($invoice);
            $invoice->refresh();
            // A consequence of its service (it ends, changes cycle), not the invoice given up on: a service that goes on
            // is billed again (Δ3a).
            $invoice->keep_periods_billable = true;
            $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => $reason]);

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
    private static function split(Collection $items, Service|ServiceUpgrade|Domain $onlyFor): array
    {
        // A domain's own (D7): its renewals and the fees of its grace or redemption; a «charge» on it (an owner change)
        // is not given up with it: staff decide.
        // A line with no reference is not a domain's (adversarial ADV-2 of #175: a domain has no setup fee or promotion):
        // it stays with the rest.
        if ($onlyFor instanceof Domain) {
            $own = $items->filter(fn (InvoiceItem $line) => $line->reference_type === Domain::class && (int) $line->reference_id === (int) $onlyFor->id
                && $line->kind !== InvoiceItem::KIND_CHARGE);

            return [$own->values(), $items->diffKeys($own)->values(), collect()];
        }
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
