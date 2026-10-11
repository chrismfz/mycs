<?php

namespace Nixpal\CardlinkEurobank;

/**
 * The signed form that sends the customer to the Cardlink/Modirum vPOS hosted page
 * (card, Apple/Google Pay and IRIS on one page).
 *
 * Field order is load-bearing: vPOS recomputes the digest by concatenating the posted
 * values in this order. It is the order of our WHMCS module (eurobanklib.php), which
 * has been taking payments on myip for years, and of Ekdosi's EurobankGateway.
 */
final class PaymentRequest
{
    const ENDPOINT_LIVE = 'https://vpos.eurocommerce.gr/vpos/shophandlermpi';

    const ENDPOINT_TEST = 'https://eurocommerce-test.cardlink.gr/vpos/shophandlermpi';

    /** Maximum lengths of the free-text fields (vPOS refuses longer values). */
    const MAX_LENGTHS = [
        'orderDesc' => 128,
        'payerEmail' => 64,
        'billCountry' => 2,
        'billZip' => 16,
        'billCity' => 64,
        'billAddress' => 100,
    ];

    /**
     * @param array{
     *   mid?:string, lang?:string, orderid?:string, orderDesc?:string, amount:string|float,
     *   currency?:string, payerEmail?:string, billCountry?:string, billZip?:string,
     *   billCity?:string, billAddress?:string, confirmUrl:string, cancelUrl:string
     * } $params  mid and orderid are required; checked below, since callers pass raw input
     * @return array<string,string> the form fields, digest last
     */
    public static function fields(array $params, $secret)
    {
        if ((string) $secret === '') {
            throw new \InvalidArgumentException('Cardlink shared secret is missing');
        }
        $mid = trim((string) ($params['mid'] ?? ''));
        if ($mid === '') {
            throw new \InvalidArgumentException('Cardlink merchant id is missing');
        }
        if (!OrderId::isValid($params['orderid'] ?? '')) {
            throw new \InvalidArgumentException('Invalid Cardlink order id');
        }

        $fields = [
            'version' => '2',
            'mid' => $mid,
            'lang' => (string) ($params['lang'] ?? 'el') === 'en' ? 'en' : 'el',
            'deviceCategory' => '0',
            'orderid' => (string) $params['orderid'],
            'orderDesc' => (string) ($params['orderDesc'] ?? ''),
            'orderAmount' => Amount::format($params['amount']),
            'currency' => (string) ($params['currency'] ?? 'EUR'),
            'payerEmail' => (string) ($params['payerEmail'] ?? ''),
            'billCountry' => strtoupper((string) ($params['billCountry'] ?? '')),
            'billZip' => (string) ($params['billZip'] ?? ''),
            'billCity' => (string) ($params['billCity'] ?? ''),
            'billAddress' => (string) ($params['billAddress'] ?? ''),
            'confirmUrl' => (string) $params['confirmUrl'],
            'cancelUrl' => (string) $params['cancelUrl'],
        ];
        foreach (self::MAX_LENGTHS as $key => $max) {
            $fields[$key] = self::cut(trim($fields[$key]), $max);
        }
        $fields['digest'] = Digest::request($fields, $secret);

        return $fields;
    }

    public static function endpoint($test)
    {
        return $test ? self::ENDPOINT_TEST : self::ENDPOINT_LIVE;
    }

    private static function cut($value, $max)
    {
        // Drop invalid UTF-8 first so a cut never leaves half a character behind.
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        $value = $clean === false ? $value : $clean;
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }

        return substr($value, 0, $max);
    }
}
