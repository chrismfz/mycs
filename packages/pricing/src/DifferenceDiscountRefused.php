<?php

namespace MyCS\Pricing;

use InvalidArgumentException;

/** A discount DifferenceDiscount does not give: its reason is one of its REFUSED_* constants. */
final class DifferenceDiscountRefused extends InvalidArgumentException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Discount refused: {$reason}.");
    }
}
