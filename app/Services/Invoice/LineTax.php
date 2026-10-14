<?php

namespace App\Services\Invoice;

use App\Models\Credit;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Order\OrderPricing;
use MyCS\Pricing\LinePricer;

/**
 * The tax of an invoice line (Δ1, docs/orders.md): the rate is decided when the line is made, from the invoice's account
 * (its country, tax exempt) and the line's product (taxable), and kept: a later change of either does not change an
 * issued line. The amounts are worked out again whenever the price, quantity or rate changes.
 */
final class LineTax
{
    /** The rate for a new line, or for one whose «taxed» flag changed; 0 when it is not taxed. */
    public static function rateFor(InvoiceItem $item): string
    {
        // Credit bought is not a sale (WHMCS «Add Funds»): never taxed.
        if (!($item->taxed ?? true) || $item->reference_type === Credit::class) {
            return '0';
        }
        $client = $item->invoice?->client;
        $rate = OrderPricing::taxRate($client, self::product($item));

        return $rate ?? '0';
    }

    /** Set the line's tax and total from its net price, quantity and rate. */
    public static function apply(InvoiceItem $item): void
    {
        $line = LinePricer::invoiceLine(number_format((float) $item->price, 2, '.', ''), (int) $item->quantity,
            ($item->taxed ?? true) ? (string) ($item->tax_rate ?? '0') : null);
        $item->tax_amount = $line['tax'];
        $item->line_total = $line['total'];
    }

    private static function product(InvoiceItem $item): ?Product
    {
        return match ($item->reference_type) {
            Service::class => Service::query()->find($item->reference_id)?->product,
            ServiceUpgrade::class => ServiceUpgrade::query()->find($item->reference_id)?->product,
            default => null,
        };
    }
}
