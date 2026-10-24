<?php

namespace MyCS\BankAlerts;

/**
 * A bank's written amount as a decimal string with two digits: «1.234,56» (Eurobank) and «1,234.56» (Piraeus). At most
 * 15 integer digits, what the columns (decimal 17,2) hold: a longer one is no amount.
 */
final class Amount
{
    /** @param  string  $decimal  the decimal mark the bank writes: «,» or «.» */
    public static function parse(string $written, string $decimal): ?string
    {
        $written = trim(str_replace(["\u{a0}", ' '], '', $written));
        $thousands = $decimal === ',' ? '.' : ',';
        if (preg_match('/^(-)?(\d{1,3}(?:' . preg_quote($thousands, '/') . '\d{3})*|\d+)(?:' . preg_quote($decimal, '/') . '(\d{1,2}))?$/', $written, $m) !== 1) {
            return null;
        }
        $whole = ltrim(str_replace($thousands, '', $m[2]), '0') ?: '0';
        if (strlen($whole) > 15) {
            return null;
        }
        $cents = str_pad($m[3] ?? '', 2, '0');

        return $m[1] . $whole . '.' . $cents;
    }
}
