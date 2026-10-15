<?php

namespace App\Services\Invoice;

use App\Enums\AdjustmentNoteStatus;
use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use Illuminate\Database\Eloquent\Collection;

/**
 * What merge and split share (PR E, sweep Π2.1/Π2.6): the customer's lock, then its invoices' in id order (the one order
 * every money path takes), and the checks, read under those locks. Lines move (their invoice_id changes), never copied:
 * each keeps its tax as issued (Δ1), its period and its own apply on payment, so two periods of one service on one
 * invoice renew it twice.
 */
final class InvoiceLineMove
{
    /**
     * The invoices, locked: their customer first, then each in id order. One customer only.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Invoice>
     */
    public static function lock(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $clients = Invoice::query()->whereKey($ids)->distinct()->pluck('client_id');
        if ($clients->count() !== 1 || $clients->first() === null) {
            throw new DisplayException(__('Only invoices of one customer are merged.'));
        }
        Client::lock((int) $clients->first());
        $invoices = Invoice::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        if ($invoices->count() !== count($ids)) {
            throw new DisplayException(__('One of these invoices no longer exists: reload the page.'));
        }
        // Moved to another customer between the read and the lock.
        if ($invoices->pluck('client_id')->unique()->count() !== 1) {
            throw new DisplayException(__('Only invoices of one customer are merged.'));
        }

        return $invoices;
    }

    /**
     * Why the lines of this invoice (locked) do not move, or null. Decision 8: an invoice with payments (processing
     * too) is refused, never unapplied as Blesta does; staff refund or delete the payment first.
     */
    public static function refusal(Invoice $invoice): ?string
    {
        $label = '#' . ($invoice->number ?: $invoice->id);
        if ($invoice->status !== Invoice::STATUS_UNPAID) {
            return __('Invoice :invoice is not unpaid: only unpaid invoices are merged or split.', ['invoice' => $label]);
        }
        if ($invoice->hasPayments(lock: true)) {
            return __('Invoice :invoice has payments: refund or delete them first.', ['invoice' => $label]);
        }
        if ($invoice->adjustmentNotes()->where('status', AdjustmentNoteStatus::Active->value)->exists()) {
            return __('Invoice :invoice has a credit note: void it first.', ['invoice' => $label]);
        }
        // The renewal run finishes it (email, credit, charge) in a moment (PR C).
        if ($invoice->finish_pending_at !== null) {
            return __('Invoice :invoice is being finished by the renewal run: try again in a minute.', ['invoice' => $label]);
        }
        $lines = InvoiceItem::query()->where('invoice_id', $invoice->id)->lockForUpdate()->get();
        if ($lines->contains(fn (InvoiceItem $line) => $line->applied_at !== null)) {
            return __('Invoice :invoice has a line already applied.', ['invoice' => $label]);
        }
        // A credit purchase gives its credit when paid, from its own money (GrantBoughtCreditService).
        if ($lines->contains(fn (InvoiceItem $line) => $line->reference_type === Credit::class)) {
            return __('Invoice :invoice buys account credit: it is not merged or split.', ['invoice' => $label]);
        }
        // A gateway subscription pays its own invoice (decision 6).
        $services = $lines->where('reference_type', Service::class)->pluck('reference_id')->all();
        if ($services !== [] && Service::query()->whereKey($services)->whereNotNull('subscription_id')->where('subscription_id', '!=', '')->exists()) {
            return __('Invoice :invoice is paid by a gateway subscription: it is not merged or split.', ['invoice' => $label]);
        }

        return null;
    }

    /** What the customer was billed as on it (the snapshot taken when it was issued): name, address, tax. */
    public static function billedAs(Invoice $invoice): ?array
    {
        $snapshot = $invoice->snapshot()->first();

        return $snapshot === null ? null : [
            'name' => $snapshot->name,
            'properties' => $snapshot->properties,
            'tax_name' => $snapshot->tax_name,
            'tax_rate' => $snapshot->tax_rate === null ? null : (string) $snapshot->tax_rate,
            'tax_country' => $snapshot->tax_country,
        ];
    }
}
