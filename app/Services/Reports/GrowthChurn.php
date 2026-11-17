<?php

namespace App\Services\Reports;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Growth and churn (owner 2026-11-17, docs/growth-churn.md): month by month, the recurring income a month (MRR) that came
 * (services started, upgrades) and went (downgrades, cancellations, terminations), beside the year before. Read only; net,
 * one currency, whole cents.
 *
 * A service counts from `activated_at` until `ended_at`, at its monthly value then: price × quantity, brought to a month
 * by its plan's cycle (ClientIncome::timesAYear); free and one-time plans are worth nothing a month. Its value at a time
 * is its price, product and plan before the next upgrade applied after it (`from_*` of ServiceUpgrade), else its own now.
 * Each upgrade moves it from one of those states to the next, so a month's start, its movements and its end always add
 * up (`adjust` is 0: a test holds it). A price staff changed by hand moves with the next upgrade, or never shows.
 * Known limits: WHMCS's own upgrades are not imported (a service counts at its price after them), and a service ended
 * with no known end (`ended_at` empty: WHMCS kept none) is left out of everything, counted apart in `unknown`.
 */
final class GrowthChurn
{
    /** A loss with no cancellation request: terminated (unpaid, mostly) or cancelled by staff. */
    public const TERMINATED = 'terminated';

    public const CANCELLED_BY_STAFF = 'cancelled_by_staff';

    /** The reasons shown one by one; the rest are summed in OTHER_REASONS. */
    public const REASONS_SHOWN = 15;

    public const OTHER_REASONS = 'other';

    /**
     * @return array{year: int, currency: string, from: CarbonImmutable, until: CarbonImmutable, current: bool,
     *               mrr_now: int, mrr_year_ago: int, months: list<array<string, mixed>>, totals: array<string, int>,
     *               last_totals: array<string, int>, products: list<array<string, mixed>>,
     *               reasons: list<array{key: string, reason: string, count: int, cents: int}>,
     *               unknown: array{count: int, cents: int}, churn: float|null}
     */
    public static function year(string $currency, int $year): array
    {
        $now = CarbonImmutable::now();
        $from = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $yearEnd = $from->addYear();
        $current = $now->lessThan($yearEnd) && $now->greaterThanOrEqualTo($from);
        $until = $current ? $now : $yearEnd;

        [$lines, $unknown] = self::lines($currency);
        $firstPaid = self::firstPaid($currency);
        $domains = Domain::query()->where('currency_code', $currency)->get(['id', 'status', 'created_at', 'expires_at']);

        $months = [];
        for ($month = $from; $month->lessThan($yearEnd) && $month->lessThanOrEqualTo($until); $month = $month->addMonth()) {
            $end = $month->addMonth()->lessThan($until) ? $month->addMonth() : $until;
            $row = self::movement($lines, $month, $end);
            $last = self::movement($lines, $month->subYear(), $month->addMonth()->subYear(), false);
            $months[] = ['month' => $month, 'end' => $end] + $row + [
                'clients' => self::countBetween($firstPaid, $month, $end),
                'domains_new' => self::domainsNew($domains, $month, $end),
                'domains_lost' => self::domainsLost($domains, $month, $end),
                'last_year' => ['new' => $last['new'], 'lost' => $last['cancelled'] + $last['terminated'], 'net' => $last['net'],
                    'new_count' => $last['new_count'], 'lost_count' => $last['cancelled_count'] + $last['terminated_count'],
                    'clients' => self::countBetween($firstPaid, $month->subYear(), $month->addMonth()->subYear())],
            ];
        }

        $sum = fn (array $rows, string $key) => array_sum(array_column($rows, $key));
        $keys = ['new', 'new_count', 'up', 'up_count', 'down', 'down_count', 'cancelled', 'cancelled_count', 'terminated',
            'terminated_count', 'net', 'clients', 'domains_new', 'domains_lost'];
        $totals = [];
        foreach ($keys as $key) {
            $totals[$key] = $sum($months, $key);
        }
        // Against the same days a year before (to date in the running year).
        $lastMove = self::movement($lines, $from->subYear(), $until->subYear(), false);
        $lastTotals = $lastMove + ['clients' => self::countBetween($firstPaid, $from->subYear(), $until->subYear()),
            'domains_new' => self::domainsNew($domains, $from->subYear(), $until->subYear()),
            'domains_lost' => self::domainsLost($domains, $from->subYear(), $until->subYear())];

        // Churn a month: what was lost over what there was at the month's start, on average over the months shown.
        $rates = array_values(array_filter(array_map(fn (array $row) => $row['start'] > 0
            ? ($row['cancelled'] + $row['terminated']) / $row['start'] : null, $months), fn ($rate) => $rate !== null));

        return [
            'year' => $year, 'currency' => $currency, 'from' => $from, 'until' => $until, 'current' => $current,
            'mrr_now' => self::mrrAt($lines, $until), 'mrr_year_ago' => self::mrrAt($lines, $until->subYear()),
            'months' => $months, 'totals' => $totals, 'last_totals' => $lastTotals,
            'products' => self::byProduct($lines, $from, $until),
            'reasons' => self::reasons($lines, $from, $until),
            'unknown' => $unknown,
            'churn' => $rates === [] ? null : array_sum($rates) / count($rates),
        ];
    }

