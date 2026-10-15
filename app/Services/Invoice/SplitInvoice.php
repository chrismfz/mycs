<?php

namespace App\Services\Invoice;

use App\Exceptions\DisplayException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;

/**
 * Split for staff (PR E, sweep Π2.6): the chosen lines move to a new invoice, in one transaction under the customer →
 * invoice locks, refused as a merge is (InvoiceLineMove::refusal). Lines by id only, never matched by their text; at least
 * one stays. The new invoice has the same customer, currency, due date and billing details (the snapshot taken when the
 * first was issued), and points at the one it left (split_from_id). It gets its number as any new invoice does (none
 * while it is an open proforma). Its email is not sent: staff send it when they want to.
 */
final class SplitInvoice
{
    /** @param list<int> $lineIds */
    public function handle(int $invoiceId, array $lineIds): Invoice
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        if ($lineIds === []) {
            throw new DisplayException(__('Choose the lines to move to a new invoice.'));
        }

        return DB::transaction(function () use ($invoiceId, $lineIds) {
            $invoice = InvoiceLineMove::lock([$invoiceId])->sole();
            if (($why = InvoiceLineMove::refusal($invoice)) !== null) {
                throw new DisplayException($why);
            }
            $all = InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id);
            if (array_diff($lineIds, $all->all()) !== []) {
                throw new DisplayException(__('A chosen line is no longer on this invoice: reload the page.'));
            }
            if (count($lineIds) >= $all->count()) {
                throw new DisplayException(__('At least one line stays on the invoice.'));
            }
            // Each part bills something (adversarial E-ADV-7): a discount line alone would make an invoice of less than
            // nothing, and the other part would bill without it; a part of nothing would stay open, reminded.
            $lines = InvoiceItem::query()->where('invoice_id', $invoice->id)->get();
            $moving = (int) $lines->whereIn('id', $lineIds)->sum(fn (InvoiceItem $line) => $line->totalCents());
            $staying = (int) $lines->whereNotIn('id', $lineIds)->sum(fn (InvoiceItem $line) => $line->totalCents());
            if ($moving <= 0 || $staying <= 0) {
                throw new DisplayException(__('Each invoice must bill more than nothing: keep a discount with the lines it discounts.'));
            }

            $new = new Invoice([
                'client_id' => $invoice->client_id,
                'currency_code' => $invoice->currency_code,
                'due_at' => $invoice->due_at,
                'status' => Invoice::STATUS_UNPAID,
            ]);
            $new->send_create_email = false;
            $new->split_from_id = $invoice->id;
            $new->kept_as_debt_at = $invoice->kept_as_debt_at;
            // Issued when the first was: a suspension that waits for an invoice of a given age is not delayed (CR-5).
            $new->created_at = $invoice->created_at;
            $new->save();
            // Billed as the first was when issued: a split never re-taxes or re-addresses a line. The first has none (an
            // imported invoice, snapshots off): nor does the new one (CR-8).
            $first = $invoice->snapshot()->first();
            $snapshot = $new->snapshot()->first();
            if ($first !== null && $snapshot !== null) {
                $snapshot->update($first->only(['name', 'properties', 'bill_to', 'tax_name', 'tax_rate', 'tax_country']));
            } elseif ($first === null) {
                $snapshot?->delete();
            }

            InvoiceLineMove::move(InvoiceItem::query()->whereKey($lineIds)->orderBy('id')->get(), $new);

            return $new->fresh();
        });
    }
}
