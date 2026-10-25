<?php

namespace App\Services\Invoice;

use App\Enums\AdjustmentNoteStatus;
use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\Credit;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Payment\SavedMethodCharge;
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
        $client = (int) $clients->first();
        Client::lock($client);
        $invoices = Invoice::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        if ($invoices->count() !== count($ids)) {
            throw new DisplayException(__('One of these invoices no longer exists: reload the page.'));
        }
        // Moved to another customer between the read and the lock (code review CR-6): the lock held is not theirs.
        if ($invoices->contains(fn (Invoice $invoice) => (int) $invoice->client_id !== $client)) {
            throw new DisplayException(__('One of these invoices changed meanwhile: reload the page.'));
        }

        return $invoices;
    }

    /**
     * Why the lines of this invoice (locked) do not move, or null. Decision 8: an invoice with payments (processing
     * too) is refused, never unapplied as Blesta does; staff refund or delete the payment first. $orderMovesWith: the order
     * goes with its invoice (a move to another client, MoveToClient), so being an order's invoice is no reason.
     */
    public static function refusal(Invoice $invoice, bool $orderMovesWith = false): ?string
    {
        $label = '#' . ($invoice->number ?: $invoice->id);
        if ($invoice->status !== Invoice::STATUS_UNPAID) {
            return __('Invoice :invoice is not unpaid: only unpaid invoices are merged or split.', ['invoice' => $label]);
        }
        // Ekdosi issued a fiscal document for it (on credit): its lines elsewhere would come back to the Inbox and be
        // issued twice, or carry a document in one account's ΑΦΜ to another account (ADV-2 of #459). Ekdosi cancels it first.
        if (EkdosiDocument::activeOn($invoice->id, lock: true)) {
            return __('Invoice :invoice has a fiscal document from Ekdosi: cancel it there first.', ['invoice' => $label]);
        }
        if ($invoice->hasPayments(lock: true)) {
            return __('Invoice :invoice has payments: refund or delete them first.', ['invoice' => $label]);
        }
        // A card charge on its way (taken, its webhook not here yet; or its outcome unknown): moved, the next run would
        // charge the other invoice in full and the late payment would land as credit, the card charged twice (sweep).
        if (SavedMethodCharge::onItsWay($invoice)) {
            return __('Invoice :invoice has a card charge on its way: wait for its outcome (minutes, or up to a day or three without the gateway\'s answer).', ['invoice' => $label]);
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
        // An order's own invoice: «Cancel & Refund» refunds the payments of that invoice (code review CR-4).
        if (!$orderMovesWith && Order::query()->where('invoice_id', $invoice->id)->exists()) {
            return __('Invoice :invoice is the invoice of an order: it is not merged or split.', ['invoice' => $label]);
        }
        // Lines of a service that ended, left open for staff (CancelOpenInvoices): moved, they would make another invoice
        // one the customer cannot pay (code review CR-3). A debt kept for it (Δ3a) moves as a debt.
        if ($invoice->kept_as_debt_at === null && $invoice->billsEndedService()) {
            return __('Invoice :invoice bills a service that has ended: cancel it, or keep it as a debt.', ['invoice' => $label]);
        }
        // A gateway subscription pays its own invoice (decision 6).
        $services = $lines->where('reference_type', Service::class)->pluck('reference_id')->all();
        if ($services !== [] && Service::query()->whereKey($services)->whereNotNull('subscription_id')->where('subscription_id', '!=', '')->exists()) {
            return __('Invoice :invoice is paid by a gateway subscription: it is not merged or split.', ['invoice' => $label]);
        }

        return null;
    }

    /**
     * The lines move to $to, and the upgrades they bill follow them: an upgrade reads its lines on its own invoice (what
     * was billed, its cancellation; code review CR-1/CR-2).
     *
     * @param  \Illuminate\Support\Collection<int, InvoiceItem>  $lines
     */
    public static function move(\Illuminate\Support\Collection $lines, Invoice $to): void
    {
        foreach ($lines as $line) {
            // One by one, through the model: each move is audited, and keeps its tax (InvoiceItemObserver).
            $line->update(['invoice_id' => $to->id]);
        }
        $upgrades = $lines->where('reference_type', ServiceUpgrade::class)->pluck('reference_id')->unique()->all();
        foreach (ServiceUpgrade::query()->whereKey($upgrades)->get() as $upgrade) {
            $upgrade->forceFill(['invoice_id' => $to->id])->save();
        }
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
