<?php

namespace App\Services\Reports;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Models\AdjustmentNote;
use App\Models\Credit;
use App\Models\Invoice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A year's income for the accountant (ROADMAP 6; nixpal's accountant wants a spreadsheet of the year's income), as cash:
 * every invoice paid in the year (paid_at, WHMCS datepaid; one refunded since too), with its net, tax and total, its
 * customer and how it was paid; and every refund or credit note made in the year on an invoice paid in an earlier one,
 * as a row of its own, negative (review F3 of #280: a year sent to the accountant never changes afterwards). A note
 * made before the year ended is on its invoice's row. Credit purchases (add funds) are never income. One currency.
 */
final class IncomeReport
{
    /** Rows shown on the page; the export holds them all (review F2 of #280). */
    public const SHOWN = 200;

    /**
     * @return array{invoices: list<array{paid_at: CarbonImmutable, number: string, client: string, country: string, vat: string,
     *               net: string, tax: string, total: string, paid_with: string}>,
     *               months: list<array{month: CarbonImmutable, count: int, net: string, tax: string, total: string}>,
     *               totals: array{count: int, net: string, tax: string, total: string}}
     */
    public static function year(string $currency, int $year): array
    {
        $from = CarbonImmutable::create($year)->startOfYear();
        $until = $from->addYear();
        $paid = Invoice::query()->whereIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_REFUNDED])->where('currency_code', $currency)
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $until);

        $rows = [];
        $add = function (array $row) use (&$rows) {
            $rows[] = $row;
        };

        // The invoices paid in the year, with the notes made before it ended.
        $ids = (clone $paid)->pluck('id')->all();
        $lines = self::lines($ids);
        $notes = DB::table('adjustment_notes')->whereIn('invoice_id', $ids === [] ? [0] : $ids)->where('status', AdjustmentNoteStatus::Active->value)
            ->where('created_at', '<', $until)->groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount) AS amount')->pluck('amount', 'invoice_id');
        foreach ((clone $paid)->with(['snapshot', 'client.primaryContact', 'transactions.gateway'])->orderBy('paid_at')->orderBy('id')->lazy(500) as $invoice) {
            $line = $lines[$invoice->id] ?? null;
            if ($line === null) {
                continue; // a credit purchase only: not income
            }
            [$net, $total] = self::amounts($line, self::cents($notes[$invoice->id] ?? 0));
            $add(self::row($invoice, CarbonImmutable::instance($invoice->paid_at), $net, $total, self::paidWith($invoice)));
        }

        // Refunds and credit notes made in the year on invoices paid before it: their own rows.
        $later = AdjustmentNote::query()->where('status', AdjustmentNoteStatus::Active->value)
            ->where('created_at', '>=', $from)->where('created_at', '<', $until)
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_REFUNDED])
                ->where('currency_code', $currency)->where('paid_at', '<', $from))
            ->with(['invoice.snapshot', 'invoice.client.primaryContact'])->orderBy('created_at')->orderBy('id')->get();
        $theirLines = self::lines($later->pluck('invoice_id')->unique()->values()->all());
        foreach ($later as $note) {
            $line = $theirLines[$note->invoice_id] ?? null;
            if ($line === null) {
                continue;
            }
            $amount = self::cents($note->amount);
            $gross = self::cents($line->gross);
            $net = $gross === 0 ? $amount : (int) round($amount * self::cents($line->net) / $gross);
            $add(self::row($note->invoice, CarbonImmutable::instance($note->created_at), $net, $amount, __('Refund or credit note')));
        }

        usort($rows, fn ($a, $b) => [$a['paid_at'], $a['number']] <=> [$b['paid_at'], $b['number']]);

        $months = [];
        foreach ($rows as $row) {
            $key = $row['paid_at']->format('m');
            $months[$key]['count'] = ($months[$key]['count'] ?? 0) + 1;
            $months[$key]['net'] = ($months[$key]['net'] ?? 0) + $row['net_cents'];
            $months[$key]['total'] = ($months[$key]['total'] ?? 0) + $row['total_cents'];
        }
        $byMonth = [];
        $totals = ['count' => 0, 'net' => 0, 'total' => 0];
        for ($month = $from; $month->lessThan($until); $month = $month->addMonth()) {
            $m = $months[$month->format('m')] ?? ['count' => 0, 'net' => 0, 'total' => 0];
            $byMonth[] = ['month' => $month, 'count' => $m['count'], 'net' => self::money($m['net']),
                'tax' => self::money($m['total'] - $m['net']), 'total' => self::money($m['total'])];
            $totals['count'] += $m['count'];
            $totals['net'] += $m['net'];
            $totals['total'] += $m['total'];
        }

        return [
            'invoices' => array_map(fn ($row) => array_diff_key($row, ['net_cents' => 1, 'total_cents' => 1]), $rows),
            'months' => $byMonth,
            'totals' => ['count' => $totals['count'], 'net' => self::money($totals['net']),
                'tax' => self::money($totals['total'] - $totals['net']), 'total' => self::money($totals['total'])],
        ];
    }

    /** The years with income, newest first, and this one; never before 2000 (an import's odd date; F8 of #280). @return list<int> */
    public static function years(): array
    {
        $first = Invoice::query()->where('status', Invoice::STATUS_PAID)->where('paid_at', '>=', '2000-01-01')->min('paid_at');
        $now = (int) CarbonImmutable::now()->format('Y');
        $start = $first ? (int) CarbonImmutable::parse($first)->format('Y') : $now;

        return range($now, min($start, $now), -1);
    }

    /**
     * Each invoice's lines summed in the database (as RevenueForecast::netOf), credit purchases left out.
     *
     * @param  list<int>  $ids
     * @return Collection<int|string, \stdClass>
     */
    private static function lines(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return DB::table('invoice_items')->whereIn('invoice_id', $ids)
            ->where(fn ($query) => $query->whereNull('reference_type')->orWhere('reference_type', '!=', Credit::class))
            ->groupBy('invoice_id')->selectRaw('invoice_id, SUM(line_total - tax_amount) AS net, SUM(line_total) AS gross')
            ->get()->keyBy('invoice_id');
    }

    /**
     * Its net and total with its notes: the notes' net part in the lines' proportion, the rest tax; an invoice of nothing
     * takes its notes as net, no tax (F5 of #280).
     *
     * @return array{int, int}
     */
    private static function amounts(object $line, int $note): array
    {
        $net = self::cents($line->net);
        $gross = self::cents($line->gross);
        $noteNet = $gross === 0 ? $note : (int) round($note * $net / $gross);

        return [$net + $noteNet, $gross + $note];
    }

    /** @return array<string, mixed> */
    private static function row(Invoice $invoice, CarbonImmutable $when, int $net, int $total, string $paidWith): array
    {
        $contact = $invoice->client?->primaryContact;

        return [
            'paid_at' => $when,
            'number' => (string) ($invoice->number ?: $invoice->id),
            'client' => (string) $invoice->user_name,
            // As issued where the invoice kept it (its snapshot's country, F4 of #280); the VAT number is the customer's now.
            'country' => (string) ($invoice->snapshot->tax_country ?? $contact->country ?? ''),
            'vat' => (string) ($contact->vat_number ?? ''),
            'net' => self::money($net),
            'tax' => self::money($total - $net),
            'total' => self::money($total),
            'paid_with' => $paidWith,
            'net_cents' => $net,
            'total_cents' => $total,
        ];
    }

    private static function paidWith(Invoice $invoice): string
    {
        return $invoice->transactions->where('status', InvoiceTransactionStatus::Succeeded)
            ->map(fn ($transaction) => $transaction->is_credit_transaction ? __('Account credit') : ($transaction->gateway->name ?? __('Manual')))
            ->unique()->implode(', ');
    }

    private static function cents(mixed $amount): int
    {
        return BigDecimal::of(number_format((float) $amount, 2, '.', ''))->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    private static function money(int $cents): string
    {
        return (string) BigDecimal::of($cents)->dividedBy(100, 2);
    }
}
