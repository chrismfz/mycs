<?php

namespace Nixpal\CardlinkEurobank;

/**
 * What the return needs besides the vPOS fields (client, invoices, expected amount),
 * carried in the confirm/cancel URL query and signed by us.
 *
 * vPOS posts the return to the confirmUrl we sent, query included (both the browser
 * and the background leg), but its return digest does not cover that URL. So the
 * query carries an HMAC (keyed with the shared secret) that binds it to the order id;
 * the order id itself is in the vPOS-signed body. A query lifted from one payment
 * therefore cannot be paired with another payment's return.
 *
 * Keep this check mandatory. Besides naming the client, it is what makes request digests
 * harmless as an oracle: the request and return digests share one construction, and the
 * customer sees request digests over values they partly choose (email, address); a
 * return forged from one would need an order id we never signed a ticket for.
 */
final class ReturnTicket
{
    /** Longest invoice list kept in the URL; invoices past it stay as account credit. */
    const MAX_INVOICES_LENGTH = 120;

    const VERSION = '1';

    /**
     * @param  array<int,array{id:int|string,amount:float|string}>  $invoices
     * @return array{c:string,i:string,a:string,u:string,s:string} query parameters
     */
    public static function create($orderId, $clientId, array $invoices, $amount, $currency, $secret)
    {
        $query = [
            'c' => (string) (int) $clientId,
            'i' => self::serializeInvoices($invoices),
            'a' => Amount::format($amount),
            'u' => strtoupper((string) $currency),
        ];
        $query['s'] = self::sign($orderId, $query, $secret);

        return $query;
    }

    /**
     * The ticket from a return's query, or null when it is missing, malformed, or not
     * ours for this order id.
     *
     * @param  array<string,mixed>  $get
     * @return array{client_id:int,invoices:array<int,array{id:int,amount:string}>,amount:string,currency:string}|null
     */
    public static function read(array $get, $orderId, $secret)
    {
        foreach (['c', 'i', 'a', 'u', 's'] as $key) {
            if (!isset($get[$key]) || !is_string($get[$key])) {
                return null;
            }
        }
        if ((string) $secret === '' || !OrderId::isValid($orderId)) {
            return null;
        }
        $query = ['c' => $get['c'], 'i' => $get['i'], 'a' => $get['a'], 'u' => $get['u']];
        if (!hash_equals(self::sign($orderId, $query, $secret), $get['s'])) {
            return null;
        }
        if (!ctype_digit($query['c']) || (int) $query['c'] <= 0) {
            return null;
        }

        return [
            'client_id' => (int) $query['c'],
            'invoices' => self::parseInvoices($query['i']),
            'amount' => $query['a'],
            'currency' => $query['u'],
        ];
    }

    /**
     * "12_10.00-13_5.50": only URL-unreserved characters, so the length is the same
     * encoded; cut at whole entries to MAX_INVOICES_LENGTH.
     */
    public static function serializeInvoices(array $invoices)
    {
        $out = '';
        foreach ($invoices as $invoice) {
            $id = (int) ($invoice['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $entry = ($out === '' ? '' : '-') . $id . '_' . Amount::format($invoice['amount'] ?? 0);
            if (strlen($out) + strlen($entry) > self::MAX_INVOICES_LENGTH) {
                break;
            }
            $out .= $entry;
        }

        return $out;
    }

    /** @return array<int,array{id:int,amount:string}> */
    public static function parseInvoices($value)
    {
        $invoices = [];
        foreach (explode('-', (string) $value) as $pair) {
            if (preg_match('/^(\d+)_(\d+\.\d{2})$/', $pair, $m)) {
                $invoices[] = ['id' => (int) $m[1], 'amount' => $m[2]];
            }
        }

        return $invoices;
    }

    private static function sign($orderId, array $query, $secret)
    {
        $message = implode("\n", [
            'blesta-cardlink-return', self::VERSION, (string) $orderId,
            $query['c'], $query['i'], $query['a'], $query['u'],
        ]);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $message, (string) $secret, true)), '+/', '-_'), '=');
    }
}
