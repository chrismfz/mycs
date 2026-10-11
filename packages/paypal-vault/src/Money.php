<?php

namespace Nixpal\PaypalVault;

final class Money
{
    /** Currencies PayPal accepts without decimals. */
    const ZERO_DECIMAL = ['HUF', 'JPY', 'TWD'];

    /**
     * Amount as the string PayPal expects ("12.50", or "1250" for zero-decimal currencies).
     */
    public static function format($amount, $currency)
    {
        if (in_array(strtoupper((string) $currency), self::ZERO_DECIMAL, true)) {
            return number_format(round((float) $amount), 0, '.', '');
        }

        return number_format((float) $amount, 2, '.', '');
    }
}
