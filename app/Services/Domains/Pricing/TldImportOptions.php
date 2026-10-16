<?php

namespace App\Services\Domains\Pricing;

use InvalidArgumentException;

/**
 * What staff chose on the import page (docs/domains.md D-6, as WHMCS's «TLD Import & Pricing Sync»).
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
    ) {
        if (!in_array($marginType, [TldPriceMath::MARGIN_PERCENT, TldPriceMath::MARGIN_FIXED], true)
            || !in_array($rounding, TldPriceMath::ROUNDING, true) || $years < 1 || $years > 10
            || !preg_match('/^\d+(\.\d+)?$/', $margin)) {
            throw new InvalidArgumentException('Invalid import options.');
        }
    }
}
