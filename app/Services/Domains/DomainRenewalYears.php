<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Invoice\ChangeOpenLine;
use App\Services\Order\OrderPricing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A domain's renewal period (owner 2026-10-17, docs/domains.md §6): the years it was ordered for, changed by its owner
 * (Settings → Domains: «Clients may change the renewal period») or by staff, among the years its TLD has a renew price
 * for in its currency. It counts from its next renewal: its open renewal invoice, unpaid and without a payment, changes
 * with it (period and price, ChangeOpenLine); one paid, or with money on it, keeps its period (the next one changes).
 *
 * Not while it is pending (its order registers or transfers the years paid for) or ended. In grace or redemption a
 * renewal is its TLD's shortest period whatever the period (D-13; two years for .gr): the period changes the renewals
 * after it.
 */
final class DomainRenewalYears
{
    public const UNCHANGED = 'unchanged';

    public const INVOICE = 'invoice';   // and its open renewal invoice with it

    public const NEXT = 'next';         // from its next renewal invoice

    private const CHANGEABLE = [DomainStatus::Active, DomainStatus::Expired, DomainStatus::Grace, DomainStatus::Redemption];

    /** @return list<int> the periods it may renew for: its TLD's renew prices in its currency, 1 to 10 years */
    public static function options(Domain $domain): array
    {
        $tld = $domain->domainTld?->loadMissing('prices');
        if ($tld === null) {
            return [];
        }

        return $tld->prices->where('operation', 'renew')->where('currency_code', $domain->currency_code)->whereNotNull('price')
            ->pluck('years')->map(fn ($y) => (int) $y)->filter(fn (int $y) => $y >= 1 && $y <= 10)->unique()->sort()->values()->all();
    }

    /**
     * The periods to choose from, labelled, its current one always among them (code review 1 of #182: one ordered or
     * imported for years no longer priced is still what it renews for, shown as it is, not as the first option).
     *
     * @return array<int, string>
     */
    public static function choices(Domain $domain): array
    {
        $years = self::options($domain);
        $current = max(1, (int) $domain->years);
        if (!in_array($current, $years, true)) {
            $years[] = $current;
            sort($years);
        }

        return collect($years)->mapWithKeys(fn (int $y) => [$y => trans_choice('domains.portal.years_option', $y, ['years' => $y])
            . (in_array($y, self::options($domain), true) ? '' : ' (' . __('domains.portal.years_current_unpriced') . ')')])->all();
    }

    /** Whether its period may change now (by staff: always, within options). */
    public static function changeable(Domain $domain): bool
    {
        return in_array($domain->status, self::CHANGEABLE, true) && count(self::choices($domain)) > 1;
    }

    /**
     * Whether its owner may change it: the setting on, not at a price staff set by hand (recurring_amount is for its
     * period: staff change both).
     */
    public static function clientMay(Domain $domain): bool
    {
        return (bool) config('settings.domain_client_years', true) && $domain->recurring_amount === null && self::changeable($domain);
    }

    /**
     * Set its period; its open renewal invoice with it when it can.
     *
     * @return string UNCHANGED, INVOICE or NEXT
     *
     * @throws DisplayException a period not offered, or not the client's to change
     */
    public function change(Domain $domain, int $years, bool $byStaff): string
    {
        // Under its registrar lock (adversarial ADV-1 of #182): no «Renew now» or renewal job charges its open line while
        // the line changes, and one charged already is seen below.
        $lock = Cache::lock("domain-registrar:{$domain->id}", 60);
        if (!$lock->get()) {
            throw new DisplayException(__('domains.portal.years_busy'));
        }
        try {
            return $this->changeLocked($domain, $years, $byStaff);
        } finally {
            $lock->release();
        }
    }

    private function changeLocked(Domain $domain, int $years, bool $byStaff): string
    {
        return DB::transaction(function () use ($domain, $years, $byStaff) {
            // The domain's row first, as the cron bills it (RenewalBilling: domain, then its invoice).
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            if (!($byStaff ? self::changeable($locked) : self::clientMay($locked))) {
                throw new DisplayException(__('domains.portal.years_not_changeable'));
            }
            if (max(1, (int) $locked->years) === $years) {
                return self::UNCHANGED; // its current one, priced or not
            }
            if (!in_array($years, self::options($locked), true)) {
                throw new DisplayException(__('domains.portal.years_not_offered', ['years' => $years]));
            }
            // What its open line was billed at for the old period, before it changes (code review 4).
            $billedAt = $this->catalogNet($locked);
            $locked->forceFill(['years' => $years])->save(); // audited
            $domain->setRawAttributes($locked->getAttributes(), true);

            return $this->changeOpenLine($locked, $billedAt) ? self::INVOICE : self::NEXT;
        });
    }

    /** Its renewal at the catalog price for its current period, net as the cron bills it; null when not priced. */
    private function catalogNet(Domain $domain): ?string
    {
        $price = $domain->domainTld?->loadMissing('prices')->priceFor('renew', max(1, (int) $domain->years), $domain->currency_code);

        return $price === null ? null : OrderPricing::toNet((string) $price, $domain->client);
    }

    /**
     * Its next period's line, if billed and still open (unpaid or draft, no payment), to its new period and price. Kept
     * as it is (the change counts from the next one) when it is not the catalog's: a price set by hand on the domain
     * (recurring_amount: for its period, code review 3) or on the line («Renew now» at another price, code review 4), or
     * when a later period is billed already (a longer one would overlap it, code review 6).
     *
     * @param  string|null  $billedAt  the catalog's net price of the old period
     */
    private function changeOpenLine(Domain $domain, ?string $billedAt): bool
    {
        // In grace or redemption its open renewal is the stage's period (D-13): kept. Expired is billed for its period (code review 2).
        if (!in_array($domain->status, [DomainStatus::Active, DomainStatus::Expired], true) || $domain->expires_at === null
            || $domain->recurring_amount !== null || $billedAt === null) {
            return false;
        }
        $start = $domain->expires_at->copy()->startOfDay();
        $line = DomainRenewalService::billedLine($domain, $start, payable: true);
        if ($line === null || $line->period_start === null || $line->kind !== null
            || !in_array($line->invoice?->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_DRAFT], true)
            || bccomp((string) $line->price, $billedAt, 2) !== 0) {
            return false;
        }
        // Charged at the registrar already, or maybe (no answer yet): its years are bought (adversarial ADV-1, ADV-1b).
        $charged = $line->applied_at !== null || DomainRegistrarLog::query()->where('domain_id', $domain->id)
            ->whereIn('action', ['renew', 'restore'])->whereIn('status', [DomainRegistrarLog::STATUS_OK, DomainRegistrarLog::STATUS_PENDING])
            ->where('request->line', $line->id)->exists();
        if ($charged) {
            return false;
        }
        $later = $domain->invoiceItems()->whereNull('kind')->whereKeyNot($line->id)->whereNotNull('period_start')
            ->whereDate('period_start', '>', $line->period_start->toDateString())
            ->whereHas('invoice', fn ($invoice) => $invoice->whereNotIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED]))->exists();
        $net = $this->catalogNet($domain);
        if ($later || $net === null) {
            return false;
        }
        $years = max(1, (int) $domain->years);

        return ChangeOpenLine::handle($line, function (InvoiceItem $locked) use ($domain, $years, $net) {
            $from = $locked->period_start->copy();
            $to = $from->copy()->addYearsNoOverflow($years);
            $locked->forceFill(['period_end' => $to, 'price' => $net, 'description' => $domain->describeRenewal($from, $to, $years)]);
        });
    }
}
