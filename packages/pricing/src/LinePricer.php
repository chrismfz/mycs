<?php

namespace MyCS\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Prices one unit of an order line (MyCS docs/orders.md): the catalog price and setup fee, less a coupon, then tax.
 * Everything in decimal, each amount rounded half-up to cents once. The discount is taken from the catalog amounts,
 * so it is the same whether the line is priced at checkout, on a recalculation or on an upgrade (CART-8).
 */
final class LinePricer
{
    public const TAX_INCLUSIVE = 'inclusive';

    public const TAX_EXCLUSIVE = 'exclusive';

    /**
     * @param  string|null  $taxRate  percent (e.g. "24"); null or zero for no tax
     * @param  string  $taxMode  inclusive: the catalog amounts already contain the tax; exclusive: it is added
     */
    public static function price(string $price, string $setupFee, ?Discount $discount = null, ?string $taxRate = null, string $taxMode = self::TAX_EXCLUSIVE): LinePrice
    {
        if (!in_array($taxMode, [self::TAX_INCLUSIVE, self::TAX_EXCLUSIVE], true)) {
            throw new InvalidArgumentException("Unknown tax mode «{$taxMode}».");
        }
        $price = self::money($price);
        $setupFee = self::money($setupFee);
        if ($price->isNegative() || $setupFee->isNegative()) {
            throw new InvalidArgumentException('A catalog amount cannot be negative.');
        }

        [$priceDiscount, $setupFeeDiscount] = self::discounts($price, $setupFee, $discount);
        $price = $price->minus($priceDiscount);
        $setupFee = $setupFee->minus($setupFeeDiscount);

        $rate = $taxRate === null ? BigDecimal::zero() : BigDecimal::of($taxRate);
        if ($rate->isNegative()) {
            throw new InvalidArgumentException('A tax rate cannot be negative.');
        }
        [$priceTax, $grossPrice] = self::tax($price, $rate, $taxMode);
        [$setupFeeTax, $grossSetupFee] = self::tax($setupFee, $rate, $taxMode);

        return new LinePrice(
            (string) $price, (string) $setupFee, (string) $priceDiscount, (string) $setupFeeDiscount,
            (string) $priceTax, (string) $setupFeeTax, (string) $grossPrice, (string) $grossSetupFee,
            (string) $grossPrice->minus($priceTax), (string) $grossSetupFee->minus($setupFeeTax),
        );
    }

    /**
     * An invoice line (Δ1): the net unit amount × quantity, then the tax on that, rounded once per line (CART-14: not
     * per unit). A negative amount (a discount line) gets a negative tax.
     *
     * @return array{net: string, tax: string, total: string}
     */
    public static function invoiceLine(string $unitNet, int $quantity, ?string $taxRate): array
    {
        $net = BigDecimal::of($unitNet)->multipliedBy($quantity)->toScale(2, RoundingMode::HalfUp);
        $rate = $taxRate === null ? BigDecimal::zero() : BigDecimal::of($taxRate);
        if ($rate->isNegative()) {
            throw new InvalidArgumentException('A tax rate cannot be negative.');
        }
        $tax = $net->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HalfUp);

        return ['net' => (string) $net, 'tax' => (string) $tax, 'total' => (string) $net->plus($tax)];
    }

    /** @return array{BigDecimal, BigDecimal} */
    private static function discounts(BigDecimal $price, BigDecimal $setupFee, ?Discount $discount): array
    {
        $zero = self::money('0');
        if ($discount === null) {
            return [$zero, $zero];
        }
        $value = BigDecimal::of($discount->value);

        if ($discount->type === 'percentage') {
            $value = BigDecimal::min($value, BigDecimal::of(100));
            $off = fn (BigDecimal $amount) => $amount->multipliedBy($value)->dividedBy(100, 2, RoundingMode::HalfUp);

            return [
                $discount->appliesToPrice() ? $off($price) : $zero,
                $discount->appliesToSetupFee() ? $off($setupFee) : $zero,
            ];
        }

        // Fixed: one amount per line, the price first (it is the part that recurs), then the setup fee.
        $left = self::money((string) $value);
        $priceDiscount = $discount->appliesToPrice() ? BigDecimal::min($left, $price) : $zero;
        $left = $left->minus($priceDiscount);
        $setupFeeDiscount = $discount->appliesToSetupFee() ? BigDecimal::min($left, $setupFee) : $zero;

        return [$priceDiscount, $setupFeeDiscount];
    }

    /** @return array{BigDecimal, BigDecimal} the tax and the gross amount */
    private static function tax(BigDecimal $amount, BigDecimal $rate, string $taxMode): array
    {
        if ($rate->isZero()) {
            return [self::money('0'), $amount];
        }
        if ($taxMode === self::TAX_EXCLUSIVE) {
            $tax = $amount->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HalfUp);

            return [$tax, $amount->plus($tax)];
        }
        $net = $amount->multipliedBy(100)->dividedBy($rate->plus(100), 2, RoundingMode::HalfUp);

        return [$amount->minus($net), $amount];
    }

    private static function money(string $amount): BigDecimal
    {
        return BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);
    }
}
