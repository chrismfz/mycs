<?php

namespace App\Services\Domains\Pricing;

use InvalidArgumentException;

/**
 * The arithmetic of TLD prices (docs/domains.md D-6), as WHMCS's «TLD Import & Pricing Sync»: a margin on the
 * registrar's cost (a percentage, or an amount in the price's currency), rounded once, up, to a price people are used
 * to, and the price of N years. Amounts are non-negative decimal strings, computed with bcmath at 8 decimals and
 * rounded only at the end: never a float (CLAUDE.md). Zero stays zero: a free operation is free (D-6).
 */
final class TldPriceMath
{
    public const MARGIN_PERCENT = 'percent';

    public const MARGIN_FIXED = 'fixed';

    /** How a price is rounded: none (to the cent, half up), or UP to the next whole, .50 or .99. */
    public const ROUNDING = ['none', '.00', '.50', '.99'];

    private const SCALE = 8;

    /** Cost plus margin, exact (8 decimals): rounded once, by round(). */
    public static function withMargin(string $cost, string $type, string $margin): string
    {
        self::amount($cost);
        self::amount($margin);

        return match ($type) {
            self::MARGIN_PERCENT => bcmul($cost, bcadd('1', bcdiv($margin, '100', self::SCALE), self::SCALE), self::SCALE),
            self::MARGIN_FIXED => bcadd($cost, $margin, self::SCALE),
            default => throw new InvalidArgumentException("Unknown margin type «{$type}»."),
        };
    }

    /** A price rounded as staff chose: to the cent half up, or up to an ending; zero stays zero. */
    public static function round(string $price, string $rounding): string
    {
        self::amount($price);
        if (!in_array($rounding, self::ROUNDING, true)) {
            throw new InvalidArgumentException("Unknown rounding «{$rounding}».");
        }
        if ($rounding === 'none' || bccomp($price, '0', self::SCALE) === 0) {
            return self::cents($price);
        }
        $whole = bcadd($price, '0', 0); // truncated
        $fraction = bcsub($price, $whole, self::SCALE);
        // The endings allowed within a unit, lowest first: the first at or above the exact fraction, else the next
        // unit's (a whole number already ends in .00).
        $endings = match ($rounding) {
            '.00' => ['0'],
            '.50' => ['0', '0.5'],
            default => ['0.99'],
        };
        foreach ($endings as $ending) {
            if (bccomp($fraction, $ending, self::SCALE) <= 0) {
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

    /** A cost in another currency of MyCS, exact, by the rate staff gave (1 unit of the cost's currency = rate units). */
    public static function convert(string $amount, string $rate): string
    {
        self::amount($amount);
        self::amount($rate);
        if (bccomp($rate, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('An exchange rate is above zero.');
        }

        return bcmul($amount, $rate, self::SCALE);
    }

    /** Two decimals, half up. */
    public static function cents(string $amount): string
    {
        self::amount($amount);

        return bcadd($amount, '0.005', 2);
    }

    /** A non-negative decimal amount (a registrar may never make a price negative: review F1). */
    public static function isAmount(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{1,12}(\.\d{1,8})?\z/', $value) === 1;
    }

    private static function amount(string $value): void
    {
        if (!self::isAmount($value)) {
            throw new InvalidArgumentException("Not an amount: «{$value}».");
        }
    }
}
