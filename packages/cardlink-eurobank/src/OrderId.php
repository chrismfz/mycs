<?php

namespace Nixpal\CardlinkEurobank;

/**
 * vPOS order ids: alphanumeric, at most 50 characters, unique per terminal (vPOS
 * refuses a reused one). Same shape as the WHMCS module ("<invoice>Inv<date>"), so
 * the bank's portal reads the same before and after the move; the two random digits
 * keep two attempts in the same second apart. A payment without invoices (credit)
 * uses "<client>Cl<date>".
 */
final class OrderId
{
    /**
     * @param  int|null  $invoiceId  first invoice being paid, if any
     * @param  int  $clientId
     * @param  int  $time  unix time
     * @param  int  $random  0-99
     */
    public static function generate($invoiceId, $clientId, $time, $random)
    {
        $prefix = $invoiceId !== null && (int) $invoiceId > 0
            ? (int) $invoiceId . 'Inv'
            : (int) $clientId . 'Cl';

        return $prefix . gmdate('ymdHis', (int) $time) . sprintf('%02d', ((int) $random) % 100);
    }

    public static function isValid($orderId)
    {
        return is_string($orderId) && preg_match('/^[A-Za-z0-9]{1,50}$/', $orderId) === 1;
    }
}
