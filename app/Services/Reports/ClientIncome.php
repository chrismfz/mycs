<?php

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Billing\RenewalBilling;
use App\Services\Order\OrderPricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Income by customer (owner 2026-10-28, the approved mockup; docs/income-planning.md): who brings in how much in a
 * year, against the same period a year before (this year: the same days, as IncomePlan::yearToDate; a year gone: the
 * whole year before), how much the largest weigh, who is new and who stopped paying, and what each one's active
 * services and domains bring in a year if renewed. By the reports' rules: the invoices paid in the period (paid_at),
 * net (RevenueForecast::netByInvoice), one currency; the total is the Income & forecast page's.
 */
final class ClientIncome
{
    /** The share of a top-50 customer's fall that flags it (owner: «έπεσε» over 30%). */
    public const FALL_PERCENT = 30;

    /**
     * @return array{from: CarbonImmutable, until: CarbonImmutable, last_from: CarbonImmutable, last_until: CarbonImmutable, to_date: bool,
     *               rows: list<array{client_id: int, received: int, last_year: int, services: int, annual: int, new: bool, fallen: bool}>,
     *               unassigned: array{received: int, last_year: int}, total: int, last_year_total: int, paid: int, positive: int, top10: int, top50: int,
     *               new: array{count: int, amount: int}, lost: array{count: int, amount: int}}
     */
    public static function year(string $currency, int $year, ?int $group = null): array
    {
        $now = CarbonImmutable::now();
        $from = CarbonImmutable::create($year, 1, 1)->startOfDay();
        // This year: to the end of today, against the same days a year before (IncomePlan::yearToDate); else whole years.
        $toDate = $year === (int) $now->format('Y');
        $until = $toDate ? $now->addDay()->startOfDay() : $from->addYear();
        $lastUntil = $toDate ? $now->subYearNoOverflow()->addDay()->startOfDay() : $from;

        $clients = $group === null ? null : Client::query()->where('client_group_id', $group)->pluck('id')->all();
        $received = self::byClient($currency, $from, $until, $clients);
        $lastYear = self::byClient($currency, $from->subYear(), $lastUntil, $clients);
        // Invoices of no client (deleted: client_id null on delete; an import's): apart, in the totals (ADV-503-1).
        $unassigned = ['received' => $received[0] ?? 0, 'last_year' => $lastYear[0] ?? 0];
        unset($received[0], $lastYear[0]);
        // Paid anything before the year: not new.
        $before = self::paidBefore($currency, $from, array_keys($received));
        [$services, $annual] = self::renewing($currency, $clients);

        $ids = array_keys($received + $lastYear);
        $rows = [];
        foreach ($ids as $id) {
            $rows[] = ['client_id' => $id, 'received' => $received[$id] ?? 0, 'last_year' => $lastYear[$id] ?? 0,
                'services' => $services[$id] ?? 0, 'annual' => $annual[$id] ?? 0,
                'new' => ($received[$id] ?? 0) > 0 && !isset($before[$id]), 'fallen' => false];
        }
        usort($rows, fn ($a, $b) => [$b['received'], $b['last_year'], $a['client_id']] <=> [$a['received'], $a['last_year'], $b['client_id']]);
        foreach (array_slice($rows, 0, 50) as $i => $row) {
            // A top-50 customer down over FALL_PERCENT, in whole cents (no float).
            // One who paid nothing is lost, not fallen (its own card), whatever the number of clients (review of #503).
            $rows[$i]['fallen'] = $row['received'] > 0 && $row['last_year'] > 0
                && ($row['last_year'] - $row['received']) * 100 > $row['last_year'] * self::FALL_PERCENT;
        }

        $amounts = array_column($rows, 'received');
        // The weight of the largest out of what the clients brought in: one netting below zero (a refund, a credit) never
        // makes them weigh more than all (ADV-503-7).
        $positive = array_map(fn (int $amount) => max(0, $amount), $amounts);
        $new = array_filter($rows, fn ($row) => $row['new']);
        $lost = array_filter($rows, fn ($row) => $row['received'] <= 0 && $row['last_year'] > 0);

        return [
            'from' => $from, 'until' => $until, 'last_from' => $from->subYear(), 'last_until' => $lastUntil, 'to_date' => $toDate,
            'rows' => $rows,
            'unassigned' => $unassigned,
            'total' => array_sum($amounts) + $unassigned['received'],
            'last_year_total' => array_sum(array_column($rows, 'last_year')) + $unassigned['last_year'],
            'paid' => count(array_filter($amounts, fn ($amount) => $amount > 0)),
            'positive' => array_sum($positive),
            'top10' => array_sum(array_slice($positive, 0, 10)),
            'top50' => array_sum(array_slice($positive, 0, 50)),
            'new' => ['count' => count($new), 'amount' => array_sum(array_column($new, 'received'))],
            'lost' => ['count' => count($lost), 'amount' => array_sum(array_column($lost, 'last_year'))],
        ];
    }

