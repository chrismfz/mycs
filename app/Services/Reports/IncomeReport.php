<?php

namespace App\Services\Reports;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Credit;
use App\Models\Invoice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A year's income for the accountant (ROADMAP 6; nixpal's accountant wants a spreadsheet of the year's income): every
 * invoice paid in the year (by paid_at, WHMCS datepaid) with its net, tax and total, its customer (name as issued,
 * country, VAT number) and how it was paid; and the months. The same rules as the forecast and the dashboard: its
 * adjustment notes (refunds, credit notes) in, credit purchases (add funds) never income. One currency.
 */
final class IncomeReport
{
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
        $paid = Invoice::query()->where('status', Invoice::STATUS_PAID)->where('currency_code', $currency)
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $until);

        // Summed in the database per invoice (as RevenueForecast::netOf): lines' net and gross, without credit purchases.
        $ids = (clone $paid)->select('invoices.id');
        $lines = DB::table('invoice_items')->whereIn('invoice_id', $ids)
            ->where(fn ($query) => $query->whereNull('reference_type')->orWhere('reference_type', '!=', Credit::class))
            ->groupBy('invoice_id')->selectRaw('invoice_id, SUM(line_total - tax_amount) AS net, SUM(line_total) AS gross')
            ->get()->keyBy('invoice_id');
        $notes = DB::table('adjustment_notes')->whereIn('invoice_id', $ids)->where('status', AdjustmentNoteStatus::Active->value)
            ->groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount) AS amount')->pluck('amount', 'invoice_id');

        $rows = [];
        $months = [];
        foreach ((clone $paid)->with(['client.primaryContact', 'transactions.gateway'])->orderBy('paid_at')->orderBy('id')->get() as $invoice) {
            $line = $lines[$invoice->id] ?? null;
            if ($line === null) {
                continue; // a credit purchase only: not income
            }
            $net = self::cents($line->net);
            $gross = self::cents($line->gross);
            $note = self::cents($notes[$invoice->id] ?? 0);
            // Its notes are gross amounts on its total: their net part in the lines' proportion; the rest is tax.
            $noteNet = $gross === 0 ? 0 : (int) round($note * $net / $gross);
            $net += $noteNet;
            $total = $gross + $note;
            $paidAt = CarbonImmutable::instance($invoice->paid_at);
            $contact = $invoice->client?->primaryContact;
            $rows[] = [
                'paid_at' => $paidAt,
                'number' => (string) ($invoice->number ?: $invoice->id),
                'client' => (string) $invoice->user_name,
                'country' => (string) ($contact->country ?? ''),
                'vat' => (string) ($contact->vat_number ?? ''),
                'net' => self::money($net),
                'tax' => self::money($total - $net),
                'total' => self::money($total),
                'paid_with' => $invoice->transactions->where('status', InvoiceTransactionStatus::Succeeded)
                    ->map(fn ($transaction) => $transaction->is_credit_transaction ? __('Account credit') : ($transaction->gateway->name ?? __('Manual')))
                    ->unique()->implode(', '),
            ];
            $key = $paidAt->format('m');
            $months[$key]['count'] = ($months[$key]['count'] ?? 0) + 1;
            $months[$key]['net'] = ($months[$key]['net'] ?? 0) + $net;
            $months[$key]['total'] = ($months[$key]['total'] ?? 0) + $total;
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

        return ['invoices' => $rows, 'months' => $byMonth, 'totals' => ['count' => $totals['count'], 'net' => self::money($totals['net']),
            'tax' => self::money($totals['total'] - $totals['net']), 'total' => self::money($totals['total'])]];
    }

    /** The years with income, newest first, and this one. @return list<int> */
    public static function years(): array
    {
        $first = Invoice::query()->where('status', Invoice::STATUS_PAID)->min('paid_at');
        $now = (int) CarbonImmutable::now()->format('Y');
        $start = $first ? (int) CarbonImmutable::parse($first)->format('Y') : $now;

        return range($now, min($start, $now), -1);
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
