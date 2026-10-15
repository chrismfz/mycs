<?php

namespace App\Services\Invoice;

use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;

/**
 * Merge for staff (PR E, decision 8, sweep Π2.1–Π2.5): the lines of the other invoices move onto one the staff choose
 * (by default the oldest), in one transaction under the customer → invoices locks. The target keeps its id and number and
 * takes the earliest due date, so a merge never delays a suspension. The others are kept, cancelled, «merged into #X»
 * (WHMCS deletes them): no credit note (they bill nothing any more) and no period given up on (their lines moved, Δ3).
 *
 * Refused (InvoiceLineMove::refusal): any invoice not unpaid, with payments, a credit note, a line already applied, a
 * credit purchase or a gateway subscription; another customer or currency; billed to another name, address or tax (a
 * merge never re-taxes a line); a debt kept for an ended service (Δ3a) with one that is not.
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
            $billedAs = $invoices->map(fn (Invoice $invoice) => InvoiceLineMove::billedAs($invoice))->filter()->unique(fn ($as) => json_encode($as));
            if ($billedAs->count() > 1) {
                throw new DisplayException(__('These invoices bill the customer under different details (name, address or tax): they are not merged.'));
            }

            $label = '#' . ($target->number ?: $target->id);
            InvoiceLineMove::move(InvoiceItem::query()->whereIn('invoice_id', $sources->pluck('id'))->orderBy('id')->get(), $target);
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
