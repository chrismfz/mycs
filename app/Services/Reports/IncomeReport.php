<?php

namespace App\Services\Reports;

use App\Enums\AdjustmentNoteStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Models\AdjustmentNote;
use App\Models\Client;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\MoneyEvent;
use App\Services\Money\MoneyTrail;
use App\Services\Order\OrderPricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A year's income (ROADMAP 6), as cash. Informative: fiscal documents and their corrections are Ekdosi's, and the
 * accountant works from there (owner 2026-11-10). It shows the invoices paid in the year as they stand today (paid_at,
 * WHMCS datepaid; one refunded since too), with net, tax and total, customer and how each was paid. So a chargeback, a
 * deleted payment or a staff correction after the year's end changes it: an invoice open again is no longer listed,
 * and one paid again is listed on its new date. Nothing is kept to hold a past year still.
 *
 * A refund or credit note made in the year on an invoice paid in an earlier one is a row of its own, negative; one made
 * before the year ended is on its invoice's row. Credit purchases (add funds) are never income. One currency.
 *
 * The credit a downgrade or a change of cycle gave (the journal's credit_in of those causes, read only) is a negative
 * row in the year it was given (sweep 6, S6-TAX-EKDOSI-1): its money came in on the invoice first paid and is counted
 * again on the invoice that credit pays. Gross, split into net and tax at the customer's VAT rate today: an estimate,
 * the journal keeps no split. Imported credit is left out (its WHMCS years are as WHMCS had them).
 */
final class IncomeReport
{
    /** Rows shown on the page; the export holds them all (review F2 of #280). */
    public const SHOWN = 200;

    /** The line on the page and at the top of the export. */
    public static function notice(): string
    {
        return __('Informative. Fiscal documents are issued in Ekdosi.');
    }

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
        foreach ((clone $paid)->with(['snapshot', 'client.primaryContact', 'client.ownerGrant.user', 'transactions.gateway'])->orderBy('paid_at')->orderBy('id')->lazy(500) as $invoice) {
            $line = $lines[$invoice->id] ?? null;
            if ($line === null) {
                continue; // a credit purchase only: not income
            }
            [$net, $total] = self::amounts($line, self::cents($notes[$invoice->id] ?? 0));
            // Paid with no money on it (staff marked it paid by hand, owner 2026-11-10): listed as any paid invoice, and said.
            $with = self::paidWith($invoice);
            $add(self::row($invoice, CarbonImmutable::instance($invoice->paid_at), $net, $total, $with === '' && $total > 0 ? __('Marked paid by staff, no payment') : $with));
        }

        // Refunds and credit notes made in the year on invoices paid before it: their own rows.
        $later = AdjustmentNote::query()->where('status', AdjustmentNoteStatus::Active->value)
            ->where('created_at', '>=', $from)->where('created_at', '<', $until)
            ->whereHas('invoice', fn ($query) => $query->whereIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_REFUNDED])
                ->where('currency_code', $currency)->where('paid_at', '<', $from))
            ->with(['invoice.snapshot', 'invoice.client.primaryContact', 'invoice.client.ownerGrant.user'])->orderBy('created_at')->orderBy('id')->get();
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

        // The credit a downgrade or a change of cycle gave in the year: taken off, as the invoice it pays counts it again.
        $credits = MoneyEvent::query()->where('kind', MoneyEventKind::CreditIn->value)->where('currency_code', $currency)
            ->whereIn('cause', [MoneyTrail::CAUSE_DOWNGRADE, MoneyTrail::CAUSE_CYCLE_CHANGE])->where('source', '!=', MoneyTrail::SOURCE_IMPORT)
            ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $until)->whereDoesntHave('reversal')
            ->with(['client.primaryContact', 'client.ownerGrant.user'])->orderBy('occurred_at')->orderBy('id')->get();
        foreach ($credits as $event) {
            $gross = self::cents($event->amount);
            $rate = $event->client !== null ? OrderPricing::taxRate($event->client) : null;
            $net = $rate === null ? $gross : BigDecimal::of($gross)->multipliedBy(100)
                ->dividedBy(BigDecimal::of($rate)->plus(100), 0, RoundingMode::HalfUp)->toInt();
            $add(self::entry(CarbonImmutable::instance($event->occurred_at), '', (string) ($event->client->name ?? ''), $event->client,
                (string) ($event->client?->primaryContact->country ?? ''), -$net, -$gross,
                $event->cause === MoneyTrail::CAUSE_DOWNGRADE ? __('Credit given for a downgrade') : __('Credit given for a change of cycle')));
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

    /** The country an issued invoice's tax rule was for, null when none or the rule is for every country. */
    private static function issuedCountry(?string $country): ?string
    {
        return $country === null || $country === '' || strtolower($country) === 'all' ? null : $country;
    }

    /** @return array<string, mixed> */
    private static function row(Invoice $invoice, CarbonImmutable $when, int $net, int $total, string $paidWith): array
    {
        // As issued where the invoice kept it (its snapshot's country, F4 of #280); the VAT number is the customer's now.
        // A rule for every country ('all', as WhmcsTaxImport maps WHMCS's «any») says nothing of the customer's: the
        // customer's country now then (sweep TAX-REPORTS-3).
        return self::entry($when, (string) ($invoice->number ?: $invoice->id), (string) $invoice->user_name, $invoice->client,
            (string) (self::issuedCountry($invoice->snapshot?->tax_country) ?? $invoice->client?->primaryContact->country ?? ''), $net, $total, $paidWith);
    }

    /**
     * A row: an invoice's, or one without an invoice (credit given).
     *
     * @return array<string, mixed>
     */
    private static function entry(CarbonImmutable $when, string $number, string $name, ?Client $client, string $country, int $net, int $total, string $what): array
    {
        return [
            'paid_at' => $when,
            'number' => $number,
            'client' => $name,
            'country' => $country,
            'vat' => (string) ($client?->primaryContact->vat_number ?? ''),
            'net' => self::money($net),
            'tax' => self::money($total - $net),
            'total' => self::money($total),
            'paid_with' => $what,
            'net_cents' => $net,
            'total_cents' => $total,
        ];
    }

    /** How it was paid: its gateways, account credit or «Manual» (also the chat card of a paid invoice). */
    public static function paidWith(Invoice $invoice): string
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
