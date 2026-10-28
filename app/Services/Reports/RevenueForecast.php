<?php

namespace App\Services\Reports;

use App\Enums\AdjustmentNoteStatus;
use App\Models\Credit;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Billing\RenewalBilling;
use App\Services\Billing\RenewalPrice;
use App\Services\Domains\DomainRenewalService;
use App\Services\Order\OrderPricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What is coming in (ROADMAP 6, owner 2026-10-20: «how much we expect this month from renewals, and what we made last
 * year the same period, without taxes»; the tax figures are Ekdosi's). As WHMCS's Income Forecast, by the billing's rules
 * (RenewalBilling): every active service not to be cancelled, and every domain the cron renews, renews on its due date,
 * then every cycle after, within the months shown; by cycle. Beside it, the same month a year before: what the invoices
 * paid then brought in (refunds and notes off). All amounts net (Δ1), in one currency.
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
        $cells = self::renewalCells($currency, $from, $until);

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

        // This month (F6 of #275), all by the month things fall due in: what is invoiced already (open or paid), what is
        // still to be invoiced (the forecast's month, less the renewals already on open invoices: a paid one has moved on),
        // their sum; and what came in this month.
        $now = CarbonImmutable::now()->startOfMonth();
        $invoiced = self::invoicedNet($currency, $now, $now->addMonth());
        $toInvoice = $from->equalTo($now) ? max(0, array_sum($cells[$now->format('Y-m')]['cycles'] ?? []) + ($cells[$now->format('Y-m')]['domains'] ?? 0)
            - self::openRenewalNet($currency, $now, $now->addMonth())) : 0;

        return [
            'months' => $rows,
            'total' => self::money($total),
            'last_year' => self::money($lastYearTotal),
            'current' => [
                'expected' => self::money($invoiced + $toInvoice),
                'invoiced' => self::money($invoiced),
                'collected' => self::money(self::paidNet($currency, $now, $now->addMonth())),
            ],
        ];
    }

    /**
     * The renewals falling due in [from, until), by month: 'Y-m' => ['cycles' => [months => cents], 'domains' => cents].
     * $skip: what each service or domain has invoiced already, left out (its invoice counts for it): the periods on its
     * lines (a renewal on a date one covers, as the billing tells a billed period) and how many open lines it has with
     * no period (each the next renewal not covered, as RenewServiceService gives it when paid: imported, by hand).
     *
     * A service's renewal given up on (its invoice cancelled or refunded, the line forgone) ends its renewals: the billing
     * bills nothing after it until staff close the gap (RenewalBilling, Δ3).
     *
     * @param  array<class-string, array<int, array{periods: list<array{0: CarbonImmutable, 1: ?CarbonImmutable, 2: bool}>, loose: int}>>  $skip
     * @return array<string, array{cycles?: array<int|string, int>, domains?: int}>
     */
    public static function renewalCells(string $currency, CarbonImmutable $from, CarbonImmutable $until, array $skip = []): array
    {
        $cells = [];

        // As the billing renews them (reviews of #275): active, not to be cancelled; each renewal on the plan and at the
        // price the billing will use (a cycle change at the next renewal, a coupon that ends: RenewalPrice).
        $services = Service::query()->with(['plan', 'nextPlan', 'coupon', 'product'])->where('status', Service::STATUS_ACTIVE)
            ->whereDoesntHave('cancellation')->where('currency_code', $currency)->whereNotNull('expires_at')->get();
        foreach ($services as $service) {
            $invoiced = $skip[Service::class][$service->id] ?? ['periods' => [], 'loose' => 0];
            $date = CarbonImmutable::instance($service->expires_at)->startOfDay();
            $month = $date->startOfMonth();
            $first = true;
            while ($month->lessThan($until)) {
                $priced = RenewalPrice::for($service, $month, $first);
                $every = self::months($priced->plan?->billing_unit, (int) $priced->plan?->billing_period, $priced->plan?->type);
                if ($every === null) {
                    break; // free, one time, no plan
                }
                $state = self::invoiced($invoiced, $date, false);
                if ($state === 'forgone') {
                    break;
                }
                if ($state === null && $month->greaterThanOrEqualTo($from)) {
                    $amount = self::cents(BigDecimal::of(number_format((float) $priced->price, 2, '.', ''))->multipliedBy(max(1, (int) $priced->quantity)));
                    $column = in_array($every, self::CYCLES, true) ? $every : 'other';
                    $key = $month->format('Y-m');
                    $cells[$key]['cycles'][$column] = ($cells[$key]['cycles'][$column] ?? 0) + $amount;
                }
                $service = $priced; // on its next plan from here on
                $first = false;
                $date = $date->addMonthsNoOverflow($every);
                $month = $month->addMonths($every);
            }
        }

        // The domains the cron renews (RenewalBilling::BILLED_DOMAINS, automatic renewal), at the price it bills: its own
        // or its TLD's for its next period (renewalPriceFor; R1 of #275), net of the account's tax.
        $domains = Domain::query()->with(['client', 'domainTld.prices'])
            ->whereIn('status', array_map(fn ($status) => $status->value, RenewalBilling::BILLED_DOMAINS))
            ->where('currency_code', $currency)->whereNotNull('expires_at')->where('auto_renew', true)->get();
        foreach ($domains as $domain) {
            $years = $domain->nextRenewalYears();
            $price = $domain->renewalPriceFor($years);
            if ($price === null) {
                continue; // the billing does not bill it either (no renew price)
            }
            $amount = self::cents(OrderPricing::toNet(number_format((float) $price, 2, '.', ''), $domain->client, null));
            $invoiced = $skip[Domain::class][$domain->id] ?? ['periods' => [], 'loose' => 0];
            // Overdue (its due date before the window): its renewals from the first one inside it, as WHMCS counts on.
            for ($date = CarbonImmutable::instance($domain->expires_at)->startOfDay(); $date->startOfMonth()->lessThan($until);
                $date = $date->addMonthsNoOverflow(12 * max(1, $years))) {
                if (self::invoiced($invoiced, $date, true) === null && $date->startOfMonth()->greaterThanOrEqualTo($from)) {
                    $key = $date->format('Y-m');
                    $cells[$key]['domains'] = ($cells[$key]['domains'] ?? 0) + $amount;
                }
            }
        }

        return $cells;
    }

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

    /**
     * Whether the renewal on $date is invoiced already, as the billing tells it: a line's period covers it (a service: from
     * its start to its end; a domain: one starting within DomainRenewalService::SAME_PERIOD_DAYS of it, as billedLine,
     * a registrar sync may move its expiry), else an open line with no period not used yet takes it (and is used).
     * 'forgone' when the period covering it was given up on, 'invoiced' otherwise, null when it is still to bill.
     *
     * @param  array{periods: list<array{0: CarbonImmutable, 1: ?CarbonImmutable, 2: bool}>, loose: int}  $invoiced
     */
    private static function invoiced(array &$invoiced, CarbonImmutable $date, bool $domain): ?string
    {
        foreach ($invoiced['periods'] as [$start, $end, $forgone]) {
            $covers = $domain ? abs($start->diffInDays($date)) <= DomainRenewalService::SAME_PERIOD_DAYS
                : $start->lessThanOrEqualTo($date) && ($end === null || $date->lessThan($end));
            if ($covers) {
                return $forgone ? 'forgone' : 'invoiced';
            }
        }
        if ($invoiced['loose'] > 0) {
            $invoiced['loose']--;

            return 'invoiced';
        }

        return null;
    }

    /**
     * What came in, net, in [from, until) (null: since ever), by when invoices were paid: the dashboard's Billing (Today,
     * This Month, This Year, All Time, as WHMCS's, net), the forecast's «collected» and «last year».
     */
    public static function collected(string $currency, ?CarbonImmutable $from, ?CarbonImmutable $until): string
    {
        return self::money(self::paidNet($currency, $from, $until));
    }

    /**
     * What the invoices paid in [from, until) brought in, net, in cents (by paid_at, WHMCS datepaid). No bounds: every
     * paid invoice, one imported without a payment date too (F4 of #278).
     */
    public static function paidNet(string $currency, ?CarbonImmutable $from, ?CarbonImmutable $until): int
    {
        return self::netOf(Invoice::query()->where('status', Invoice::STATUS_PAID)->where('currency_code', $currency)
            ->when($from !== null, fn ($query) => $query->where('paid_at', '>=', $from))
            ->when($until !== null, fn ($query) => $query->where('paid_at', '<', $until)));
    }

    /** What the invoices due in [from, until) bill, net, open or paid (not drafts, cancelled or refunded), in cents. */
    private static function invoicedNet(string $currency, CarbonImmutable $from, CarbonImmutable $until): int
    {
        return self::netOf(Invoice::query()->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID])->where('currency_code', $currency)
            ->where('due_at', '>=', $from)->where('due_at', '<', $until));
    }

    /** The services' renewal lines on open invoices due in [from, until), net: in the forecast's month as well. */
    private static function openRenewalNet(string $currency, CarbonImmutable $from, CarbonImmutable $until): int
    {
        $sum = DB::table('invoice_items')->where('reference_type', Service::class)
            ->whereIn('invoice_id', Invoice::query()->where('status', Invoice::STATUS_UNPAID)->where('currency_code', $currency)
                ->where('due_at', '>=', $from)->where('due_at', '<', $until)->select('id'))
            ->selectRaw('COALESCE(SUM(line_total - tax_amount), 0) AS net')->value('net');

        return self::cents((string) $sum);
    }

    /**
     * What these invoices are worth, net, in cents: their lines' net with their active adjustment notes (a refund, a
     * credit or a debit note: R8–R10 of #275) in the lines' net share; never a credit purchase (add funds: the credit is
     * income when it pays an invoice, counted there).
     *
     * @param  Builder<Invoice>  $invoices
     */
    public static function netOf(Builder $invoices): int
    {
        return array_sum(self::netByInvoice($invoices));
    }

    /**
     * netOf() by invoice: id => its net, in cents (the income reports by customer and product add them up their way).
     *
     * @param  Builder<Invoice>  $invoices
     * @return array<int, int>
     */
    public static function netByInvoice(Builder $invoices): array
    {
        // Summed in the database per invoice (F2 of #278: 30k invoices in one read, not each one's lines loaded).
        $ids = $invoices->select('invoices.id');
        $lines = DB::table('invoice_items')->whereIn('invoice_id', $ids)
            ->where(fn ($query) => $query->whereNull('reference_type')->orWhere('reference_type', '!=', Credit::class))
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(line_total - tax_amount) AS net, SUM(line_total) AS gross')
            ->get()->keyBy('invoice_id');
        $notes = DB::table('adjustment_notes')->whereIn('invoice_id', $lines->keys()->all() === [] ? [0] : $ids)
            ->where('status', AdjustmentNoteStatus::Active->value)
            ->groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount) AS amount')->pluck('amount', 'invoice_id');

        $out = [];
        foreach ($lines as $id => $row) {
            $net = self::cents(number_format((float) $row->net, 2, '.', ''));
            $gross = self::cents(number_format((float) $row->gross, 2, '.', ''));
            $note = self::cents(number_format((float) ($notes[$id] ?? 0), 2, '.', ''));
            // The notes are gross amounts on its total: their net part in the lines' proportion.
            $out[(int) $id] = $gross === 0 ? $net : $net + (int) round($note * $net / $gross);
        }

        return $out;
    }

    public static function cents(BigDecimal|string $amount): int
    {
        return BigDecimal::of((string) $amount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    public static function money(int $cents): string
    {
        return (string) BigDecimal::of($cents)->dividedBy(100, 2);
    }
}
