<?php

namespace MyCS\EuVatRates;

/** A VAT rate as the tax rates keep it (decimal 5,2): «25.5», 25.5 and «25.50» are all «25.50». No float arithmetic. */
final class Rate
{
    public static function normalize(string|int|float $rate): string
    {
        $text = trim((string) $rate);
        if (!preg_match('/^(\d{1,3})(?:\.(\d{1,2})0*)?$/', $text, $parts)) {
            throw new \InvalidArgumentException("Not a VAT rate: {$text}");
        }

        return (int) $parts[1] . '.' . str_pad($parts[2] ?? '', 2, '0');
    }
}