    /**
     * What each customer's invoices paid in [from, until) brought in, net, in cents (zero ones left out; 0: no client).
     *
     * @param  list<int>|null  $clients  only these (a group), null: all
     * @return array<int, int>
     */
    private static function byClient(string $currency, CarbonImmutable $from, CarbonImmutable $until, ?array $clients): array
    {
        $query = fn () => Invoice::query()->where('status', Invoice::STATUS_PAID)->where('currency_code', $currency)
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $until)
            ->when($clients !== null, fn ($query) => $query->whereIn('client_id', $clients ?: [0]));
        $owners = $query()->pluck('client_id', 'id');
        $out = [];
        foreach (RevenueForecast::netByInvoice($query()) as $id => $cents) {
            $client = (int) $owners[$id]; // 0: no client
            $out[$client] = ($out[$client] ?? 0) + $cents;
        }

        return array_filter($out, fn (int $cents) => $cents !== 0);
    }

    /**
     * Of these customers, those with a paid invoice before $from, in any currency, or with no payment date (an import:
     * paid at some time before; ADV-503-2, ADV-503-3): not new in the year.
     *
     * @param  list<int>  $clients
     * @return array<int, true>
     */
    private static function paidBefore(string $currency, CarbonImmutable $from, array $clients): array
    {
        if ($clients === []) {
            return [];
        }

        return array_fill_keys(Invoice::query()->where('status', Invoice::STATUS_PAID)
            ->whereIn('client_id', $clients)->where(fn ($query) => $query->where('paid_at', '<', $from)->orWhereNull('paid_at'))->distinct()->pluck('client_id')->map(fn ($id) => (int) $id)->all(), true);
    }

    /**
     * Each customer's active services (count) and what they and its auto-renewing domains bring in a year if renewed, in
     * cents: a service's price × quantity × 12 / its cycle's months (as RevenueForecast::annualEstimate), a domain's net
     * renewal price over its years (as RevenueForecast::renewalCells bills it).
     *
     * @param  list<int>|null  $clients
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private static function renewing(string $currency, ?array $clients): array
    {
        $count = [];
        $exact = [];
        // As the billing renews them: not to be cancelled (ADV-503-4).
        $services = Service::query()->with('plan')->where('status', Service::STATUS_ACTIVE)->whereDoesntHave('cancellation')->where('currency_code', $currency)
            ->when($clients !== null, fn ($query) => $query->whereIn('client_id', $clients ?: [0]))->get();
        foreach ($services as $service) {
            $id = (int) $service->client_id;
            $count[$id] = ($count[$id] ?? 0) + 1;
            $times = self::timesAYear($service->plan?->billing_unit, (int) $service->plan?->billing_period, $service->plan?->type);
            if ($times === null) {
                continue; // free, one time: nothing a year
            }
            // The quantity as the billing puts it on the line: 0 bills nothing (ADV-503-5).
            $amount = BigDecimal::of(number_format((float) $service->price, 2, '.', ''))->multipliedBy(max(0, (int) $service->quantity));
            $exact[$id] = ($exact[$id] ?? BigDecimal::zero())->plus($amount->multipliedBy($times[0])->dividedBy($times[1], 6, RoundingMode::HalfUp));
        }

        $domains = Domain::query()->with(['client.primaryContact', 'domainTld.prices']) // toNet reads the client's tax (review of #503: N+1)
            ->whereIn('status', array_map(fn ($status) => $status->value, RenewalBilling::BILLED_DOMAINS))
            ->where('currency_code', $currency)->whereNotNull('expires_at')->where('auto_renew', true)
            ->when($clients !== null, fn ($query) => $query->whereIn('client_id', $clients ?: [0]))->get();
        foreach ($domains as $domain) {
            $years = max(1, $domain->nextRenewalYears());
            $price = $domain->renewalPriceFor($years);
            if ($price === null) {
                continue;
            }
            $id = (int) $domain->client_id;
            $net = OrderPricing::toNet(number_format((float) $price, 2, '.', ''), $domain->client, null);
            $exact[$id] = ($exact[$id] ?? BigDecimal::zero())->plus(BigDecimal::of((string) $net)->dividedBy($years, 6, RoundingMode::HalfUp));
        }

        return [$count, array_map(fn (BigDecimal $amount) => $amount->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt(), $exact)];
    }

    /**
     * How many times a year a plan renews, as a fraction [per, every]: by the month or year (RevenueForecast::months),
     * and by the week (52) or day (365), which the catalogue offers too (ADV-503-6). Null: it does not recur.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function timesAYear(?string $unit, int $period, ?string $type): ?array
    {
        if ($type !== 'recurring' || $period < 1) {
            return null;
        }

        return match ($unit) {
            'day' => [365, $period],
            'week' => [52, $period],
            default => ($months = RevenueForecast::months($unit, $period, $type)) === null ? null : [12, $months],
        };
    }
}
