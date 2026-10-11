<?php

namespace Nixpal\CardlinkEurobank;

/**
 * Verifies a vPOS return against the shared secret and the configured terminal.
 *
 * The digest signs the field VALUES concatenated without delimiters, so the field
 * boundaries are not signed. Hashing "whatever arrived, in the order it arrived" (what
 * the WHMCS callback did) accepts any body that re-partitions the same bytes, e.g. a
 * filler field, or part of "mid" moved into "orderid", which can re-aim one genuine
 * capture at another order. Ported from Ekdosi's EurobankGateway, which closed that:
 *  - unknown fields are refused before hashing;
 *  - the sign string is rebuilt in the fixed vPOS order (only the keys present);
 *  - "mid" must equal the configured terminal and "currency" must be present, so
 *    "orderid" and "orderAmount" sit between fixed anchors.
 *
 * Field list checked against real myip returns (rigel debug log, 2026-10-09, names
 * only): card and IRIS captures post all twelve fields below; refused/cancelled ones
 * leave out payMethod and sometimes paymentRef; the background "Modirum HTTPClient"
 * leg adds _charset_ and submitButton. If vPOS ever adds a field, every return is
 * refused (fail closed) and the gateway log shows the posted names.
 */
final class ReturnVerifier
{
    /** Posted, but not part of the digest. */
    const EXCLUDED = ['_charset_', 'digest', 'submitButton'];

    /** The order vPOS signs the return in. */
    const FIELD_ORDER = [
        'version', 'mid', 'orderid', 'status', 'orderAmount', 'currency',
        'paymentTotal', 'message', 'riskScore', 'payMethod', 'txId', 'paymentRef',
        // Not seen on our returns; tolerated as Ekdosi does.
        'transactionId',
    ];

    /**
     * @param  array<string,mixed>  $posted  the POST body as parsed (posted order kept)
     * @param  string  $secret  shared secret
     * @param  string  $merchantId  the configured terminal (mid)
     */
    public static function verify(array $posted, $secret, $merchantId)
    {
        $keys = array_map(function ($key) {
            return substr((string) $key, 0, 40);
        }, array_slice(array_keys($posted), 0, 40));
        $signed = array_diff_key($posted, array_flip(self::EXCLUDED));
        $postedKeys = array_values(array_diff($keys, self::EXCLUDED));

        foreach ($signed as $value) {
            if (!is_scalar($value)) {
                return new VposReturn(false, 'bad_value', [], $postedKeys);
            }
        }
        $fields = array_map('strval', $signed);

        if ((string) $secret === '') {
            return new VposReturn(false, 'no_secret', $fields, $postedKeys);
        }
        $sent = isset($posted['digest']) && is_string($posted['digest']) ? $posted['digest'] : '';
        if ($sent === '') {
            return new VposReturn(false, 'no_digest', $fields, $postedKeys);
        }
        if (array_diff(array_keys($fields), self::FIELD_ORDER)) {
            return new VposReturn(false, 'unknown_fields', $fields, $postedKeys);
        }

        $signString = '';
        foreach (self::FIELD_ORDER as $name) {
            if (array_key_exists($name, $fields)) {
                $signString .= $fields[$name];
            }
        }
        if (!hash_equals(Digest::response($signString, $secret), $sent)) {
            // Diagnostic only: does the posted order verify? Then the secret is right
            // and FIELD_ORDER is not; otherwise look at the secret.
            $postedOrder = hash_equals(Digest::response(implode('', $fields), $secret), $sent);

            return new VposReturn(false, 'digest_mismatch', $fields, $postedKeys, $postedOrder);
        }

        // The reported mid is compared raw: trimming it would let bytes move across
        // the very boundary this check pins.
        $mid = trim((string) $merchantId);
        if ($mid === '' || !isset($fields['mid']) || !hash_equals($mid, $fields['mid'])) {
            return new VposReturn(false, 'mid_mismatch', $fields, $postedKeys);
        }
        if (!isset($fields['currency']) || trim($fields['currency']) === '') {
            return new VposReturn(false, 'currency_missing', $fields, $postedKeys);
        }
        if (!OrderId::isValid($fields['orderid'] ?? null)) {
            return new VposReturn(false, 'bad_orderid', $fields, $postedKeys);
        }

        return new VposReturn(true, 'ok', $fields, $postedKeys);
    }
}