    /**
     * Each service that started, as a line: when it started and ended, why it ended, and its states, the first at its
     * start and one more after each upgrade. A state: [from (timestamp), product, monthly cents].
     *
     * @return array{0: list<array{id: int, start: int, end: int|null, kind: string|null, reason: string, request: bool, states: list<array{0: int, 1: int, 2: int}>}>, 1: array{count: int, cents: int}}
     */
    private static function lines(string $currency): array
    {
        $services = Service::query()->with(['plan', 'cancellation'])->where('currency_code', $currency)->whereNotNull('activated_at')
            ->get(['id', 'product_id', 'plan_id', 'price', 'quantity', 'status', 'activated_at', 'ended_at']);
        $upgrades = ServiceUpgrade::query()->where('status', ServiceUpgrade::STATUS_COMPLETED)->whereNotNull('applied_at')->whereNotNull('from_price')
            ->whereIn('service_id', Service::query()->select('id')->where('currency_code', $currency))
            ->orderBy('applied_at')->orderBy('id')->get(['id', 'service_id', 'from_product_id', 'from_plan_id', 'from_price', 'applied_at'])
            ->groupBy('service_id');
        $plans = Plan::query()->get()->keyBy('id');

        $lines = [];
        $unknown = ['count' => 0, 'cents' => 0];
        foreach ($services as $service) {
            $start = $service->activated_at->getTimestamp();
            $now = [(int) $service->product_id, self::monthly((string) $service->price, (int) $service->quantity, $service->plan)];
            if ($service->isEnded() && $service->ended_at === null) {
                $unknown['count']++;
                $unknown['cents'] += $now[1];

                continue;
            }
            // Never before it started (a WHMCS date earlier than its signup): it ended as it started.
            $end = $service->ended_at === null ? null : max($start, $service->ended_at->getTimestamp());
            // Applied while it ran: an upgrade at its start counts as one (it started at the price before).
            $applied = ($upgrades[$service->id] ?? collect())->filter(fn (ServiceUpgrade $upgrade) => $upgrade->applied_at->getTimestamp() >= $start
                && ($end === null || $upgrade->applied_at->getTimestamp() < $end))->values();
            // From now back: each upgrade's «before» is the state up to it.
            $states = [];
            $next = ['product' => $now[0], 'plan' => $service->plan];
            $after = $now;
            for ($i = $applied->count() - 1; $i >= 0; $i--) {
                $upgrade = $applied[$i];
                $states[] = [$upgrade->applied_at->getTimestamp(), $after[0], $after[1]];
                $plan = $upgrade->from_plan_id !== null ? ($plans[$upgrade->from_plan_id] ?? $next['plan']) : $next['plan'];
                $product = $upgrade->from_product_id !== null ? (int) $upgrade->from_product_id : $next['product'];
                $after = [$product, self::monthly((string) $upgrade->from_price, (int) $service->quantity, $plan)];
                $next = ['product' => $product, 'plan' => $plan];
            }
            $states[] = [$start, $after[0], $after[1]];

            $kind = null;
            if ($end !== null) {
                $kind = $service->cancellation !== null ? 'cancelled'
                    : ($service->status === Service::STATUS_TERMINATED ? 'terminated' : 'cancelled');
            }
            $lines[] = [
                'id' => (int) $service->id, 'start' => $start, 'end' => $end, 'kind' => $kind,
                'reason' => $service->cancellation !== null ? trim((string) $service->cancellation->reason)
                    : ($service->status === Service::STATUS_TERMINATED ? self::TERMINATED : self::CANCELLED_BY_STAFF),
                'request' => $service->cancellation !== null,
                'states' => array_reverse($states),
            ];
        }

        return [$lines, $unknown];
    }

    /** A price a month in whole cents: price × quantity × times a year / 12; 0 when it does not recur. */
    public static function monthly(string $price, int $quantity, ?Plan $plan): int
    {
        $times = ClientIncome::timesAYear($plan?->billing_unit, (int) $plan?->billing_period, $plan?->type);
        if ($times === null) {
            return 0;
        }
        $amount = BigDecimal::of(number_format((float) $price, 2, '.', ''))->multipliedBy(max(0, $quantity))
            ->multipliedBy($times[0])->dividedBy($times[1] * 12, 6, RoundingMode::HalfUp);

        return ClientIncome::toCents($amount);
    }

