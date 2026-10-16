<?php

namespace App\Services\Domains\Pricing;

use InvalidArgumentException;

/**
 * The arithmetic of TLD prices (docs/domains.md D-6), as WHMCS's «TLD Import & Pricing Sync»: a margin on the
 * registrar's cost (a percentage or an amount), rounded up to a price people are used to, and the price of N years.
 * Amounts are decimal strings with two decimals, computed with bcmath: never a float (CLAUDE.md).
 */
final class TldPriceMath
{
    public const MARGIN_PERCENT = 'percent';

    public const MARGIN_FIXED = 'fixed';

    /** How a price is rounded: none (to the cent, half up), or UP to the next whole, .50 or .99. */
    public const ROUNDING = ['none', '.00', '.50', '.99'];

    /** Cost plus margin, to the cent (half up). */
    public static function withMargin(string $cost, string $type, string $margin): string
    {
        self::number($cost);
        self::number($margin);

        $price = match ($type) {
            self::MARGIN_PERCENT => bcmul($cost, bcadd('1', bcdiv($margin, '100', 8), 8), 8),
            self::MARGIN_FIXED => bcadd($cost, $margin, 8),
            default => throw new InvalidArgumentException("Unknown margin type «{$type}»."),
        };

        return self::cents($price);
    }

    /** A price rounded as staff chose: never down. */
    public static function round(string $price, string $rounding): string
    {
        $price = self::cents($price);
        if (!in_array($rounding, self::ROUNDING, true)) {
            throw new InvalidArgumentException("Unknown rounding «{$rounding}».");
        }
        if ($rounding === 'none') {
            return $price;
        }
        $whole = bcadd($price, '0', 0); // truncated
        $fraction = bcsub($price, $whole, 2);
        // The endings allowed within a unit, lowest first: the first at or above the fraction, else the next unit's.
        $endings = match ($rounding) {
            '.00' => ['0.00'],
            '.50' => ['0.00', '0.50'],
            default => ['0.99'],
        };
        foreach ($endings as $ending) {
            if (bccomp($fraction, $ending, 2) <= 0) {
                return bcadd($whole, $ending, 2);
            }
        }

        return bcadd(bcadd($whole, '1', 0), $endings[0], 2);
    }

    /** N years of a 1-year price. */
    public static function years(string $yearly, int $years): string
    {
        if ($years < 1 || $years > 10) {
            throw new InvalidArgumentException("Years are 1 to 10, not {$years}.");
        }

        return bcmul(self::cents($yearly), (string) $years, 2);
    }

    /** A cost in another currency of MyCS, by the rate staff gave (1 unit of the cost's currency = rate units). */
    public static function convert(string $amount, string $rate): string
    {
        self::number($amount);
        self::number($rate);
        if (bccomp($rate, '0', 8) <= 0) {
            throw new InvalidArgumentException('An exchange rate is above zero.');
        }

        return self::cents(bcmul($amount, $rate, 8));
    }

    /** Two decimals, half up (for the non-negative amounts of prices). */
    public static function cents(string $amount): string
    {
        self::number($amount);

        return bcadd($amount, bccomp($amount, '0', 8) >= 0 ? '0.005' : '-0.005', 2);
    }

    private static function number(string $value): void
    {
        if (!preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Not an amount: «{$value}».");
        }
    }
}
