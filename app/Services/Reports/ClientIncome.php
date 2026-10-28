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
     *               total: int, last_year_total: int, paid: int, top10: int, top50: int, new: array{count: int, amount: int}, lost: array{count: int, amount: int}}
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
            $rows[$i]['fallen'] = $row['last_year'] > 0 && ($row['last_year'] - $row['received']) * 100 > $row['last_year'] * self::FALL_PERCENT;
        }

        $amounts = array_column($rows, 'received');
        $new = array_filter($rows, fn ($row) => $row['new']);
        $lost = array_filter($rows, fn ($row) => $row['received'] <= 0 && $row['last_year'] > 0);

        return [
            'from' => $from, 'until' => $until, 'last_from' => $from->subYear(), 'last_until' => $lastUntil, 'to_date' => $toDate,
            'rows' => $rows,
            'total' => array_sum($amounts),
            'last_year_total' => array_sum(array_column($rows, 'last_year')),
            'paid' => count(array_filter($amounts, fn ($amount) => $amount > 0)),
            'top10' => array_sum(array_slice($amounts, 0, 10)),
            'top50' => array_sum(array_slice($amounts, 0, 50)),
            'new' => ['count' => count($new), 'amount' => array_sum(array_column($new, 'received'))],
            'lost' => ['count' => count($lost), 'amount' => array_sum(array_column($lost, 'last_year'))],
        ];
    }

    /**
     * What each customer's invoices paid in [from, until) brought in, net, in cents (zero ones left out).
     *
     * @param  list<int>|null  $clients  only these (a group), null: all
     * @return array<int, int>
     */
    private static function byClient(string $currency, CarbonImmutable $from, CarbonImmutable $until, ?array $clients): array
    {
        $query = fn () => Invoice::query()->where('status', Invoice::STATUS_PAID)->where('currency_code', $currency)
            ->whereNotNull('client_id')->where('paid_at', '>=', $from)->where('paid_at', '<', $until)
            ->when($clients !== null, fn ($query) => $query->whereIn('client_id', $clients ?: [0]));
        $owners = $query()->pluck('client_id', 'id');
        $out = [];
        foreach (RevenueForecast::netByInvoice($query()) as $id => $cents) {
            $client = (int) $owners[$id];
            $out[$client] = ($out[$client] ?? 0) + $cents;
        }

        return array_filter($out, fn (int $cents) => $cents !== 0);
    }

    /**
     * Of these customers, those with a paid invoice before $from (in this currency): not new in the year.
     *
     * @param  list<int>  $clients
     * @return array<int, true>
     */
    private static function paidBefore(string $currency, CarbonImmutable $from, array $clients): array
    {
        if ($clients === []) {
            return [];
        }

        return array_fill_keys(Invoice::query()->where('status', Invoice::STATUS_PAID)->where('currency_code', $currency)
            ->whereIn('client_id', $clients)->where('paid_at', '<', $from)->distinct()->pluck('client_id')->map(fn ($id) => (int) $id)->all(), true);
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
        $services = Service::query()->with('plan')->where('status', Service::STATUS_ACTIVE)->where('currency_code', $currency)
            ->when($clients !== null, fn ($query) => $query->whereIn('client_id', $clients ?: [0]))->get();
        foreach ($services as $service) {
            $id = (int) $service->client_id;
            $count[$id] = ($count[$id] ?? 0) + 1;
            $every = RevenueForecast::months($service->plan?->billing_unit, (int) $service->plan?->billing_period, $service->plan?->type);
            if ($every === null) {
                continue; // free, one time: nothing a year
            }
            $amount = BigDecimal::of(number_format((float) $service->price, 2, '.', ''))->multipliedBy(max(1, (int) $service->quantity));
            $exact[$id] = ($exact[$id] ?? BigDecimal::zero())->plus($amount->multipliedBy(12)->dividedBy($every, 6, RoundingMode::HalfUp));
        }

        $domains = Domain::query()->with(['client', 'domainTld.prices'])
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
}
