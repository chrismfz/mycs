<?php

namespace Nixpal\CardlinkEurobank\Tests;

use Nixpal\CardlinkEurobank\Digest;
use Nixpal\CardlinkEurobank\PaymentRequest;
use PHPUnit\Framework\TestCase;

final class PaymentRequestTest extends TestCase
{
    private function params(array $override = [])
    {
        return $override + [
            'mid' => ' MID123 ',
            'lang' => 'el',
            'orderid' => '42Inv26100912000007',
            'orderDesc' => 'Invoice 42',
            'amount' => 1.5,
            'currency' => 'EUR',
            'payerEmail' => 'client@example.com',
            'billCountry' => 'gr',
            'billZip' => '10431',
            'billCity' => 'Αθήνα',
            'billAddress' => 'Οδός 1',
            'confirmUrl' => 'https://billing.example.com/callback/gw/1/cardlink_eurobank/?c=7',
            'cancelUrl' => 'https://billing.example.com/callback/gw/1/cardlink_eurobank/?c=7',
        ];
    }

    public function test_fields_are_in_the_whmcs_order_with_the_digest_last()
    {
        $fields = PaymentRequest::fields($this->params(), 'test-secret');

        $this->assertSame([
            'version', 'mid', 'lang', 'deviceCategory', 'orderid', 'orderDesc', 'orderAmount', 'currency',
            'payerEmail', 'billCountry', 'billZip', 'billCity', 'billAddress', 'confirmUrl', 'cancelUrl', 'digest',
        ], array_keys($fields));
        $this->assertSame('MID123', $fields['mid']);
        $this->assertSame('1.50', $fields['orderAmount']);
        $this->assertSame('GR', $fields['billCountry']);
        $this->assertSame('0', $fields['deviceCategory']);
    }

    public function test_digest_is_base64_of_raw_sha256_over_values_and_secret()
    {
        $fields = PaymentRequest::fields($this->params(), 'test-secret');
        $values = $fields;
        unset($values['digest']);

        $expected = base64_encode(hash('sha256', implode('', $values) . 'test-secret', true));
        $this->assertSame($expected, $fields['digest']);
        $this->assertSame($expected, Digest::request($fields, 'test-secret'));
    }

    public function test_long_values_are_cut_on_characters()
    {
        $fields = PaymentRequest::fields($this->params(['billAddress' => str_repeat('Ω', 150)]), 'test-secret');

        $this->assertSame(100, mb_strlen($fields['billAddress'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($fields['billAddress'], 'UTF-8'));
    }

    public function test_unknown_language_falls_back_to_greek()
    {
        $this->assertSame('el', PaymentRequest::fields($this->params(['lang' => 'de']), 's')['lang']);
        $this->assertSame('en', PaymentRequest::fields($this->params(['lang' => 'en']), 's')['lang']);
    }

    public function test_missing_secret_or_mid_or_bad_order_id_is_refused()
    {
        foreach ([[[], ''], [['mid' => ''], 's'], [['orderid' => '42-Inv'], 's']] as [$override, $secret]) {
            try {
                PaymentRequest::fields($this->params($override), $secret);
                $this->fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_endpoints()
    {
        $this->assertSame('https://eurocommerce-test.cardlink.gr/vpos/shophandlermpi', PaymentRequest::endpoint(true));
        $this->assertSame('https://vpos.eurocommerce.gr/vpos/shophandlermpi', PaymentRequest::endpoint(false));
    }
}
