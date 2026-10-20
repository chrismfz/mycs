<?php

namespace App\Support\Portal;

use App\Classes\Price;
use App\Enums\DomainStatus;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Ticket;
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

    /** A card that ended is told for these months after. */
    public const CARD_MONTHS_AFTER = 3;

    /** A domain past its expiry but still recoverable (review of #230: the most urgent of all). */
    private const RECOVERABLE = [DomainStatus::Expired, DomainStatus::Grace, DomainStatus::Redemption];

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
        $invoices = $this->client->invoices()->where('status', Invoice::STATUS_UNPAID)->with(['transactions', 'items', 'adjustmentNotes', 'currency'])->get();
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
            'detail' => $oldest === null ? '' : __('dashboard.attention.' . ($overdue ? 'unpaid_overdue' : 'unpaid_due') . ($invoices->count() === 1 ? '_one' : ''),
                ['date' => $oldest->format('d/m/Y')]),
            'action' => __('dashboard.attention.pay'),
            'url' => $invoices->count() === 1 ? route('invoices.show', $invoices->first()) : route('invoices', ['status' => 'unpaid']),
            'urgent' => $overdue,
        ]];
    }

    /**
     * A saved card that ends this month or the next, or ended in the last months (not one forgotten years ago, review
     * of #230): automatic payments cannot use it after.
     */
    private function cards(): array
    {
        $today = CarbonImmutable::today();

        return BillingAgreement::query()->where('client_id', $this->client->id)->whereHas('gateway')->whereNotNull('expiry')
            ->whereBetween('expiry', [$today->subMonthsNoOverflow(self::CARD_MONTHS_AFTER)->startOfMonth()->toDateString(), $today->addMonthNoOverflow()->endOfMonth()->toDateString()])
            ->orderBy('expiry')->get()
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

    /**
     * Domains that do not renew (auto renew off) and end soon, or ended and can still be recovered: lost unless renewed
     * by hand.
     */
    private function domains(): array
    {
        $statuses = array_map(fn (DomainStatus $status) => $status->value, [DomainStatus::Active, ...self::RECOVERABLE]);
        $domains = $this->client->domains()->whereIn('status', $statuses)->where('auto_renew', false)
            ->whereNotNull('expires_at')->where('expires_at', '<=', CarbonImmutable::today()->addDays(self::DOMAIN_DAYS)->endOfDay())
            ->orderBy('expires_at')->get();

        return $domains->map(fn ($domain) => [
            'kind' => 'domain',
            'text' => __($domain->expires_at->isPast() ? 'dashboard.attention.domain_ended' : 'dashboard.attention.domain_ends',
                ['domain' => $domain->fqdn, 'date' => $domain->expires_at->format('d/m/Y')]),
            'detail' => __($domain->expires_at->isPast() ? 'dashboard.attention.domain_ended_detail' : 'dashboard.attention.domain_detail'),
            'action' => __('dashboard.attention.manage'),
            'url' => route('domains.show', $domain),
            'urgent' => $domain->expires_at->lt(CarbonImmutable::today()->addDays(7)),
        ])->values()->all();
    }

    /** Tickets answered by us and waiting on the customer. */
    private function tickets(): array
    {
        $replied = $this->client->tickets()->where('status', Ticket::STATUS_ANSWERED);
        $count = (clone $replied)->count();
        if ($count === 0) {
            return [];
        }
        $ticket = $replied->latest('updated_at')->first();

        return [[
            'kind' => 'tickets',
            'text' => trans_choice('dashboard.attention.tickets', $count, ['count' => $count]),
            'detail' => $count === 1 ? '#' . $ticket->reference . ' · ' . $ticket->subject : '',
            'action' => __('dashboard.attention.reply'),
            'url' => $count === 1 ? route('tickets.show', $ticket) : route('tickets'),
            'urgent' => false,
        ]];
    }
}
