<?php

namespace App\Support\Portal;

use App\Classes\Price;
use App\Enums\DomainStatus;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\CurrentClient;
use Carbon\CarbonImmutable;

/**
 * «Needs your attention» on the home page (P4b, owner 2026-10-19): what waits on the customer, in one place, instead of
 * counted on four pages. Each thing only to who may see its area (a member's grant).
 */
final class Attention
{
    /** Domains that end within these days without renewing are told. */
    public const DOMAIN_DAYS = 30;

    public function __construct(private readonly Client $client) {}

    /**
     * @return list<array{kind: string, text: string, detail: string, action: string, url: string, urgent: bool}>
     */
    public function items(): array
    {
        $items = [];
        if (CurrentClient::allows('invoices')) {
            array_push($items, ...$this->unpaid(), ...$this->cards());
        }
        if (CurrentClient::allows('domains')) {
            array_push($items, ...$this->domains());
        }
        if (!config('settings.tickets_disabled', false) && CurrentClient::allows('tickets')) {
            array_push($items, ...$this->tickets());
        }

        return $items;
    }

    /** Unpaid invoices: how many, what they come to (per currency), the oldest due date. */
    private function unpaid(): array
    {
        $invoices = $this->client->invoices()->where('status', Invoice::STATUS_UNPAID)->with(['transactions', 'items', 'currency'])->orderBy('due_at')->get();
        if ($invoices->isEmpty()) {
            return [];
        }
        $amounts = $invoices->groupBy('currency_code')->map(function ($group) {
            $cents = $group->sum(fn (Invoice $invoice) => (int) round($invoice->remaining * 100));

            return (string) new Price(['price' => $cents / 100, 'currency' => $group->first()->currency], tax: 0);
        })->implode(' + ');
        // The oldest due date among those that have one: one without must not hide an overdue one (review of #230).
        $oldest = $invoices->whereNotNull('due_at')->sortBy('due_at')->first()?->due_at;
        $overdue = $oldest !== null && $oldest->lt(CarbonImmutable::today());

        return [[
            'kind' => 'invoices',
            'text' => trans_choice('dashboard.attention.unpaid', $invoices->count(), ['count' => $invoices->count(), 'amount' => $amounts]),
            'detail' => $oldest === null ? '' : __($overdue ? 'dashboard.attention.unpaid_overdue' : 'dashboard.attention.unpaid_due', ['date' => $oldest->format('d/m/Y')]),
            'action' => __('dashboard.attention.pay'),
            'url' => $invoices->count() === 1 ? route('invoices.show', $invoices->first()) : route('invoices', ['status' => 'unpaid']),
            'urgent' => $overdue,
        ]];
    }

    /** A saved card that has expired or ends this month or the next: automatic payments stop with it. */
    private function cards(): array
    {
        $until = CarbonImmutable::today()->addMonthNoOverflow()->endOfMonth();

        return BillingAgreement::query()->where('client_id', $this->client->id)->whereHas('gateway')
            ->whereNotNull('expiry')->where('expiry', '<=', $until->toDateString())->orderBy('expiry')->get()
            ->map(function (BillingAgreement $card) {
                $expiry = CarbonImmutable::parse($card->expiry);
                $expired = $expiry->lt(CarbonImmutable::today());

                return [
                    'kind' => 'card',
                    'text' => __($expired ? 'dashboard.attention.card_expired' : 'dashboard.attention.card_expires', ['name' => $card->name, 'date' => $expiry->format('m/Y')]),
                    'detail' => __('dashboard.attention.card_detail'),
                    'action' => __('dashboard.attention.update_card'),
                    'url' => route('account.payment-methods'),
                    'urgent' => $expired,
                ];
            })->values()->all();
    }

    /** Active domains that end soon and do not renew (auto renew off): lost unless renewed by hand. */
    private function domains(): array
    {
        $domains = $this->client->domains()->where('status', DomainStatus::Active->value)->where('auto_renew', false)
            ->whereNotNull('expires_at')->where('expires_at', '<=', CarbonImmutable::today()->addDays(self::DOMAIN_DAYS)->endOfDay())
            ->orderBy('expires_at')->get();

        return $domains->map(fn ($domain) => [
            'kind' => 'domain',
            'text' => __('dashboard.attention.domain_ends', ['domain' => $domain->fqdn, 'date' => $domain->expires_at->format('d/m/Y')]),
            'detail' => __('dashboard.attention.domain_detail'),
            'action' => __('dashboard.attention.manage'),
            'url' => route('domains.show', $domain),
            'urgent' => $domain->expires_at->lt(CarbonImmutable::today()->addDays(7)),
        ])->values()->all();
    }

    /** Tickets answered by us and waiting on the customer. */
    private function tickets(): array
    {
        $tickets = $this->client->tickets()->where('status', 'replied')->latest('updated_at')->get();
        if ($tickets->isEmpty()) {
            return [];
        }

        return [[
            'kind' => 'tickets',
            'text' => trans_choice('dashboard.attention.tickets', $tickets->count(), ['count' => $tickets->count()]),
            'detail' => $tickets->count() === 1 ? '#' . $tickets->first()->id . ' · ' . $tickets->first()->subject : '',
            'action' => __('dashboard.attention.reply'),
            'url' => $tickets->count() === 1 ? route('tickets.show', $tickets->first()) : route('tickets'),
            'urgent' => false,
        ]];
    }
}
