<?php

namespace App\Services\Invoice;

use App\Enums\AdjustmentNoteStatus;
use App\Exceptions\DisplayException;
use App\Models\AdjustmentNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Merge for staff (PR E, decision 8, sweep Π2.1–Π2.5): the lines of the other invoices move onto one the staff choose
 * (by default the oldest), in one transaction under the customer → invoices locks. The target keeps its id and number and
 * takes the earliest due date, so a merge never delays a suspension. The others are kept, cancelled, «merged into #X»
 * (WHMCS deletes them): no credit note (they bill nothing any more) and no period given up on (their lines moved, Δ3).
 *
 * Refused (InvoiceLineMove::refusal): any invoice not unpaid or a draft, one that is Ekdosi's (a document, a request, an
 * account with «immediate invoicing»), with payments, a line already applied, a credit purchase or a gateway
 * subscription; another customer or currency; a debt kept for an ended service (Δ3a) with one that is not. Since
 * 2026-11-10 (owner, docs/staff-freedom.md): the target's billing details apply to what joins it (no line is re-taxed:
 * each keeps its tax as issued, Δ1), the active notes of the others move with their lines, and an order whose own
 * invoice was merged away follows its lines to the target.
 */
final class MergeInvoices
{
    /** @param list<int> $sourceIds */
    public function handle(int $targetId, array $sourceIds): Invoice
    {
        $sourceIds = array_values(array_diff(array_unique(array_map('intval', $sourceIds)), [$targetId]));
        if ($sourceIds === []) {
            throw new DisplayException(__('Choose at least two invoices to merge.'));
        }

        return DB::transaction(function () use ($targetId, $sourceIds) {
            $invoices = InvoiceLineMove::lock([$targetId, ...$sourceIds]);
            $target = $invoices->firstWhere('id', $targetId);
            $sources = $invoices->where('id', '!=', $targetId)->values();
            foreach ($invoices as $invoice) {
                if (($why = InvoiceLineMove::refusal($invoice)) !== null) {
                    throw new DisplayException($why);
                }
            }
            if ($invoices->pluck('currency_code')->unique()->count() !== 1) {
                throw new DisplayException(__('Only invoices in one currency are merged.'));
            }
            if ($invoices->map(fn (Invoice $invoice) => $invoice->kept_as_debt_at === null)->unique()->count() !== 1) {
                throw new DisplayException(__('A debt kept for an ended service is not merged with other invoices.'));
            }

            $label = '#' . ($target->number ?: $target->id);
            InvoiceLineMove::move(InvoiceItem::query()->whereIn('invoice_id', $sources->pluck('id'))->orderBy('id')->get(), $target);
            // Their active notes go with the lines they discount or charge (else the customer's discount would stay on a
            // cancelled, empty invoice). Each keeps its number and amount; by its key: a note never changes invoice by hand.
            AdjustmentNote::query()->whereIn('invoice_id', $sources->pluck('id'))->where('status', AdjustmentNoteStatus::Active->value)
                ->update(['invoice_id' => $target->id]);
            // An order follows its own invoice's lines: «Cancel & Refund» then looks at the invoice that bills it (and,
            // seeing other lines there too, leaves the refund to staff).
            Order::query()->whereIn('invoice_id', $sources->pluck('id'))->update(['invoice_id' => $target->id]);
            // One kept from the cron's charge keeps the merged one from it (PR H2b, code review CR-2): its lines are not
            // charged because they moved.
            if ($invoices->contains(fn (Invoice $invoice) => (bool) $invoice->no_auto_charge) && !$target->no_auto_charge) {
                Invoice::query()->whereKey($target->id)->update(['no_auto_charge' => true]);
                $target->no_auto_charge = true;
                $target->syncOriginalAttribute('no_auto_charge');
            }
            $earliest = $invoices->min(fn (Invoice $invoice) => $invoice->due_at?->toDateString());
            if ($earliest !== null && $target->due_at?->toDateString() !== $earliest) {
                $target->update(['due_at' => $earliest]);
            }
            // And the earliest issue date: a suspension that waits for an invoice issued N days ago counts from the oldest
            // (adversarial E-ADV-5b). Not a money field.
            $issued = $invoices->min('created_at');
            if ($issued !== null && $target->created_at !== null && $issued->lt($target->created_at)) {
                Invoice::query()->whereKey($target->id)->update(['created_at' => $issued]);
            }
            foreach ($sources as $source) {
                $source->keep_periods_billable = true; // nothing left on it to give up on
                $source->merged_into_id = $target->id;
                $source->update([
                    'status' => Invoice::STATUS_CANCELLED,
                    'cancellation_reason' => __('Merged into invoice :invoice', ['invoice' => $label]),
                ]);
            }

            return $target->fresh();
        });
    }
}
