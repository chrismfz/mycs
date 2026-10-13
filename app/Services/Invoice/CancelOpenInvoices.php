<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
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
 * With $onlyFor, line by line (audit sweep SVC-4): an invoice that also bills other services or upgrades loses only
 * this service's lines and stays open for the rest. Their removal is in the audit (InvoiceItem, deleted).
 *
 * Left open for staff, as a notice the cron mails: an invoice that holds money a gateway or a bank transfer paid (it
 * would sit on a cancelled invoice, neither credited nor refunded), and one that also bills something else with lines
 * that belong to no one (a setup fee or a promotion: whose it is is not known).
 */
final class CancelOpenInvoices
{
    /**
     * @param  iterable<Invoice>  $invoices
     * @param  string  $reason  the cancellation note's description (lang/*\/invoices.php, cancellation_reasons)
     * @param  Service|null  $onlyFor  cancel only this service's part of each invoice
     * @return array<string, Throwable> what was left open or failed, by invoice («invoice_cancelled #id»)
     *
     * @throws Throwable inside a caller's transaction: a failure (a deadlock rolled the caller's work back already)
     *                   is the caller's, never swallowed here
     */
    public static function handle(iterable $invoices, string $reason, ?Service $onlyFor = null): array
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

    /** Cancel the invoice, or this service's lines on it. Returns why it stays open for staff, or null. */
    private static function cancel(int $id, string $reason, ?Service $onlyFor): ?string
    {
        // Customer, then invoice: the payment paths' order. A payment that got here first is seen below.
        $invoice = (new RecordPaymentService)->lockInvoice($id);
        if ($invoice->status !== Invoice::STATUS_PENDING) {
            return null; // paid or cancelled in the meantime: nothing to give up on
        }
        // A payment still processing does not keep it open: when it settles on a cancelled invoice it becomes credit
        // (RecordPaymentService), and on a smaller one the part over becomes credit.
        $transactions = $invoice->transactions()->lockForUpdate()->get(['id', 'status', 'is_credit_transaction']);
        if ($transactions->contains(fn ($transaction) => $transaction->status === InvoiceTransactionStatus::Succeeded && !$transaction->is_credit_transaction)) {
            return 'it holds money a gateway or a transfer paid (refund it or move it to credit, then cancel it).';
        }

        $own = collect();
        if ($onlyFor !== null) {
            [$own, $others, $loose] = self::split($invoice->items()->lockForUpdate()->get(), $onlyFor);
            if ($own->isEmpty()) {
                return null; // nothing of this service on it (any more): the rest is not this cancellation's
            }
            if ($others->isNotEmpty() && $loose->isNotEmpty()) {
                return "it also bills something other than service #{$onlyFor->id}, with lines that belong to neither (remove this service's lines by hand).";
            }
            if ($others->isNotEmpty() && self::cents($others) <= 0) {
                return "what it bills besides service #{$onlyFor->id} comes to nothing (cancel it by hand).";
            }
            if ($others->isEmpty()) {
                $own = collect(); // nothing else on it: the whole invoice goes
            }
        }

        (new CreditAutoApply)->giveBack($invoice);
        if ($own->isNotEmpty()) {
            $own->each(fn (InvoiceItem $line) => $line->delete());

            return null;
        }
        $invoice->refresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => $reason]);

        return null;
    }

    /**
     * The invoice's lines: this service's (its renewals and upgrades), other services' or upgrades' (anything with a
     * reference), and lines with no reference (a setup fee, a promotion, an imported WHMCS Promo/Setup line).
     *
     * @param  Collection<int, InvoiceItem>  $items
     * @return array{0: Collection<int, InvoiceItem>, 1: Collection<int, InvoiceItem>, 2: Collection<int, InvoiceItem>}
     */
    private static function split(Collection $items, Service $service): array
    {
        $upgrades = ServiceUpgrade::query()->where('service_id', $service->id)->pluck('id')->map(fn ($id) => (int) $id);
        $own = $items->filter(fn (InvoiceItem $line) => ($line->reference_type === Service::class && (int) $line->reference_id === (int) $service->id)
            || ($line->reference_type === ServiceUpgrade::class && $upgrades->contains((int) $line->reference_id)));
        $loose = $items->filter(fn (InvoiceItem $line) => blank($line->reference_type));

        return [$own->values(), $items->diffKeys($own)->diffKeys($loose)->values(), $loose->values()];
    }

    /** @param  Collection<int, InvoiceItem>  $lines */
    private static function cents(Collection $lines): int
    {
        return (int) $lines->sum(fn (InvoiceItem $line) => RecordPaymentService::toCents($line->price) * (int) $line->quantity);
    }
}
