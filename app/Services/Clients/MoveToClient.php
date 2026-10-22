<?php

namespace App\Services\Clients;

use App\Events\Invoice as InvoiceEvent;
use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Services\Invoice\CreateInvoiceSnapshotService;
use App\Services\Invoice\InvoiceLineMove;
use App\Services\Invoice\LineTax;
use App\Services\Invoice\RecordPaymentService;
use App\Support\AfterCommit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A service or a domain moved to another client by staff (owner 2026-10-23, as WHMCS's «Move»): the item is the other
 * client's from now on, with what is still to be paid for it.
 * - Its invoices not paid yet (draft or unpaid) go with it. One that bills no other item moves whole (same number), its
 *   lines without an item too (a discount), re-taxed and addressed to its new client, with its order when the order is
 *   only this item's. One that also bills other items keeps them, and this item's lines move (never copied) to a new
 *   invoice of the new client, as a split does (SplitInvoice); one left with nothing to pay is closed. A paid or
 *   cancelled invoice stays where it is (its history). Each is refused as a split is (InvoiceLineMove::refusal): a
 *   payment, a credit note, an applied line, an ended service's... staff settle it first (its money is the first
 *   client's).
 * - Customers locked first (both, in id order), then the item, then its invoices: the order every money path takes.
 * - A service's related contact (the first client's) is cleared; a gateway subscription (the first client's card or
 *   PayPal) or a cancellation the first client asked for refuses the move.
 * - A domain's contacts are copied to the new client and the domain points to them, its handles kept: nothing changes at
 *   the registrar (no change of owner at the registry; that is its own step, G8-5).
 * A note on both accounts says who moved what, when.
 */
final class MoveToClient
{
    /** @return array{moved: list<int>, split: array<int, int>} invoices moved whole, and old invoice => new invoice */
    public function service(Service $service, Client $to, Staff $by): array
    {
        return DB::transaction(function () use ($service, $to, $by) {
            $from = $this->lockClients(Service::query()->whereKey($service->id)->value('client_id'), $to);
            $service = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
            $this->refuseChanged((int) $service->client_id, $from);
            if (filled($service->subscription_id)) {
                throw new DisplayException(__('clients.move.subscription'));
            }
            // The first client asked to end it: the new one would lose it for a request it never made.
            if (!$service->isEnded() && $service->cancellation()->exists()) {
                throw new DisplayException(__('clients.move.cancellation'));
            }
            $refs = [[Service::class, $service->id]];
            foreach (ServiceUpgrade::query()->where('service_id', $service->id)->pluck('id') as $upgrade) {
                $refs[] = [ServiceUpgrade::class, (int) $upgrade];
            }
            $plan = $this->plan($refs, $service);
            $service->forceFill(['client_id' => $to->id, 'contact_id' => null])->save();
            $result = $this->moveInvoices($plan, $to);
            $label = '#' . $service->id . ' ' . (string) $service->product?->name;
            $this->notes($from, $to, $by, 'service', $label);

            return $result;
        });
    }

    /** @return array{moved: list<int>, split: array<int, int>} */
    public function domain(Domain $domain, Client $to, Staff $by): array
    {
        return DB::transaction(function () use ($domain, $to, $by) {
            $from = $this->lockClients(Domain::query()->whereKey($domain->id)->value('client_id'), $to);
            $domain = Domain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();
            $this->refuseChanged((int) $domain->client_id, $from);
            $plan = $this->plan([[Domain::class, $domain->id]], $domain);
            // Its contacts copied (each once, whatever roles it holds) and the roles pointing to the copies; the handles
            // stay as they are (written without the model, which would clear a role's handle with its contact).
            $copies = [];
            $update = ['client_id' => $to->id];
            foreach (Domain::ROLES as $role) {
                $id = $domain->{"{$role}_contact_id"};
                if ($id === null) {
                    continue;
                }
                $copies[$id] ??= $this->copyContact((int) $id, $to);
                $update["{$role}_contact_id"] = $copies[$id];
            }
            $meta = $domain->meta ?? [];
            if (isset($meta['contact_handles']) && is_array($meta['contact_handles'])) {
                $handles = [];
                foreach ($meta['contact_handles'] as $contact => $handle) {
                    $handles[$copies[$contact] ?? $contact] = $handle;
                }
                $meta['contact_handles'] = $handles;
            }
            $update['meta'] = json_encode($meta);
            Domain::query()->whereKey($domain->id)->update($update);
            $domain->refresh();
            $result = $this->moveInvoices($plan, $to);
            $this->notes($from, $to, $by, 'domain', (string) $domain->fqdn);

            return $result;
        });
    }

