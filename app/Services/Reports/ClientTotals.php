<?php

namespace App\Services\Reports;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Invoice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A customer's totals by year (the owner, 2026-10-21: on its Summary, all time and yearly), net, by the reports' rules
 * (notes in by the lines' net share, credit purchases never; RevenueForecast::netOf):
 * - invoiced: its open and paid invoices, by their date (created_at: WHMCS's `date` for an import; a draft issued later
 *   counts in the year it was made); a refunded or cancelled one is neither invoiced nor paid, as in the reports;
 * - paid: by when (paid_at); a paid one with no date (an import) on a row of its own, so the years add up (F2 of #281);
 * - outstanding: what is left to pay on its open invoices (their balance, net share; F1), by their date.
 * One read of its invoices, bucketed here (F4). Per currency, newest year first; no empty years or currencies (F3).
 */
final class ClientTotals
{
    /**
     * @return array<string, array{years: list<array{year: int|null, invoiced: string, paid: string, outstanding: string}>, total: array{invoiced: string, paid: string, outstanding: string}}>
     */
    public static function for(Client $client): array
    {
        $invoices = DB::table('invoices')->where('client_id', $client->id)
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID])
            ->get(['id', 'status', 'currency_code', 'created_at', 'paid_at']);
        if ($invoices->isEmpty()) {
            return [];
        }
        $ids = $invoices->pluck('id')->all();
        $lines = DB::table('invoice_items')->whereIn('invoice_id', $ids)
            ->where(fn ($query) => $query->whereNull('reference_type')->orWhere('reference_type', '!=', Credit::class))
            ->groupBy('invoice_id')->selectRaw('invoice_id, SUM(line_total - tax_amount) AS net, SUM(line_total) AS gross')->get()->keyBy('invoice_id');
        $notes = DB::table('adjustment_notes')->whereIn('invoice_id', $ids)->where('status', AdjustmentNoteStatus::Active->value)
            ->groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount) AS amount')->pluck('amount', 'invoice_id');
        // What came in on each (as Invoice::remaining: net of refunds and of what became credit again).
        $received = DB::table('invoice_transactions')->whereIn('invoice_id', $ids)->where('status', InvoiceTransactionStatus::Succeeded->value)
            ->groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount - refunded_amount - credited_amount) AS amount')->pluck('amount', 'invoice_id');

        $buckets = []; // currency => year|'undated' => [invoiced, paid, outstanding] cents
        foreach ($invoices as $invoice) {
            $line = $lines[$invoice->id] ?? null;
            if ($line === null) {
                continue; // a credit purchase only
            }
            $net = self::cents($line->net);
            $gross = self::cents($line->gross);
            $note = self::cents($notes[$invoice->id] ?? 0);
            $share = fn (int $amount) => $gross === 0 ? $amount : (int) round($amount * $net / $gross);
            $value = $net + $share($note);
            $currency = (string) $invoice->currency_code;
            $year = (int) CarbonImmutable::parse($invoice->created_at)->format('Y');
            $buckets[$currency][$year][0] = ($buckets[$currency][$year][0] ?? 0) + $value;
            if ($invoice->status === Invoice::STATUS_PAID) {
                $paidYear = $invoice->paid_at !== null ? (int) CarbonImmutable::parse($invoice->paid_at)->format('Y') : 'undated';
                $buckets[$currency][$paidYear][1] = ($buckets[$currency][$paidYear][1] ?? 0) + $value;
            } else {
                $left = max(0, $gross + $note - self::cents($received[$invoice->id] ?? 0));
                $buckets[$currency][$year][2] = ($buckets[$currency][$year][2] ?? 0) + $share($left);
            }
        }

        $out = [];
        ksort($buckets);
        foreach ($buckets as $currency => $byYear) {
            $years = [];
            $total = [0, 0, 0];
            $dated = array_filter(array_keys($byYear), 'is_int');
            for ($year = max([...$dated, (int) CarbonImmutable::now()->format('Y')]); $dated !== [] && $year >= min($dated); $year--) {
                $row = ($byYear[$year] ?? []) + [0, 0, 0];
                if ($row[0] === 0 && $row[1] === 0 && $row[2] === 0) {
                    continue;
                }
                $years[] = self::row($year, $row);
                $total = [$total[0] + $row[0], $total[1] + $row[1], $total[2] + $row[2]];
            }
            if (isset($byYear['undated'])) {
                $row = $byYear['undated'] + [0, 0, 0];
                $years[] = self::row(null, $row);
                $total = [$total[0] + $row[0], $total[1] + $row[1], $total[2] + $row[2]];
            }
            if ($years !== []) {
                $out[$currency] = ['years' => $years, 'total' => array_diff_key(self::row(null, $total), ['year' => 1])];
            }
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $cents  invoiced, paid, outstanding
     * @return array{year: int|null, invoiced: string, paid: string, outstanding: string}
     */
    private static function row(?int $year, array $cents): array
    {
        return ['year' => $year, 'invoiced' => self::money($cents[0]), 'paid' => self::money($cents[1]), 'outstanding' => self::money($cents[2])];
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
