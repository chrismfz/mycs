<?php

namespace App\Services\Reports;

use App\Enums\DomainStatus;
use App\Models\Credit;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Order\OrderPricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What is coming in (ROADMAP 6, owner 2026-10-20: «how much we expect this month from renewals, and what we made last
 * year the same period, without taxes»; the tax figures are Ekdosi's). As WHMCS's Income Forecast: every active service
 * and domain renews at its price on its next due date, then every cycle after, within the months shown; by cycle. Beside
 * it, the same month a year before: what the invoices paid then billed, net. All amounts net (Δ1), in one currency.
 */
final class RevenueForecast
{
    /** The cycles WHMCS shows, in months; anything else is «other». */
    public const CYCLES = [1, 3, 6, 12, 24, 36];

    /**
     * @return array{months: list<array{month: CarbonImmutable, cycles: array<int|string, string>, domains: string, total: string, last_year: string}>,
     *               total: string, last_year: string, current: array{expected: string, invoiced: string, collected: string}}
     */
    public static function for(string $currency, ?CarbonImmutable $from = null, int $months = 12): array
    {
        $from = ($from ?? CarbonImmutable::now())->startOfMonth();
        $until = $from->addMonths($months);
        $cells = []; // 'Y-m' => ['cycles' => [months => cents], 'domains' => cents]

        $services = Service::query()->with(['plan', 'client'])->where('status', Service::STATUS_ACTIVE)
            ->where('currency_code', $currency)->whereNotNull('expires_at')->get();
        foreach ($services as $service) {
            $every = self::months($service->plan?->billing_unit, (int) $service->plan?->billing_period, $service->plan?->type);
            if ($every === null) {
                continue; // free, one time, no plan
            }
            $amount = self::cents(BigDecimal::of(number_format((float) $service->price, 2, '.', ''))->multipliedBy(max(1, (int) $service->quantity)));
            $column = in_array($every, self::CYCLES, true) ? $every : 'other';
            foreach (self::dates(CarbonImmutable::instance($service->expires_at), $every, $from, $until) as $key) {
                $cells[$key]['cycles'][$column] = ($cells[$key]['cycles'][$column] ?? 0) + $amount;
            }
        }

        $domains = Domain::query()->with('client')->where('status', DomainStatus::Active->value)->where('currency_code', $currency)
            ->whereNotNull('expires_at')->where('auto_renew', true)->get();
        foreach ($domains as $domain) {
            $every = 12 * max(1, (int) $domain->years);
            // Its renewal as a line bills it: the catalog amount, net of the account's tax (OrderPricing::toNet).
            $amount = self::cents(OrderPricing::toNet(number_format((float) $domain->recurring_amount, 2, '.', ''), $domain->client, null));
            foreach (self::dates(CarbonImmutable::instance($domain->expires_at), $every, $from, $until) as $key) {
                $cells[$key]['domains'] = ($cells[$key]['domains'] ?? 0) + $amount;
            }
        }

        $rows = [];
        $total = 0;
        $lastYearTotal = 0;
        for ($month = $from; $month->lessThan($until); $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $cycles = [];
            foreach ([...self::CYCLES, 'other'] as $column) {
                $cycles[$column] = self::money($cells[$key]['cycles'][$column] ?? 0);
            }
            $sum = array_sum($cells[$key]['cycles'] ?? []) + ($cells[$key]['domains'] ?? 0);
            $lastYear = self::paidNet($currency, $month->subYear(), $month->subYear()->addMonth());
            $total += $sum;
            $lastYearTotal += $lastYear;
            $rows[] = ['month' => $month, 'cycles' => $cycles, 'domains' => self::money($cells[$key]['domains'] ?? 0),
                'total' => self::money($sum), 'last_year' => self::money($lastYear)];
        }

        $now = CarbonImmutable::now()->startOfMonth();

        return [
            'months' => $rows,
            'total' => self::money($total),
            'last_year' => self::money($lastYearTotal),
            'current' => [
                'expected' => $rows[0]['total'] ?? '0.00',
                'invoiced' => self::money(self::invoicedNet($currency, $now, $now->addMonth())),
                'collected' => self::money(self::paidNet($currency, $now, $now->addMonth())),
            ],
        ];
    }

    /**
     * The estimated annual income of the active services, by cycle (WHMCS's billing widget: monthly × 12 + quarterly × 4
     * + semi-annually × 2 + annually + biennially / 2 + triennially / 3), net.
     *
     * @return array<string, array{cycles: array<int|string, array{amount: string, count: int}>, annual: string}> by currency
     */
    public static function annualEstimate(): array
    {
        $out = [];
        foreach (Service::query()->with('plan')->where('status', Service::STATUS_ACTIVE)->get() as $service) {
            $every = self::months($service->plan?->billing_unit, (int) $service->plan?->billing_period, $service->plan?->type);
            if ($every === null) {
                continue;
            }
            $currency = (string) $service->currency_code;
            $column = in_array($every, self::CYCLES, true) ? $every : 'other';
            $amount = self::cents(BigDecimal::of(number_format((float) $service->price, 2, '.', ''))->multipliedBy(max(1, (int) $service->quantity)));
            $out[$currency]['cycles'][$column]['amount'] = ($out[$currency]['cycles'][$column]['amount'] ?? 0) + $amount;
            $out[$currency]['cycles'][$column]['count'] = ($out[$currency]['cycles'][$column]['count'] ?? 0) + 1;
            // A year of it: its amount × 12 / its months, in cents, rounded once at the end.
            $out[$currency]['annual_exact'] = ($out[$currency]['annual_exact'] ?? BigDecimal::zero())
                ->plus(BigDecimal::of($amount)->multipliedBy(12)->dividedBy($every, 6, RoundingMode::HalfUp));
        }
        $result = [];
        foreach ($out as $currency => $data) {
            $cycles = [];
            foreach ([...self::CYCLES, 'other'] as $column) {
                $cycles[$column] = ['amount' => self::money($data['cycles'][$column]['amount'] ?? 0), 'count' => (int) ($data['cycles'][$column]['count'] ?? 0)];
            }
            $result[$currency] = ['cycles' => $cycles,
                'annual' => (string) $data['annual_exact']->dividedBy(100, 2, RoundingMode::HalfUp)];
        }
        ksort($result);

        return $result;
    }

    /** A plan's cycle in months; null when it does not recur (free, one time) or is not in months or years. */
    public static function months(?string $unit, int $period, ?string $type = 'recurring'): ?int
    {
        if ($type !== 'recurring' || $period < 1) {
            return null;
        }

        return match ($unit) {
            'month' => $period,
            'year' => 12 * $period,
            default => null,
        };
    }

    /** @return list<string> the months ('Y-m') it falls due in, from its next due date every $every months */
    private static function dates(CarbonImmutable $due, int $every, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $keys = [];
        // Overdue (its due date before the window): its renewals from the first one inside it, as WHMCS counts on.
        for ($month = $due->startOfMonth(); $month->lessThan($until); $month = $month->addMonths($every)) {
            if ($month->greaterThanOrEqualTo($from)) {
                $keys[] = $month->format('Y-m');
            }
        }

        return $keys;
    }

    /** What the invoices paid in [from, until) billed, net (their lines without tax), in cents. */
    private static function paidNet(string $currency, CarbonImmutable $from, CarbonImmutable $until): int
    {
        return self::netOf(Invoice::query()->where('status', Invoice::STATUS_PAID)->where('currency_code', $currency)
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $until));
    }

    /** What the invoices made in [from, until) bill, net, but drafts and cancelled ones, in cents. */
    private static function invoicedNet(string $currency, CarbonImmutable $from, CarbonImmutable $until): int
    {
        return self::netOf(Invoice::query()->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID])->where('currency_code', $currency)
            ->where('created_at', '>=', $from)->where('created_at', '<', $until));
    }

    /** @param  Builder<Invoice>  $invoices */
    private static function netOf($invoices): int
    {
        // A credit purchase (add funds) is not income: the credit is, when it pays an invoice (counted there).
        $sum = DB::table('invoice_items')->whereIn('invoice_id', $invoices->select('id'))
            ->where(fn ($query) => $query->whereNull('reference_type')->orWhere('reference_type', '!=', Credit::class))
            ->selectRaw('COALESCE(SUM(line_total - tax_amount), 0) AS net')->value('net');

        return self::cents((string) $sum);
    }

    private static function cents(BigDecimal|string $amount): int
    {
        return BigDecimal::of((string) $amount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    private static function money(int $cents): string
    {
        return (string) BigDecimal::of($cents)->dividedBy(100, 2);
    }
}
