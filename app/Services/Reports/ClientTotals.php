<?php

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\Invoice;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * A customer's totals by year (the owner, 2026-10-21: on its Summary, all time and yearly): what it was invoiced (by
 * the invoice's date), what it paid (by when, paid_at), what is open; net, by the same rules as the reports
 * (RevenueForecast::netOf: notes in, credit purchases never). Per currency, newest year first.
 */
final class ClientTotals
{
    /**
     * @return array<string, array{years: list<array{year: int, invoiced: string, paid: string, open: string}>, total: array{invoiced: string, paid: string, open: string}}>
     */
    public static function for(Client $client): array
    {
        $invoices = fn () => Invoice::query()->where('client_id', $client->id);
        $out = [];
        foreach ($invoices()->distinct()->pluck('currency_code') as $currency) {
            $first = $invoices()->where('currency_code', $currency)->min('created_at');
            if ($first === null) {
                continue;
            }
            $years = [];
            $total = ['invoiced' => 0, 'paid' => 0, 'open' => 0];
            for ($year = (int) CarbonImmutable::now()->format('Y'); $year >= (int) CarbonImmutable::parse($first)->format('Y'); $year--) {
                $from = CarbonImmutable::create($year)->startOfYear();
                $until = $from->addYear();
                $mine = fn () => $invoices()->where('currency_code', $currency);
                $row = [
                    'invoiced' => RevenueForecast::netOf($mine()->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID])
                        ->where('created_at', '>=', $from)->where('created_at', '<', $until)),
                    'paid' => RevenueForecast::netOf($mine()->where('status', Invoice::STATUS_PAID)
                        ->where('paid_at', '>=', $from)->where('paid_at', '<', $until)),
                    'open' => RevenueForecast::netOf($mine()->where('status', Invoice::STATUS_UNPAID)
                        ->where('created_at', '>=', $from)->where('created_at', '<', $until)),
                ];
                foreach ($row as $key => $cents) {
                    $total[$key] += $cents;
                }
                $years[] = ['year' => $year] + array_map(self::money(...), $row);
            }
            // All time also counts a paid invoice with no payment date (an import), as the dashboard does.
            $total['paid'] = RevenueForecast::netOf($invoices()->where('currency_code', $currency)->where('status', Invoice::STATUS_PAID));
            $out[(string) $currency] = ['years' => $years, 'total' => array_map(self::money(...), $total)];
        }

        return $out;
    }

    private static function money(int $cents): string
    {
        return (string) BigDecimal::of($cents)->dividedBy(100, 2);
    }
}
