<?php

namespace Nixpal\PaypalVault;

/**
 * What Blesta stores for a vaulted PayPal account (accounts_cc), and the
 * idempotency key for charging it.
 */
final class StoredAccount
{
    /** Blesta needs a future expiration on a stored "card"; a PayPal account has none. */
    const EXPIRATION_YEARS = 20;

    /**
     * @param  int|null  $now  Unix time (tests)
     * @return array{client_reference_id:?string,reference_id:string,last4:string,expiration:string,type:string}|null
     *                                                                                                                null when the response is not a payment token
     */
    public static function fromPaymentToken(ApiResponse $response, $now = null)
    {
        if (!$response->ok() || empty($response->body['id'])) {
            return null;
        }
        $body = $response->body;
        $email = isset($body['payment_source']['paypal']['email_address'])
            ? (string) $body['payment_source']['paypal']['email_address']
            : '';

        return [
            'client_reference_id' => isset($body['customer']['id']) ? (string) $body['customer']['id'] : null,
            'reference_id' => (string) $body['id'],
            'last4' => self::hint($email),
            'expiration' => self::expiration($now),
            'type' => 'other',
        ];
    }

    /** yyyymm far enough ahead that Blesta never treats the account as expired. */
    public static function expiration($now = null)
    {
        $year = (int) date('Y', $now === null ? time() : (int) $now) + self::EXPIRATION_YEARS;

        return $year . '12';
    }

    /**
     * What Blesta shows as "ending in ...": the last 4 characters of the PayPal
     * email's local part, so the client can tell two PayPal accounts apart.
     */
    public static function hint($email)
    {
        $local = strstr((string) $email, '@', true);
        if ($local === false) {
            $local = (string) $email;
        }

        return $local === '' ? '' : substr($local, -4);
    }

    /**
     * PayPal-Request-Id for a charge. Same account, amount, currency, invoices and day =>
     * same id, so a retry after a timeout gets PayPal's first answer instead of charging
     * twice (TransactionResult rejects answers older than its replay window). A charge
     * without invoices (a credit payment) has nothing to dedupe on and gets a unique id.
     *
     * @param  array<int,array{id?:int|string,amount?:float|string}>|null  $invoices
     */
    public static function chargeRequestId($vaultId, $amount, $currency, $invoices, $now = null)
    {
        $parts = [];
        foreach ((array) $invoices as $invoice) {
            $parts[] = (isset($invoice['id']) ? $invoice['id'] : '') . '=' . (isset($invoice['amount']) ? $invoice['amount'] : '');
        }
        if ($parts === []) {
            return 'blesta-' . bin2hex(random_bytes(20));
        }
        sort($parts);

        return 'blesta-' . sha1(implode('|', [$vaultId, Money::format($amount, $currency), strtoupper((string) $currency), implode(',', $parts), self::day($now)]));
    }

    /** PayPal-Request-Id for a refund: same capture, amount and day => same id. */
    public static function refundRequestId($captureId, $amount, $currency, $now = null)
    {
        $value = $amount === null ? 'full' : Money::format($amount, $currency);

        return 'blesta-refund-' . sha1(implode('|', [$captureId, $value, self::day($now)]));
    }

    private static function day($now)
    {
        return date('Ymd', $now === null ? time() : (int) $now);
    }
}
