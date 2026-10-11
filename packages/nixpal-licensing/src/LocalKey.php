<?php

namespace Nixpal\Licensing;

/**
 * WHMCS Licensing Addon compatible "local key".
 *
 * The algorithm must stay identical to reference/whmcs/zlic.php: every deployed
 * client (Go agent/node, C++ node, Laravel controller) validates the key with
 * the product PSK and caches it, so any drift invalidates the field.
 *
 *   data  = base64(json_encode(results + checkdate))
 *   body  = strrev(md5(checkdate . secret) . data)
 *   key   = wordwrap(body . md5(body . secret), 80, "\n", true)
 *
 * json_encode() is called with default flags on purpose (escaped slashes and
 * \uXXXX unicode), exactly as zlic.php does.
 */
final class LocalKey
{
    const LINE_WIDTH = 80;

    /**
     * What zlic.php does for an Active result: add checkdate, then the local key.
     *
     * @param  array  $results  verify tags in their original order (incl. md5hash)
     * @param  string  $secret  product PSK
     * @param  string  $checkdate  Ymd
     * @return array $results + checkdate + localkey
     */
    public static function attach(array $results, $secret, $checkdate)
    {
        $results['checkdate'] = $checkdate;
        $results['localkey'] = self::encode($results, $secret, $checkdate);

        return $results;
    }

    /**
     * @param  array  $payload  must already contain 'checkdate'
     * @param  string  $secret
     * @param  string  $checkdate  Ymd
     * @return string
     */
    public static function encode(array $payload, $secret, $checkdate)
    {
        $data = base64_encode(json_encode($payload));
        $body = strrev(md5($checkdate . $secret) . $data);

        return wordwrap($body . md5($body . $secret), self::LINE_WIDTH, "\n", true);
    }

    /**
     * Validates both hashes and returns the payload, or null when the key was
     * not produced with this secret or has been tampered with.
     *
     * Line breaks are ignored, as in zlic.php, the C++ client and the Laravel
     * controller.
     *
     * @param  string  $localkey
     * @param  string  $secret
     * @return array|null
     */
    public static function decode($localkey, $secret)
    {
        $localkey = str_replace(["\r", "\n"], '', (string) $localkey);
        if (strlen($localkey) <= 64) {
            return null;
        }

        $body = substr($localkey, 0, -32);
        if (!hash_equals(md5($body . $secret), substr($localkey, -32))) {
            return null;
        }

        $body = strrev($body);
        $dateHash = substr($body, 0, 32);
        $payload = json_decode((string) base64_decode(substr($body, 32), true), true);
        if (!is_array($payload) || !isset($payload['checkdate'])) {
            return null;
        }
        if (!hash_equals(md5($payload['checkdate'] . $secret), $dateHash)) {
            return null;
        }

        return $payload;
    }
}
