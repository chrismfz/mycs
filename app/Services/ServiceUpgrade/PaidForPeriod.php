<?php

namespace App\Services\ServiceUpgrade;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What a paid line paid for the days it covers, with its tax (sweep 2): its total, less the setup fee a first invoice's
 * line holds with its period (one-time money, never days: F-PLAN-CHANGE-1), and only the share its invoice kept: a
 * refund, a refund to credit or a staff credit note on it lowers the invoice's total below its lines, and that money
 * is no longer paid for the days (F-STAFF-ADMIN-MONEY-1). What a cycle change «now» and a downgrade give back is worked
 * out from this, never from the line's total alone.
 */
final class PaidForPeriod
{
    public static function of(InvoiceItem $line): string
    {
        // A negative line (staff's discount under an upgrade's charge) takes its share off as the charge counts its own.
        $total = BigDecimal::of(number_format((float) $line->line_total, 2, '.', ''));
        if ($total->isZero()) {
            return '0.00';
        }

        return (string) $total->multipliedBy(self::periodShare($line))->multipliedBy(self::keptShare($line))
            ->toScale(2, RoundingMode::HalfUp);
    }

    /** The share of the line's price that is not its setup fee (net, per unit, as the price): 1 for a line without one. */
    private static function periodShare(InvoiceItem $line): BigDecimal
    {
        $price = BigDecimal::of(number_format((float) $line->price, 2, '.', ''));
        $setup = BigDecimal::of(number_format((float) ($line->setup_fee ?? 0), 2, '.', ''));
        if (!$setup->isPositive() || !$price->isPositive()) {
            return BigDecimal::one();
        }

        return BigDecimal::max(BigDecimal::zero(), $price->minus($setup))->dividedBy($price, 10, RoundingMode::HalfUp);
    }

    /** The share of its lines its invoice kept: its total (refunds and credit notes off, debit notes on) over its lines. */
    private static function keptShare(InvoiceItem $line): BigDecimal
    {
        $invoice = Invoice::query()->with(['items', 'adjustmentNotes'])->find($line->invoice_id);
        if ($invoice === null) {
            return BigDecimal::one();
        }
        $lines = (int) $invoice->items->sum(fn (InvoiceItem $item) => $item->totalCents());
        $kept = (int) round((float) $invoice->total * 100);
        if ($lines <= 0 || $kept >= $lines) {
            return BigDecimal::one();
        }

        return $kept <= 0 ? BigDecimal::zero() : BigDecimal::of($kept)->dividedBy($lines, 10, RoundingMode::HalfUp);
    }
}
