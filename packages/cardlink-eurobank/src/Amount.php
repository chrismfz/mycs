<?php

namespace Nixpal\CardlinkEurobank;

final class Amount
{
    /** "12.50" from 12.5, "12.5" or "12.50". */
    public static function format($amount)
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    /** Amount in cents, or null when the value is not a plain decimal number. */
    public static function cents($amount)
    {
        $amount = trim((string) $amount);
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $amount)) {
            return null;
        }

        return (int) round(((float) $amount) * 100);
    }
}
