<?php

namespace App\Support\Portal;

use App\Classes\Price;
use App\Enums\DomainStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Ticket;
use App\Support\CurrentClient;
use App\Support\ServiceIdentifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * The home page's counts and its services table (docs/ROADMAP.md, home PR 2; owner 2026-11-03: «as WHMCS, compact»):
 * services, domains, tickets and unpaid invoices at a glance, each a link to its list, and the services that run with
 * what tells each apart. Each only to who may see its area (a member's grant), as the rest of the page.
 */
final class Summary
{
    /** The services the table shows; the rest are «All (N)». */
    public const SERVICES = 8;

    /** A domain that ends within these days is counted as ending soon (as Attention::DOMAIN_DAYS). */
    public const DOMAIN_DAYS = Attention::DOMAIN_DAYS;

    public function __construct(private readonly Client $client) {}

    /**
     * @return list<array{key: string, count: int, label: string, detail: string, url: string, tone: string}>
     */
    public function tiles(): array
    {
        $tiles = [];
        if (CurrentClient::allows('products')) {
            $tiles[] = $this->servicesTile();
        }
        if (CurrentClient::allows('domains')) {
            $tiles[] = $this->domainsTile();
        }
        if (!config('settings.tickets_disabled', false) && CurrentClient::allows('tickets')) {
            $tiles[] = $this->ticketsTile();
        }
        if (CurrentClient::allows('invoices')) {
            $tiles[] = $this->invoicesTile();
        }

        return $tiles;
    }

    /**
     * The services that run (active or suspended), the newest first, with what ServiceIdentifier reads; none without
     * `products`.
     *
     * @return Collection<int, Service>
     */
    public function services(int $limit = self::SERVICES): Collection
    {
        if (!CurrentClient::allows('products')) {
            return collect();
        }

        return $this->running()->with(['plan', 'product', ...ServiceIdentifier::RELATIONS])
            ->orderByRaw('CASE status WHEN ? THEN 0 ELSE 1 END', [Service::STATUS_ACTIVE])->orderByDesc('id')->limit($limit)->get();
    }

    /** How many run (the table's «All (N)»). */
    public function servicesCount(): int
    {
        return CurrentClient::allows('products') ? $this->running()->count() : 0;
    }

    /** @return HasMany<Service, Client> */
    private function running()
    {
        return $this->client->services()->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED]);
    }

    /** @return array{key: string, count: int, label: string, detail: string, url: string, tone: string} */
    private function servicesTile(): array
    {
        $active = $this->client->services()->where('status', Service::STATUS_ACTIVE)->count();
        $suspended = $this->client->services()->where('status', Service::STATUS_SUSPENDED)->count();

        return ['key' => 'services', 'count' => $active, 'label' => trans_choice('dashboard.summary.services', $active),
            'detail' => $suspended > 0 ? trans_choice('dashboard.summary.services_suspended', $suspended, ['count' => $suspended]) : '',
            'url' => route('services'), 'tone' => $suspended > 0 ? 'warning' : 'primary'];
    }

    /** @return array{key: string, count: int, label: string, detail: string, url: string, tone: string} */
    private function domainsTile(): array
    {
        $domains = $this->client->domains()->whereNotIn('status', DomainStatus::terminalValues());
        $count = (clone $domains)->count();
        $ending = (clone $domains)->where('status', DomainStatus::Active->value)->whereNotNull('expires_at')
            ->where('expires_at', '<=', CarbonImmutable::today()->addDays(self::DOMAIN_DAYS)->endOfDay())->count();

        return ['key' => 'domains', 'count' => $count, 'label' => trans_choice('dashboard.summary.domains', $count),
            'detail' => $ending > 0 ? trans_choice('dashboard.summary.domains_ending', $ending, ['count' => $ending, 'days' => self::DOMAIN_DAYS]) : '',
            'url' => route('domains'), 'tone' => $ending > 0 ? 'warning' : 'primary'];
    }

    /** @return array{key: string, count: int, label: string, detail: string, url: string, tone: string} */
    private function ticketsTile(): array
    {
        $open = $this->client->tickets()->where('status', '!=', Ticket::STATUS_CLOSED)->count();
        $yours = $this->client->tickets()->where('status', Ticket::STATUS_ANSWERED)->count();

        return ['key' => 'tickets', 'count' => $open, 'label' => trans_choice('dashboard.summary.tickets', $open),
            'detail' => $yours > 0 ? trans_choice('dashboard.summary.tickets_yours', $yours, ['count' => $yours]) : '',
            'url' => route('tickets'), 'tone' => $yours > 0 ? 'warning' : 'primary'];
    }

    /** @return array{key: string, count: int, label: string, detail: string, url: string, tone: string} */
    private function invoicesTile(): array
    {
        $invoices = $this->client->invoices()->where('status', Invoice::STATUS_UNPAID)->with(['transactions', 'items', 'adjustmentNotes', 'currency'])->get();
        // What they come to, per currency, as Attention::unpaid says it.
        $amounts = $invoices->groupBy('currency_code')->map(function ($group) {
            $cents = $group->sum(fn (Invoice $invoice) => (int) round($invoice->remaining * 100));

            return (string) new Price(['price' => $cents / 100, 'currency' => $group->first()->currency], tax: 0);
        })->implode(' + ');
        $overdue = $invoices->filter(fn (Invoice $invoice) => $invoice->due_at?->lt(CarbonImmutable::today()))->count();
        $detail = $amounts === '' ? '' : ($overdue > 0 ? trans_choice('dashboard.summary.invoices_overdue', $overdue, ['amount' => $amounts, 'count' => $overdue]) : $amounts);

        return ['key' => 'invoices', 'count' => $invoices->count(), 'label' => trans_choice('dashboard.summary.invoices', $invoices->count()),
            'detail' => $detail, 'url' => route('invoices', ['status' => 'unpaid']), 'tone' => $overdue > 0 ? 'error' : 'primary'];
    }
}
