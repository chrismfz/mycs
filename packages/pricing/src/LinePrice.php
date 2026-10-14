<?php

namespace MyCS\Pricing;

use Brick\Math\BigDecimal;

/**
 * One line, priced. Amounts are decimal strings with two places. «Catalog» amounts are in the catalog's terms (net when
 * tax is exclusive, gross when inclusive); «net» amounts are without tax (what services and invoice lines store, Δ1);
 * «gross» amounts are what the customer pays for one unit.
 */
final class LinePrice
{
    public function __construct(
        public readonly string $price,
        public readonly string $setupFee,
        public readonly string $priceDiscount,
        public readonly string $setupFeeDiscount,
        public readonly string $priceTax,
        public readonly string $setupFeeTax,
        public readonly string $grossPrice,
        public readonly string $grossSetupFee,
        public readonly string $netPrice,
        public readonly string $netSetupFee,
    ) {}

    /** The first invoice's net unit amount: the (discounted) price and setup fee without tax. */
    public function firstPaymentNet(): string
    {
        return (string) BigDecimal::of($this->netPrice)->plus($this->netSetupFee);
    }

    /** What the first invoice asks for one unit: the discounted price and setup fee, with tax. */
    public function firstPayment(): string
    {
        return (string) BigDecimal::of($this->grossPrice)->plus($this->grossSetupFee);
    }

    public function hasDiscount(): bool
    {
        return BigDecimal::of($this->priceDiscount)->plus($this->setupFeeDiscount)->isPositive();
    }
}