    /**
     * The state a line is in at a time: the last that began at or before it.
     *
     * @param  array{states: list<array{0: int, 1: int, 2: int}>}  $line
     * @return array{0: int, 1: int, 2: int}
     */
    private static function stateAt(array $line, int $at): array
    {
        $state = $line['states'][0];
        foreach ($line['states'] as $candidate) {
            if ($candidate[0] > $at) {
                break;
            }
            $state = $candidate;
        }

        return $state;
    }

    /**
     * What it brought in a month at a time: the lines started before it and not ended by it.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public static function mrrAt(array $lines, CarbonImmutable $at): int
    {
        $t = $at->getTimestamp();
        $sum = 0;
        foreach ($lines as $line) {
            if ($line['start'] < $t && ($line['end'] === null || $line['end'] >= $t)) {
                // Its state just before the time: what ran up to it.
                $sum += self::stateAt($line, $t - 1)[2];
            }
        }

        return $sum;
    }

    /**
     * What came and went in [from, until): started, moved up or down, ended (with a request or none). With $close, the
     * month's MRR at its start and end and what does not add up between them (0).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, int>
     */
    private static function movement(array $lines, CarbonImmutable $from, CarbonImmutable $until, bool $close = true): array
    {
        [$a, $b] = [$from->getTimestamp(), $until->getTimestamp()];
        $out = array_fill_keys(['new', 'new_count', 'up', 'up_count', 'down', 'down_count', 'cancelled', 'cancelled_count',
            'terminated', 'terminated_count'], 0);
        foreach ($lines as $line) {
            if ($line['start'] >= $a && $line['start'] < $b && ($value = $line['states'][0][2]) > 0) {
                $out['new'] += $value;
                $out['new_count']++;
            }
            for ($i = 1; $i < count($line['states']); $i++) {
                if ($line['states'][$i][0] >= $a && $line['states'][$i][0] < $b) {
                    $delta = $line['states'][$i][2] - $line['states'][$i - 1][2];
                    $side = $delta > 0 ? 'up' : 'down';
                    if ($delta !== 0) {
                        $out[$side] += $delta;
                        $out[$side . '_count']++;
                    }
                }
            }
            if ($line['end'] !== null && $line['end'] >= $a && $line['end'] < $b && ($value = $line['states'][count($line['states']) - 1][2]) > 0) {
                $out[$line['kind']] += $value;
                $out[$line['kind'] . '_count']++;
            }
        }
        $out['net'] = $out['new'] + $out['up'] + $out['down'] - $out['cancelled'] - $out['terminated'];
        if ($close) {
            $out['start'] = self::mrrAt($lines, $from);
            $out['end_mrr'] = self::mrrAt($lines, $until);
            $out['adjust'] = $out['end_mrr'] - $out['start'] - $out['net'];
        }

        return $out;
    }

    /**
     * By product, in [from, until): what its services brought when they started, what upgrades moved in or out (a change
     * of product takes its old value off the old one and puts its new value on the new one), what it lost.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{product_id: int, name: string, category: string, new: int, moved: int, lost: int, net: int, now: int}>
     */
    private static function byProduct(array $lines, CarbonImmutable $from, CarbonImmutable $until): array
    {
        [$a, $b] = [$from->getTimestamp(), $until->getTimestamp()];
        $cells = [];
        $add = function (int $product, string $column, int $cents) use (&$cells) {
            $cells[$product] ??= ['new' => 0, 'moved' => 0, 'lost' => 0, 'now' => 0];
            $cells[$product][$column] += $cents;
        };
        foreach ($lines as $line) {
            $states = $line['states'];
            if ($line['start'] >= $a && $line['start'] < $b) {
                $add($states[0][1], 'new', $states[0][2]);
            }
            for ($i = 1; $i < count($states); $i++) {
                if ($states[$i][0] >= $a && $states[$i][0] < $b) {
                    $add($states[$i - 1][1], 'moved', -$states[$i - 1][2]);
                    $add($states[$i][1], 'moved', $states[$i][2]);
                }
            }
            $last = $states[count($states) - 1];
            if ($line['end'] !== null && $line['end'] >= $a && $line['end'] < $b) {
                $add($last[1], 'lost', $last[2]);
            }
            if ($line['start'] < $b && ($line['end'] === null || $line['end'] >= $b)) {
                $add(self::stateAt($line, $b - 1)[1], 'now', self::stateAt($line, $b - 1)[2]);
            }
        }
        $products = Product::query()->with('category')->whereKey(array_keys($cells))->get()->keyBy('id');
        $rows = [];
        foreach ($cells as $id => $cell) {
            $net = $cell['new'] + $cell['moved'] - $cell['lost'];
            if ($cell['new'] === 0 && $cell['moved'] === 0 && $cell['lost'] === 0) {
                continue; // nothing came or went
            }
            $rows[] = ['product_id' => $id, 'name' => (string) ($products[$id]->name ?? '#' . $id),
                'category' => (string) ($products[$id]->category->name ?? ''), 'net' => $net] + $cell;
        }
        usort($rows, fn ($x, $y) => [$y['net'], $x['name']] <=> [$x['net'], $y['name']]);

        return $rows;
    }

