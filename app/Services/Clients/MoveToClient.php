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
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Services\Invoice\LineTax;
use App\Support\AfterCommit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A service or a domain moved to another client by staff (owner 2026-10-23, as WHMCS's «Move»): the item is the other
 * client's from now on, with what is still to be paid for it.
 * - Its invoices not paid yet (draft or unpaid) go with it: one that bills only it moves whole (same number), re-taxed
 *   for its new client; one that also bills other items keeps them, and its lines for this item move to a new invoice of
 *   the new client. A paid or cancelled invoice stays where it is (its history). One with a payment refuses the move:
 *   staff settle it first (its money is the first client's).
 * - A service's related contact (the first client's) is cleared; a gateway subscription (the first client's card or
 *   PayPal) refuses the move until it is cancelled.
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
            $service = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
            $from = $this->refuseSame((int) $service->client_id, $to);
            if (filled($service->subscription_id)) {
                throw new DisplayException(__('clients.move.subscription'));
            }
            $refs = [[Service::class, $service->id]];
            foreach (ServiceUpgrade::query()->where('service_id', $service->id)->pluck('id') as $upgrade) {
                $refs[] = [ServiceUpgrade::class, (int) $upgrade];
            }
            $invoices = $this->openInvoices($refs);
            $service->forceFill(['client_id' => $to->id, 'contact_id' => null])->save();
            $result = $this->moveInvoices($invoices, $refs, $to);
            $label = '#' . $service->id . ' ' . (string) $service->product?->name;
            $this->notes($from, $to, $by, 'service', $label);

            return $result;
        });
    }

    /** @return array{moved: list<int>, split: array<int, int>} */
    public function domain(Domain $domain, Client $to, Staff $by): array
    {
        return DB::transaction(function () use ($domain, $to, $by) {
            $domain = Domain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();
            $from = $this->refuseSame((int) $domain->client_id, $to);
            $refs = [[Domain::class, $domain->id]];
            $invoices = $this->openInvoices($refs);
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
            $result = $this->moveInvoices($invoices, $refs, $to);
            $this->notes($from, $to, $by, 'domain', (string) $domain->fqdn);

            return $result;
        });
    }

    private function refuseSame(int $from, Client $to): Client
    {
        if ($from === (int) $to->id) {
            throw new DisplayException(__('clients.move.same'));
        }

        return Client::query()->findOrFail($from);
    }

    /**
     * Its invoices not paid yet (draft or unpaid), read locked; one with a payment refuses the move.
     *
     * @param  list<array{0: class-string, 1: int}>  $refs
     * @return Collection<int, Invoice>
     */
    private function openInvoices(array $refs): Collection
    {
        $ids = InvoiceItem::query()->where(function ($q) use ($refs) {
            foreach ($refs as [$type, $id]) {
                $q->orWhere(fn ($q) => $q->where('reference_type', $type)->where('reference_id', $id));
            }
        })->pluck('invoice_id')->unique();
        $invoices = Invoice::query()->whereKey($ids)->whereIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_UNPAID])
            ->lockForUpdate()->get();
        $paid = $invoices->filter(fn (Invoice $invoice) => $invoice->hasPayments(lock: true));
        if ($paid->isNotEmpty()) {
            throw new DisplayException(__('clients.move.paid_in_part', ['invoices' => $paid->map(fn (Invoice $i) => $i->number ?: '#' . $i->id)->implode(', ')]));
        }

        return $invoices;
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @param  list<array{0: class-string, 1: int}>  $refs
     * @return array{moved: list<int>, split: array<int, int>}
     */
    private function moveInvoices(Collection $invoices, array $refs, Client $to): array
    {
        $result = ['moved' => [], 'split' => []];
        $isOurs = fn (InvoiceItem $item) => in_array([$item->reference_type, (int) $item->reference_id], $refs, true);
        foreach ($invoices as $invoice) {
            $items = $invoice->items()->get();
            $ours = $items->filter($isOurs);
            if ($ours->isEmpty()) {
                continue;
            }
            if ($ours->count() === $items->count()) {
                // It bills only this item: it moves whole, its lines taxed again for their new client.
                $invoice->client_id = $to->id;
                $invoice->save();
                $invoice->setRelation('client', $to);
                foreach ($ours as $item) {
                    $item->setRelation('invoice', $invoice);
                    $item->forceFill(LineTax::decide($item))->save();
                }
                $result['moved'][] = $invoice->id;
                $this->sendAgain($invoice);

                continue;
            }
            // It also bills other items: they stay; this item's lines go to a new invoice of the new client.
            $new = new Invoice(['client_id' => $to->id, 'status' => $invoice->status, 'due_at' => $invoice->due_at, 'currency_code' => $invoice->currency_code]);
            $new->save();
            foreach ($ours as $item) {
                $new->items()->create(array_filter([
                    'reference_type' => $item->reference_type, 'reference_id' => $item->reference_id, 'kind' => $item->kind,
                    'description' => $item->description, 'price' => $item->price, 'quantity' => $item->quantity, 'taxed' => $item->taxed,
                    'period_start' => $item->period_start?->toDateString(), 'period_end' => $item->period_end?->toDateString(),
                ], fn ($v) => $v !== null));
                $item->delete();
            }
            $result['split'][$invoice->id] = $new->id;
            $this->sendAgain($invoice);
            $this->sendAgain($new);
        }

        return $result;
    }

    /** An unpaid invoice that changed is sent again (its new total, its new client), once the move commits. */
    private function sendAgain(Invoice $invoice): void
    {
        if ($invoice->status === Invoice::STATUS_UNPAID) {
            AfterCommit::event(new InvoiceEvent\Finalized($invoice->refresh(), true), $invoice);
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
