<?php

namespace App\Services\Domains\Pricing;

use InvalidArgumentException;

/**
 * What staff chose on the import page (docs/domains.md D-6, as WHMCS's «TLD Import & Pricing Sync»). A fixed margin is
 * in the price's currency (+5 is +5 EUR on EUR prices, +5 USD on USD ones).
 */
final class TldImportOptions
{
    /**
     * @param  array<string, string>  $rates  currency code => units of it per 1 unit of the registrar's currency; a
     *                                        currency of MyCS without a rate gets no price from the import
     */
    public function __construct(
        public readonly string $marginType,
        public readonly string $margin,
        public readonly string $rounding = 'none',
        public readonly int $years = 1,
        public readonly bool $redemptionFee = false,
        public readonly bool $autoRegister = false,
        public readonly array $rates = [],
        // A price that is free (0.00) or not offered (none) is a decision: kept unless staff untick this (D-6).
        public readonly bool $keepFreeAndClosed = true,
    ) {
        if (!in_array($marginType, [TldPriceMath::MARGIN_PERCENT, TldPriceMath::MARGIN_FIXED], true)
            || !in_array($rounding, TldPriceMath::ROUNDING, true) || $years < 1 || $years > 10
            || !TldPriceMath::isAmount($margin)) {
            throw new InvalidArgumentException('Invalid import options.');
        }
        foreach ($rates as $rate) {
            if (!TldPriceMath::isAmount($rate) || bccomp($rate, '0', 8) <= 0) {
                throw new InvalidArgumentException('An exchange rate is a number above zero.');
            }
        }
    }
}