    /**
     * Why they left, in [from, until): the cancellation requests' own words (the same words, whatever their case or
     * spacing, together), the terminations and staff's cancellations with no request apart; the largest first.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{key: string, reason: string, count: int, cents: int}>
     */
    private static function reasons(array $lines, CarbonImmutable $from, CarbonImmutable $until): array
    {
        [$a, $b] = [$from->getTimestamp(), $until->getTimestamp()];
        $groups = [];
        foreach ($lines as $line) {
            if ($line['end'] === null || $line['end'] < $a || $line['end'] >= $b) {
                continue;
            }
            $text = preg_replace('/\s+/u', ' ', (string) $line['reason']) ?? '';
            $key = $line['request'] ? 'text:' . mb_strtolower($text) : $text;
            $groups[$key] ??= ['key' => $key, 'reason' => $text, 'count' => 0, 'cents' => 0];
            $groups[$key]['count']++;
            $groups[$key]['cents'] += $line['states'][count($line['states']) - 1][2];
        }
        $rows = array_values($groups);
        usort($rows, fn ($x, $y) => [$y['cents'], $y['count'], $x['reason']] <=> [$x['cents'], $x['count'], $y['reason']]);
        if (count($rows) > self::REASONS_SHOWN) {
            $rest = array_slice($rows, self::REASONS_SHOWN);
            $rows = array_slice($rows, 0, self::REASONS_SHOWN);
            $rows[] = ['key' => self::OTHER_REASONS, 'reason' => self::OTHER_REASONS,
                'count' => array_sum(array_column($rest, 'count')), 'cents' => array_sum(array_column($rest, 'cents'))];
        }

        return $rows;
    }

    /**
     * Each customer's first payment, when it was in this currency (as ClientIncome's «new»: an imported invoice with no
     * payment date was paid before anything, so its customer is never new).
     *
     * @return list<int> timestamps
     */
    private static function firstPaid(string $currency): array
    {
        $first = [];
        $never = [];
        foreach (Invoice::query()->where('status', Invoice::STATUS_PAID)->whereNotNull('client_id')
            ->orderBy('paid_at')->orderBy('id')->toBase()->get(['client_id', 'paid_at', 'currency_code']) as $row) {
            $client = (int) $row->client_id;
            if ($row->paid_at === null) {
                $never[$client] = true;
            } elseif (!isset($first[$client])) {
                $first[$client] = [CarbonImmutable::parse($row->paid_at)->getTimestamp(), (string) $row->currency_code];
            }
        }

        return array_values(array_map(fn (array $paid) => $paid[0],
            array_filter(array_diff_key($first, $never), fn (array $paid) => $paid[1] === $currency)));
    }

    /** @param  list<int>  $times */
    private static function countBetween(array $times, CarbonImmutable $from, CarbonImmutable $until): int
    {
        [$a, $b] = [$from->getTimestamp(), $until->getTimestamp()];

        return count(array_filter($times, fn (int $t) => $t >= $a && $t < $b));
    }

    /**
     * Domains added in [from, until) (as Income by product's «new»).
     *
     * @param  Collection<int, Domain>  $domains
     */
    private static function domainsNew(Collection $domains, CarbonImmutable $from, CarbonImmutable $until): int
    {
        return $domains->filter(fn (Domain $domain) => $domain->created_at !== null && $domain->created_at >= $from && $domain->created_at < $until)->count();
    }

    /**
     * Domains gone for good (transferred away, cancelled, deleted) with their expiry in [from, until), as Income by
     * product's «ended».
     *
     * @param  Collection<int, Domain>  $domains
     */
    private static function domainsLost(Collection $domains, CarbonImmutable $from, CarbonImmutable $until): int
    {
        $gone = [DomainStatus::TransferredAway, DomainStatus::Cancelled, DomainStatus::Deleted];

        return $domains->filter(fn (Domain $domain) => in_array($domain->status, $gone, true) && $domain->expires_at !== null
            && $domain->expires_at >= $from && $domain->expires_at < $until)->count();
    }
}
