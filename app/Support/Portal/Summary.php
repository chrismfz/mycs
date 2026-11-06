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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

    /** @var array<string, int>|null how many services are active and suspended (serviceCounts) */
    private ?array $counts = null;

    /** @param  EloquentCollection<int, Invoice>|null  $unpaid  the account's unpaid invoices, read once by the page (review of #616) */
    public function __construct(private readonly Client $client, private readonly ?EloquentCollection $unpaid = null) {}

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

        return $this->running()->with(['product', ...ServiceIdentifier::RELATIONS])
            ->orderByRaw('CASE status WHEN ? THEN 0 ELSE 1 END', [Service::STATUS_ACTIVE])->orderByDesc('id')->limit($limit)->get();
    }

    /** How many run (the table's «+N more»). */
    public function servicesCount(): int
    {
        return CurrentClient::allows('products') ? array_sum($this->serviceCounts()) : 0;
    }

    /** @return array<string, int> how many of the account's services are active and suspended, in one query */
    private function serviceCounts(): array
    {
        return $this->counts ??= $this->running()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)->all();
    }

    /** @return HasMany<Service, Client> */
    private function running()
    {
        // Addons with their parent, as the services list shows them (docs/addons.md).
        return $this->client->services()->notAddons()->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED]);
    }

    /** @return array{key: string, count: int, label: string, detail: string, url: string, tone: string} */
    private function servicesTile(): array
    {
        $active = $this->serviceCounts()[Service::STATUS_ACTIVE] ?? 0;
        $suspended = $this->serviceCounts()[Service::STATUS_SUSPENDED] ?? 0;

        return ['key' => 'services', 'count' => $active, 'label' => trans_choice('dashboard.summary.services', $active),
            'detail' => $suspended > 0 ? trans_choice('dashboard.summary.services_suspended', $suspended, ['count' => $suspended]) : '',
            'url' => route('services'), 'tone' => $suspended > 0 ? 'warning' : 'primary'];
    }

    /** @return array{key: string, count: int, label: string, detail: string, url: string, tone: string} */
    private function domainsTile(): array
    {
        $domains = $this->client->domains()->whereNotIn('status', DomainStatus::terminalValues());
        $count = (clone $domains)->count();
        // Those «Needs your attention» tells (Attention::domains, review of #616): not renewing and ending within the days,
        // or ended but still recoverable; never one that renews by itself.
        $statuses = array_map(fn (DomainStatus $status) => $status->value, [DomainStatus::Active, ...Attention::RECOVERABLE]);
        $ending = (clone $domains)->whereIn('status', $statuses)->where('auto_renew', false)->whereNotNull('expires_at')
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
        $invoices = $this->unpaid ?? Attention::unpaidOf($this->client);
        // What they come to, per currency, as Attention::unpaid says it.
        $amounts = $invoices->groupBy('currency_code')->map(function ($group) {
            $cents = $group->sum(fn (Invoice $invoice) => (int) round($invoice->remaining * 100));

            return (string) new Price(['price' => $cents / 100, 'currency' => $group->first()->currency], tax: 0);
        })->implode(' + ');
        // Overdue as the rest of the code reads it (Invoice::daysOverdue: not an add-funds offer, not one whose payment is
        // on its way; review of #616).
        $overdue = $invoices->filter(fn (Invoice $invoice) => $invoice->daysOverdue() > 0)->count();
        $detail = $amounts === '' ? '' : ($overdue > 0 ? trans_choice('dashboard.summary.invoices_overdue', $overdue, ['amount' => $amounts, 'count' => $overdue]) : $amounts);

        return ['key' => 'invoices', 'count' => $invoices->count(), 'label' => trans_choice('dashboard.summary.invoices', $invoices->count()),
            'detail' => $detail, 'url' => route('invoices', ['status' => 'unpaid']), 'tone' => $overdue > 0 ? 'error' : 'primary'];
    }
}
