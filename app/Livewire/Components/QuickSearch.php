<?php

namespace App\Livewire\Components;

use App\Livewire\Component;
use App\Models\Invoice;
use App\Support\CurrentClient;
use Livewire\Attributes\Computed;

/**
 * Quick search (P4d, owner 2026-10-19): Ctrl+K (⌘K) or the 🔍 in the header, «shop» finds the domain, the service, the
 * invoice or the ticket. Only the account's own, each area within the member's grant.
 */
class QuickSearch extends Component
{
    /** Each kind's matches shown at most. */
    public const PER_KIND = 5;

    public string $q = '';

    /**
     * @return list<array{kind: string, title: string, detail: string, url: string}>
     */
    #[Computed]
    public function results(): array
    {
        $term = trim($this->q);
        $client = CurrentClient::model();
        if ($client === null || mb_strlen($term) < 2 || mb_strlen($term) > 100) {
            return [];
        }
        // «!» escapes, the same on MariaDB and SQLite (neither then reads «%» or «_» typed as a wildcard).
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)) . '%';
        $id = ctype_digit(ltrim($term, '#')) ? (int) ltrim($term, '#') : null;
        $results = [];

        if (CurrentClient::allows('domains')) {
            foreach ($client->domains()->whereRaw("lower(fqdn) like ? escape '!'", [$like])->orderBy('fqdn')->limit(self::PER_KIND)->get() as $domain) {
                $results[] = ['kind' => 'domain', 'title' => $domain->fqdn,
                    'detail' => $domain->status->getLabel() . ($domain->expires_at ? ' · ' . __('search.expires', ['date' => $domain->expires_at->format('d/m/Y')]) : ''),
                    'url' => route('domains.show', $domain)];
            }
        }
        if (CurrentClient::allows('products')) {
            $services = $client->services()->with('product')
                ->where(fn ($q) => $q->whereRaw("lower(label) like ? escape '!'", [$like])->orWhereRaw("lower(domain) like ? escape '!'", [$like])
                    ->orWhereHas('product', fn ($product) => $product->whereRaw("lower(name) like ? escape '!'", [$like]))
                    ->when($id !== null, fn ($q) => $q->orWhere('id', $id)))
                ->orderByDesc('id')->limit(self::PER_KIND)->get();
            foreach ($services as $service) {
                $results[] = ['kind' => 'service', 'title' => (string) $service->label,
                    'detail' => trim(($service->domain ? $service->domain . ' · ' : '') . __('services.statuses.' . $service->status)),
                    'url' => route('services.show', $service)];
            }
        }
        if (CurrentClient::allows('invoices')) {
            $invoices = $client->invoices()->where('status', '!=', Invoice::STATUS_DRAFT)
                ->where(fn ($q) => $q->whereRaw("lower(number) like ? escape '!'", [$like])->when($id !== null, fn ($q) => $q->orWhere('id', $id)))
                ->orderByDesc('id')->limit(self::PER_KIND)->get();
            foreach ($invoices as $invoice) {
                $results[] = ['kind' => 'invoice',
                    'title' => !$invoice->number && config('settings.invoice_proforma', false) ? __('invoices.proforma_invoice', ['id' => $invoice->id]) : __('invoices.invoice', ['id' => $invoice->number]),
                    'detail' => __('invoices.statuses.' . $invoice->status) . ' · ' . $invoice->created_at->format('d/m/Y'),
                    'url' => route('invoices.show', $invoice)];
            }
        }
        if (!config('settings.tickets_disabled', false) && CurrentClient::allows('tickets')) {
            $tickets = $client->tickets()
                ->where(fn ($q) => $q->whereRaw("lower(subject) like ? escape '!'", [$like])->when($id !== null, fn ($q) => $q->orWhere('id', $id)))
                ->orderByDesc('id')->limit(self::PER_KIND)->get();
            foreach ($tickets as $ticket) {
                $results[] = ['kind' => 'ticket', 'title' => '#' . $ticket->id . ' · ' . $ticket->subject,
                    'detail' => in_array($ticket->status, ['open', 'replied', 'closed'], true) ? __('ticket.statuses.' . $ticket->status) : (string) $ticket->status,
                    'url' => route('tickets.show', $ticket)];
            }
        }

        return $results;
    }

    public function render()
    {
        return view('components.quick-search');
    }
}