    /** Both customers locked, in id order, before the item and its invoices (RecordPaymentService::lockInvoice). */
    private function lockClients(mixed $fromId, Client $to): Client
    {
        $from = (int) $fromId;
        if ($from === (int) $to->id) {
            throw new DisplayException(__('clients.move.same'));
        }
        $ids = [$from, (int) $to->id];
        sort($ids);
        foreach ($ids as $id) {
            Client::lock($id);
        }

        return Client::query()->findOrFail($from);
    }

    /** Moved by someone else between the read and the lock: the lock held is not its customer's. */
    private function refuseChanged(int $owner, Client $from): void
    {
        if ($owner !== (int) $from->id) {
            throw new DisplayException(__('clients.move.changed'));
        }
    }

    /**
     * Its invoices not paid yet (draft or unpaid), locked in id order, each with what moves: whole, or this item's lines.
     * Refused as a split is; checked before anything changes.
     *
     * @param  list<array{0: class-string, 1: int}>  $refs
     * @return list<array{invoice: Invoice, whole: bool, lines: Collection<int, InvoiceItem>, order: ?Order}>
     */
    private function plan(array $refs, Service|Domain $item): array
    {
        $ids = InvoiceItem::query()->where(function ($q) use ($refs) {
            foreach ($refs as [$type, $id]) {
                $q->orWhere(fn ($q) => $q->where('reference_type', $type)->where('reference_id', $id));
            }
        })->pluck('invoice_id')->unique();
        $invoices = Invoice::query()->whereKey($ids)->whereIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID])
            ->orderBy('id')->lockForUpdate()->get();
        $isOurs = fn (InvoiceItem $line) => in_array([$line->reference_type, (int) $line->reference_id], $refs, true);
        $plan = [];
        foreach ($invoices as $invoice) {
            $label = $invoice->number ?: '#' . $invoice->id;
            $lines = InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get();
            $ours = $lines->filter($isOurs)->values();
            // Lines of another item stay; a line of no item (a discount, a fee typed by staff) goes with the item it is
            // on an invoice with (adversarial ADV-2).
            $others = $lines->reject($isOurs)->filter(fn (InvoiceItem $line) => $line->reference_type !== null);
            $whole = $others->isEmpty();
            $order = $whole ? Order::query()->where('invoice_id', $invoice->id)->first() : null;
            // An order's own invoice goes with its order when the order is only this item's; otherwise it stays.
            $orderMoves = $order !== null && $this->orderIsOnly($order, $item);
            if ($invoice->status === Invoice::STATUS_DRAFT) {
                $why = match (true) {
                    !$whole => __('clients.move.draft_split', ['invoices' => $label]),
                    $invoice->hasPayments(lock: true) => __('clients.move.paid_in_part', ['invoices' => $label]),
                    default => null,
                };
            } else {
                $why = InvoiceLineMove::refusal($invoice, $orderMoves);
            }
            if ($why === null && !$whole) {
                // Neither part less than nothing (code review 4, as SplitInvoice's E-ADV-7); one of nothing (a free item) is
                // closed below (adversarial R2, note).
                $moving = (int) $ours->sum(fn (InvoiceItem $line) => $line->totalCents());
                $staying = (int) $lines->reject($isOurs)->sum(fn (InvoiceItem $line) => $line->totalCents());
                if ($moving < 0 || $staying < 0) {
                    $why = __('clients.move.nothing', ['invoices' => $label]);
                }
            }
            if ($why !== null) {
                throw new DisplayException($why);
            }
            $plan[] = ['invoice' => $invoice, 'whole' => $whole, 'lines' => $whole ? $lines : $ours, 'order' => $orderMoves ? $order : null];
        }

        return $plan;
    }

    private function orderIsOnly(Order $order, Service|Domain $item): bool
    {
        $services = $order->services()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $domains = $order->domains()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $item instanceof Service ? $services === [(int) $item->id] && $domains === [] : $domains === [(int) $item->id] && $services === [];
    }

    /**
     * @param  list<array{invoice: Invoice, whole: bool, lines: Collection<int, InvoiceItem>, order: ?Order}>  $plan
     * @return array{moved: list<int>, split: array<int, int>}
     */
    private function moveInvoices(array $plan, Client $to): array
    {
        $result = ['moved' => [], 'split' => []];
        foreach ($plan as ['invoice' => $invoice, 'whole' => $whole, 'lines' => $lines, 'order' => $order]) {
            if ($whole) {
                $order?->forceFill(['client_id' => $to->id])->save();
                $invoice->client_id = $to->id;
                $invoice->save();
                $invoice->setRelation('client', $to);
                $this->retax($lines, $invoice);
                $this->readdress($invoice);
                $result['moved'][] = $invoice->id;
                $this->sendAgain($invoice);

                continue;
            }
            // This item's lines move to a new invoice of the new client, as SplitInvoice moves them: issued when the first
            // was, kept from the cron's charge and as a debt as it was, pointing at it. Sent once, by its creation (after
            // the commit, its lines on it; adversarial ADV-3).
            $new = new Invoice(['client_id' => $to->id, 'status' => $invoice->status, 'due_at' => $invoice->due_at, 'currency_code' => $invoice->currency_code]);
            $new->split_from_id = $invoice->id;
            $new->kept_as_debt_at = $invoice->kept_as_debt_at;
            $new->no_auto_charge = (bool) $invoice->no_auto_charge;
            $new->created_at = $invoice->created_at;
            $new->save();
            $new->setRelation('client', $to);
            InvoiceLineMove::move($lines, $new);
            $this->retax($lines, $new);
            $result['split'][$invoice->id] = $new->id;
            // The first left with nothing to pay is closed, as a zero invoice is; not with an upgrade's line, whose
            // credit nobody decided (ChangeOpenLine; adversarial ADV-4).
            foreach ([$invoice, $new] as $part) {
                if ($part->status === Invoice::STATUS_UNPAID
                    && !InvoiceItem::query()->where('invoice_id', $part->id)->where('reference_type', ServiceUpgrade::class)->exists()) {
                    (new RecordPaymentService)->settle($part->id);
                }
            }
            $this->sendAgain($invoice);
        }

        return $result;
    }

    /**
     * @param  Collection<int, InvoiceItem>  $lines
     */
    private function retax(Collection $lines, Invoice $invoice): void
    {
        foreach ($lines as $line) {
            $line->refresh();
            $line->setRelation('invoice', $invoice);
            $line->forceFill(LineTax::decide($line))->save();
        }
    }

    /** Addressed to its new client: the snapshot taken when it was issued was the first client's (ADV-1, code review 1). */
    private function readdress(Invoice $invoice): void
    {
        $snapshot = $invoice->snapshot()->first();
        if ($snapshot === null) {
            return;
        }
        $snapshot->delete();
        $invoice->unsetRelation('snapshot');
        app(CreateInvoiceSnapshotService::class)->handle($invoice);
    }

    /** An unpaid invoice that changed is sent again (its new total, its new client), once the move commits. */
    private function sendAgain(Invoice $invoice): void
    {
        if ($invoice->refresh()->status === Invoice::STATUS_UNPAID) {
            AfterCommit::event(new InvoiceEvent\Finalized($invoice, true), $invoice);
        }
    }

    private function copyContact(int $id, Client $to): int
    {
        $contact = Contact::query()->findOrFail($id);
        $copy = $contact->replicate(['primary_of_client_id', 'archived_at']);
        $copy->forceFill(['client_id' => $to->id]);
        $copy->save();

        return (int) $copy->id;
    }

    private function notes(Client $from, Client $to, Staff $by, string $what, string $label): void
    {
        ClientNote::query()->create(['client_id' => $from->id, 'staff_id' => $by->id, 'sticky' => false,
            'body' => __("clients.move.note_from_{$what}", ['item' => $label, 'client' => $to->name . ' (#' . $to->id . ')'])]);
        ClientNote::query()->create(['client_id' => $to->id, 'staff_id' => $by->id, 'sticky' => false,
            'body' => __("clients.move.note_to_{$what}", ['item' => $label, 'client' => $from->name . ' (#' . $from->id . ')'])]);
    }
}
