<?php

namespace App\Services\Reports;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Credit;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Services\Domains\DomainRenewalService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cash planning (owner 2026-10-28, design approved on the mockup): what came in, what is still expected, and the same
 * period a year before. Read only, net (as every MyCS figure: the tax ones are Ekdosi's), one currency at a time.
 *
 * - Received: the invoices paid in the month (by paid_at), as the dashboard's This Month and the Income report.
 * - Expected in a month: what is left to pay on the open invoices due in it (a partial payment off, in the invoice's net
 *   share), plus the renewals falling due in it (RevenueForecast, the billing's rules) whose period is on no invoice yet:
 *   an invoiced period counts once, by its invoice. Drafts, cancelled and refunded invoices are not expected.
 * - Overdue: what is left on open invoices due before this month, apart: in no month's total.
 */
final class IncomePlan
{
    /**
     * The dashboard's figures: this month, the last, the year so far, the overdue and the next $ahead months.
     *
     * @return array{this_month: array{month: CarbonImmutable, received: string, invoices: string, renewals: string, expected: string, total: string, last_year: string},
     *               last_month: array{month: CarbonImmutable, received: string, last_year: string},
     *               year: array{received: string, last_year: string, until: CarbonImmutable},
     *               overdue: array{amount: string, count: int},
     *               ahead: list<array{month: CarbonImmutable, received: string, invoices: string, renewals: string, expected: string, total: string, last_year: string}>}
     */
    public static function summary(string $currency, int $ahead = 3): array
    {
        $now = CarbonImmutable::now();
        $month = $now->startOfMonth();
        $rows = self::months($currency, $month, 1 + $ahead);
        $previous = $month->subMonth();
        // The year so far, today included, against the same days a year before: to the end of the same date (29
        // February: of 28 February; 28 February after a leap year: not the 29th, ADV-500-3).
        $until = $now->addDay()->startOfDay();
        $lastYearUntil = $now->subYearNoOverflow()->addDay()->startOfDay();
        $overdue = array_column(self::open($currency, null, $month), 'cents');

        return [
            'this_month' => $rows[0],
            'last_month' => [
                'month' => $previous,
                'received' => RevenueForecast::collected($currency, $previous, $month),
                'last_year' => RevenueForecast::collected($currency, $previous->subYear(), $month->subYear()),
            ],
            'year' => [
                'received' => RevenueForecast::collected($currency, $now->startOfYear(), $until),
                'last_year' => RevenueForecast::collected($currency, $now->startOfYear()->subYear(), $lastYearUntil),
                'until' => $now->startOfDay(),
            ],
            'overdue' => ['amount' => RevenueForecast::money(array_sum($overdue)), 'count' => count(array_filter($overdue))],
            'ahead' => array_slice($rows, 1),
        ];
    }

    /**
     * $count months from $from: received, expected (open invoices, renewals not invoiced), their total, and what came in
     * the same month a year before.
     *
     * @return list<array{month: CarbonImmutable, received: string, invoices: string, renewals: string, expected: string, total: string, last_year: string}>
     */
    public static function months(string $currency, CarbonImmutable $from, int $count): array
    {
        $from = $from->startOfMonth();
        $until = $from->addMonths($count);
        $cells = RevenueForecast::renewalCells($currency, $from, $until, self::invoicedPeriods($from, $until));
        $open = [];
        foreach (self::open($currency, $from, $until) as ['due' => $due, 'cents' => $cents]) {
            $open[$due->format('Y-m')] = ($open[$due->format('Y-m')] ?? 0) + $cents;
        }

        $rows = [];
        for ($month = $from; $month->lessThan($until); $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $received = RevenueForecast::paidNet($currency, $month, $month->addMonth());
            $invoices = $open[$key] ?? 0;
            $renewals = array_sum($cells[$key]['cycles'] ?? []) + ($cells[$key]['domains'] ?? 0);
            $rows[] = [
                'month' => $month,
                'received' => RevenueForecast::money($received),
                'invoices' => RevenueForecast::money($invoices),
                'renewals' => RevenueForecast::money($renewals),
                'expected' => RevenueForecast::money($invoices + $renewals),
                'total' => RevenueForecast::money($received + $invoices + $renewals),
                'last_year' => RevenueForecast::collected($currency, $month->subYear(), $month->subYear()->addMonth()),
            ];
        }

        return $rows;
    }

    /**
     * What each service and domain has invoiced already, which the billing will not bill again (RevenueForecast::
     * renewalCells): the periods on its open or paid lines (the invoice counts for them: expected while open, received
     * once paid) or given up on (forgone: nothing from it on, as the billing), around [from, until); and its open lines with no period
     * (an imported or hand-made renewal, review of #500), each the next renewal.
     *
     * @return array<class-string, array<int, array{periods: list<array{0: CarbonImmutable, 1: ?CarbonImmutable, 2: bool}>, loose: int}>>
     */
    private static function invoicedPeriods(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $margin = DomainRenewalService::SAME_PERIOD_DAYS + 1;
        $lines = DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereIn('invoice_items.reference_type', [Service::class, Domain::class])
            ->where(fn ($line) => $line->where(fn ($period) => $period->whereNotNull('invoice_items.period_start')
                ->where('invoice_items.period_start', '<', $until->addDays($margin)->toDateString())
                ->where(fn ($end) => $end->whereNull('invoice_items.period_end')->orWhere('invoice_items.period_end', '>', $from->subDays($margin)->toDateString()))
                // Live (open or paid), or given up on (forgone: its invoice cancelled or refunded): the billing never bills
                // that one again (Service::billedPeriodLines), nor a service's after it (review of #500).
                ->where(fn ($live) => $live->whereNotNull('invoice_items.forgone_at')
                    ->orWhereIn('invoices.status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID])))
                // No period, on an open invoice, a renewal (not a charge): it takes the next one.
                ->orWhere(fn ($loose) => $loose->whereNull('invoice_items.period_start')->where('invoices.status', Invoice::STATUS_UNPAID)
                    ->where(fn ($kind) => $kind->whereNull('invoice_items.kind')->orWhere('invoice_items.kind', '!=', InvoiceItem::KIND_CHARGE))))
            ->get(['invoice_items.reference_type', 'invoice_items.reference_id', 'invoice_items.period_start', 'invoice_items.period_end', 'invoice_items.forgone_at']);

        $skip = [];
        foreach ($lines as $line) {
            $type = (string) $line->reference_type;
            $id = (int) $line->reference_id;
            $item = $skip[$type][$id] ?? ['periods' => [], 'loose' => 0];
            if ($line->period_start === null) {
                $item['loose']++;
            } else {
                $item['periods'][] = [CarbonImmutable::parse($line->period_start)->startOfDay(),
                    $line->period_end === null ? null : CarbonImmutable::parse($line->period_end)->startOfDay(), $line->forgone_at !== null];
            }
            $skip[$type][$id] = $item;
        }

        return $skip;
    }

    /**
     * What is left to pay on the open invoices due in [from, until) (from null: any time before), net, in cents, by
     * invoice: its income net (no credit purchase; its adjustment notes in their net share, as RevenueForecast::netOf)
     * times the part of its total still unpaid (payments, credit applied, each less what was refunded or became credit).
     *
     * @return array<int, array{due: CarbonImmutable, cents: int}>
     */
    private static function open(string $currency, ?CarbonImmutable $from, CarbonImmutable $until): array
    {
        $invoices = Invoice::query()->where('status', Invoice::STATUS_UNPAID)->where('currency_code', $currency)
            ->when($from !== null, fn ($query) => $query->where('due_at', '>=', $from))
            ->whereNotNull('due_at')->where('due_at', '<', $until);
        $dues = (clone $invoices)->pluck('due_at', 'id');
        $ids = $invoices->select('id');

        $lines = DB::table('invoice_items')->whereIn('invoice_id', $ids)->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(line_total) AS gross, '
                . 'SUM(CASE WHEN reference_type IS NULL OR reference_type != ? THEN line_total - tax_amount ELSE 0 END) AS net, '
                . 'SUM(CASE WHEN reference_type IS NULL OR reference_type != ? THEN line_total ELSE 0 END) AS income', [Credit::class, Credit::class])
            ->get()->keyBy('invoice_id');
        if ($lines->isEmpty()) {
            return [];
        }
        $notes = DB::table('adjustment_notes')->whereIn('invoice_id', $ids)->where('status', AdjustmentNoteStatus::Active->value)
            ->groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount) AS amount')->pluck('amount', 'invoice_id');
        $paid = DB::table('invoice_transactions')->whereIn('invoice_id', $ids)->where('status', InvoiceTransactionStatus::Succeeded->value)
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(amount - COALESCE(refunded_amount, 0) - COALESCE(credited_amount, 0)) AS amount')
            ->pluck('amount', 'invoice_id');

        $out = [];
        foreach ($lines as $id => $row) {
            $note = self::cents($notes[$id] ?? 0);
            $income = self::cents($row->income);
            $net = self::cents($row->net);
            $net = $income === 0 ? $net : $net + self::share($note, $net, $income);
            $total = self::cents($row->gross) + $note;
            $left = min($total, max(0, $total - self::cents($paid[$id] ?? 0)));
            $out[(int) $id] = ['due' => CarbonImmutable::parse($dues[$id]),
                'cents' => $total <= 0 || $net <= 0 ? 0 : self::share($net, $left, $total)];
        }

        return $out;
    }

    /** $amount × $part / $whole, in whole cents (half up). */
    private static function share(int $amount, int $part, int $whole): int
    {
        return BigDecimal::of($amount)->multipliedBy($part)->dividedBy($whole, 0, RoundingMode::HalfUp)->toInt();
    }

    private static function cents(mixed $amount): int
    {
        return RevenueForecast::cents(number_format((float) $amount, 2, '.', ''));
    }
}
