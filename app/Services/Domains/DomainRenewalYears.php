<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Invoice\ChangeOpenLine;
use App\Services\Order\OrderPricing;
use Illuminate\Support\Facades\DB;

/**
 * A domain's renewal period (owner 2026-10-17, docs/domains.md §6): the years it was ordered for, changed by its owner
 * (Settings → Domains: «Clients may change the renewal period») or by staff, among the years its TLD has a renew price
 * for in its currency. It counts from its next renewal: its open renewal invoice, unpaid and without a payment, changes
 * with it (period and price, ChangeOpenLine); one paid, or with money on it, keeps its period (the next one changes).
 *
 * Not while it is pending (its order registers or transfers the years paid for) or ended. In grace or redemption a
 * renewal is one year whatever the period (D-13): the period changes the renewals after it.
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

    /** Whether its period may change now (by staff: always, within options). */
    public static function changeable(Domain $domain): bool
    {
        return in_array($domain->status, self::CHANGEABLE, true) && count(self::options($domain)) > 1;
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
        return DB::transaction(function () use ($domain, $years, $byStaff) {
            // The domain's row first, as the cron bills it (RenewalBilling: domain, then its invoice).
            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            if (!($byStaff ? self::changeable($locked) : self::clientMay($locked))) {
                throw new DisplayException(__('domains.portal.years_not_changeable'));
            }
            if (!in_array($years, self::options($locked), true)) {
                throw new DisplayException(__('domains.portal.years_not_offered', ['years' => $years]));
            }
            if ((int) $locked->years === $years) {
                return self::UNCHANGED;
            }
            $locked->forceFill(['years' => $years])->save(); // audited
            $domain->setRawAttributes($locked->getAttributes(), true);

            return $this->changeOpenLine($locked) ? self::INVOICE : self::NEXT;
        });
    }

    /** Its next period's line, if billed and still open (unpaid or draft, no payment), to its new period and price. */
    private function changeOpenLine(Domain $domain): bool
    {
        // In grace or redemption its open renewal is one year (D-13): kept.
        if ($domain->status !== DomainStatus::Active || $domain->expires_at === null) {
            return false;
        }
        $start = $domain->expires_at->copy()->startOfDay();
        $line = DomainRenewalService::billedLine($domain, $start, payable: true);
        if ($line === null || $line->period_start === null || $line->kind !== null
            || !in_array($line->invoice?->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_DRAFT], true)) {
            return false;
        }
        $price = $domain->renewalPrice();
        if ($price === null) {
            return false;
        }
        $years = max(1, (int) $domain->years);
        $net = OrderPricing::toNet($price, $domain->client);

        return ChangeOpenLine::handle($line, function (InvoiceItem $locked) use ($domain, $years, $net) {
            $from = $locked->period_start->copy();
            $to = $from->copy()->addYearsNoOverflow($years);
            $locked->forceFill(['period_end' => $to, 'price' => $net, 'description' => $domain->describeRenewal($from, $to, $years)]);
        });
    }
}
