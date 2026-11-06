<?php

namespace MyCS\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * A discount staff give on a charge billed as one line (MyCS: an upgrade's difference for the days left), shown on the
 * invoice as a second, negative line under it. Both lines are priced as any invoice line (LinePricer::invoiceLine: net,
 * its tax rounded half-up to cents on the line), so what the invoice totals is the full line's total plus the discount
 * line's (a negative one: the same tax, the other sign).
 *
 * - PERCENT: that share of the full net amount, rounded half-up to cents (10% of 0.42 is 0.04). 100% waives it.
 * - AMOUNT: what the customer pays, **tax included** (what the staff's summary shows as «due now»). The discount is the
 *   smallest net amount, in whole cents, that brings the invoice's total to it or under it. Each line rounds its own tax,
 *   so not every total in cents can be reached (at 24% a net cent more is sometimes two cents with tax): then the total is
 *   the nearest one below it, a cent under, never more than staff typed. 0 waives it.
 * - WAIVE: all of it, nothing to pay.
 *
 * Amounts are decimal strings with at most two decimals (a percent too), never floats.
 */
final class DifferenceDiscount
{
    public const PERCENT = 'percent';

    public const AMOUNT = 'amount';

    public const WAIVE = 'waive';

    /** Not a number of at most two decimals, or below zero. */
    public const REFUSED_INVALID = 'invalid';

    /** More than the charge: above 100%, or an amount to pay above the full total. */
    public const REFUSED_TOO_LARGE = 'too_large';

    /** No charge to discount (nothing, or a refund). */
    public const REFUSED_NOTHING_DUE = 'nothing_due';

    private function __construct(
        /** The full line: net, and with its tax. */
        public readonly string $fullNet,
        public readonly string $fullTotal,
        /** The discount, net (positive: the discount line's price is its negative) and with its tax. */
        public readonly string $discountNet,
        public readonly string $discountTotal,
        /** What the invoice totals: the full line's total less the discount's. */
        public readonly string $dueTotal,
        /** The percentage given, for the line's text; null for an amount or a waiver. */
        public readonly ?string $percent,
    ) {}

    /**
     * @param  string  $fullNet  the charge's net amount (above zero)
     * @param  string|null  $taxRate  percent (e.g. "24"); null or zero for no tax
     * @param  string  $value  the percentage, or what to pay with tax; ignored for WAIVE
     *
     * @throws DifferenceDiscountRefused
     */
    public static function of(string $fullNet, ?string $taxRate, string $mode, string $value = ''): self
    {
        if (!preg_match('/^-?\d+(\.\d+)?$/', $fullNet) || !BigDecimal::of($fullNet)->isPositive()) {
            throw new DifferenceDiscountRefused(self::REFUSED_NOTHING_DUE);
        }
        $full = BigDecimal::of($fullNet)->toScale(2, RoundingMode::HalfUp);
        $fullTotal = self::total($full, $taxRate);

        $discount = match ($mode) {
            self::WAIVE => $full,
            self::PERCENT => self::fromPercent($full, self::amount($value)),
            self::AMOUNT => self::fromAmount($full, $fullTotal, $taxRate, self::amount($value)),
            default => throw new DifferenceDiscountRefused(self::REFUSED_INVALID),
        };
        $discountTotal = self::total($discount, $taxRate);
        $percent = $mode === self::PERCENT && $discount->isPositive()
            ? self::percentLabel(self::amount($value)) : null;

        return new self((string) $full, (string) $fullTotal, (string) $discount, (string) $discountTotal,
            (string) $fullTotal->minus($discountTotal), $percent);
    }

    /** Nothing taken off (0%, or the full amount to pay): one line, as without a discount. */
    public function none(): bool
    {
        return BigDecimal::of($this->discountNet)->isZero();
    }

    /** Nothing left to pay: no invoice. */
    public function waived(): bool
    {
        return !BigDecimal::of($this->dueTotal)->isPositive();
    }

    /**
     * The net discount once the charge it is on is repriced (MyCS: the cron's, as the days left run out): the same share
     * of it, so a discount never costs more than none. A percentage stays that percentage; an amount the share it was of
     * the old charge (half-up to cents), never more than the new charge.
     */
    public static function follow(string $oldFullNet, string $oldDiscountNet, string $newFullNet, ?string $percent = null): string
    {
        $new = BigDecimal::of($newFullNet)->toScale(2, RoundingMode::HalfUp);
        if (!$new->isPositive()) {
            return '0.00';
        }
        if ($percent !== null) {
            return (string) self::fromPercent($new, self::amount($percent));
        }
        $old = BigDecimal::of($oldFullNet);
        if (!$old->isPositive()) {
            return '0.00';
        }
        $share = BigDecimal::of($oldDiscountNet)->multipliedBy($new)->dividedBy($old, 2, RoundingMode::HalfUp);

        return (string) BigDecimal::min(BigDecimal::max($share, BigDecimal::zero()), $new);
    }

    private static function fromPercent(BigDecimal $full, BigDecimal $percent): BigDecimal
    {
        if ($percent->isGreaterThan(100)) {
            throw new DifferenceDiscountRefused(self::REFUSED_TOO_LARGE);
        }

        return $full->multipliedBy($percent)->dividedBy(100, 2, RoundingMode::HalfUp);
    }

    /** The smallest net discount, in cents, whose invoice totals $pay or less (the totals only fall as it grows). */
    private static function fromAmount(BigDecimal $full, BigDecimal $fullTotal, ?string $taxRate, BigDecimal $pay): BigDecimal
    {
        if ($pay->isGreaterThan($fullTotal)) {
            throw new DifferenceDiscountRefused(self::REFUSED_TOO_LARGE);
        }
        $low = 0;
        $high = $full->getUnscaledValue()->toInt(); // all of it: the total is then 0.00, never above $pay
        while ($low < $high) {
            $cents = intdiv($low + $high, 2);
            $due = $fullTotal->minus(self::total(BigDecimal::ofUnscaledValue($cents, 2), $taxRate));
            if ($due->isLessThanOrEqualTo($pay)) {
                $high = $cents;
            } else {
                $low = $cents + 1;
            }
        }

        return BigDecimal::ofUnscaledValue($low, 2);
    }

    private static function total(BigDecimal $net, ?string $taxRate): BigDecimal
    {
        return BigDecimal::of(LinePricer::invoiceLine((string) $net, 1, $taxRate)['total']);
    }

    /** A number of at most two decimals, zero or more. */
    private static function amount(string $value): BigDecimal
    {
        $value = trim($value);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw new DifferenceDiscountRefused(self::REFUSED_INVALID);
        }

        return BigDecimal::of($value)->toScale(2);
    }

    /** 10.00 → «10», 12.50 → «12.5». */
    private static function percentLabel(BigDecimal $percent): string
    {
        return rtrim(rtrim((string) $percent, '0'), '.');
    }
}
