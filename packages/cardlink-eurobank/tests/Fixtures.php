<?php

namespace Nixpal\CardlinkEurobank\Tests;

/** Fake terminal values and returns shaped like the real ones (names/order from myip). */
final class Fixtures
{
    const MID = 'MID123';

    const SECRET = 'test-secret';

    /** A signed return in the order vPOS posts it. */
    public static function capture(array $override = [], $leg = 'browser', $secret = self::SECRET)
    {
        $fields = array_merge([
            'version' => '2',
            'mid' => self::MID,
            'orderid' => '42Inv26100912000007',
            'status' => 'CAPTURED',
            'orderAmount' => '1.50',
            'currency' => 'EUR',
            'paymentTotal' => '1.50',
            'message' => 'OK, Approved',
            'riskScore' => '0',
            'payMethod' => 'visa',
            'txId' => '900000001',
            'paymentRef' => '123456',
        ], $override);
        $fields = array_filter($fields, function ($v) {
            return $v !== null;
        });

        return self::sign($fields, $leg, $secret);
    }

    /** Signs the values in the given order (what vPOS does) and adds the leg's noise. */
    public static function sign(array $fields, $leg = 'browser', $secret = self::SECRET)
    {
        $digest = base64_encode(hash('sha256', implode('', $fields) . $secret, true));
        if ($leg === 'modirum') {
            return ['_charset_' => 'UTF-8'] + $fields + ['digest' => $digest, 'submitButton' => 'Continue'];
        }

        return $fields + ['digest' => $digest];
    }
}
