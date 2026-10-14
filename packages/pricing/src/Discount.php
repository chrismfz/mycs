<?php

namespace MyCS\Pricing;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * A coupon's discount on one line. A percentage is taken from each amount it applies to; a fixed amount is one amount
 * per line (CART-8): from the recurring price first, what is left from the setup fee, never below zero.
 */
final class Discount
{
    public const TYPES = ['percentage', 'fixed'];

    public const APPLIES_TO = ['all', 'price', 'setup_fee'];

    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly string $appliesTo = 'all',
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown discount type «{$type}».");
        }
        if (!in_array($appliesTo, self::APPLIES_TO, true)) {
            throw new InvalidArgumentException("Unknown discount target «{$appliesTo}».");
        }
        if (BigDecimal::of($value)->isNegative()) {
            throw new InvalidArgumentException('A discount cannot be negative.');
        }
    }

    public function appliesToPrice(): bool
    {
        return $this->appliesTo !== 'setup_fee';
    }

    public function appliesToSetupFee(): bool
    {
        return $this->appliesTo !== 'price';
    }
}
