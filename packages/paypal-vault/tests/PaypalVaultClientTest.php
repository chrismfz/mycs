<?php

namespace Nixpal\PaypalVault\Tests;

use Nixpal\PaypalVault\PaypalException;
use Nixpal\PaypalVault\PaypalVaultClient;
use PHPUnit\Framework\TestCase;

final class PaypalVaultClientTest extends TestCase
{
    private function client(FakeTransport $http, $sandbox = false, &$log = null, $clock = null)
    {
        $log = [];

        return new PaypalVaultClient($http, 'CID', 'SECRET', $sandbox, function () use (&$log) {
            $log[] = func_get_args();
        }, $clock);
    }

    public function test_authenticates_with_client_credentials_and_caches_the_token()
    {
        $http = (new FakeTransport)->queue(200, ['id' => 'T1'])->queue(200, ['id' => 'T2']);
        $client = $this->client($http);

        $client->getPaymentToken('T1');
        $client->getPaymentToken('T2');

        $this->assertCount(3, $http->requests);
        $auth = $http->requests[0];
        $this->assertSame('https://api-m.paypal.com/v1/oauth2/token', $auth['url']);
        $this->assertSame('Basic ' . base64_encode('CID:SECRET'), $auth['headers']['Authorization']);
        $this->assertSame('grant_type=client_credentials', $auth['body']);
        $this->assertSame('Bearer AT-0', $http->requests[1]['headers']['Authorization']);
        $this->assertSame('Bearer AT-0', $http->requests[2]['headers']['Authorization']);
    }

    public function test_refreshes_the_token_before_it_expires()
    {
        $now = 1000;
        $http = (new FakeTransport)->queue(200, ['id' => 'T1']);
        $client = $this->client($http, false, $log, function () use (&$now) {
            return $now;
        });
        $client->getPaymentToken('T1');

        $now += 32400 - 30; // inside the last minute of the token's life
        $http->queueToken()->queue(200, ['id' => 'T1']);
        $client->getPaymentToken('T1');

        $this->assertSame('https://api-m.paypal.com/v1/oauth2/token', $http->requests[2]['url']);
    }

    public function test_sandbox_uses_the_sandbox_host()
    {
        $http = (new FakeTransport)->queue(200, ['id' => 'T1']);
        $this->client($http, true)->getPaymentToken('T1');

        $this->assertSame('https://api-m.sandbox.paypal.com/v3/vault/payment-tokens/T1', $http->last()['url']);
    }

    public function test_failed_authentication_throws_without_leaking_credentials()
    {
        $http = new FakeTransport;
        $http->requests = [];
        $reflection = new \ReflectionProperty($http, 'responses');
        $reflection->setAccessible(true);
        $reflection->setValue($http, [[401, '{"error":"invalid_client"}']]);

        try {
            $this->client($http)->getPaymentToken('T1');
            $this->fail('expected PaypalException');
        } catch (PaypalException $e) {
            $this->assertStringContainsString('401', $e->getMessage());
            $this->assertStringNotContainsString('SECRET', $e->getMessage());
        }
    }

    public function test_the_logger_never_sees_the_access_token()
    {
        $http = (new FakeTransport)->queue(200, ['id' => 'T1']);
        $client = $this->client($http, false, $log);
        $client->getPaymentToken('T1');

        $this->assertStringNotContainsString('AT-0', json_encode($log));
        $this->assertStringNotContainsString('SECRET', json_encode($log));
        $this->assertCount(2, $log);
    }

    public function test_create_setup_token_asks_for_a_merchant_initiated_paypal_vault()
    {
        $http = (new FakeTransport)->queue(201, ['id' => 'ST1', 'status' => 'PAYER_ACTION_REQUIRED']);
        $response = $this->client($http)->createSetupToken('https://b/return', 'https://b/cancel', 'nixpal', 'CUST1');

        $this->assertSame('ST1', $response->body['id']);
        $this->assertSame('https://api-m.paypal.com/v3/vault/setup-tokens', $http->last()['url']);
        $body = $http->lastJson();
        $this->assertSame('MERCHANT', $body['payment_source']['paypal']['usage_type']);
        $this->assertSame('https://b/return', $body['payment_source']['paypal']['experience_context']['return_url']);
        $this->assertSame('NO_SHIPPING', $body['payment_source']['paypal']['experience_context']['shipping_preference']);
        $this->assertSame(['id' => 'CUST1'], $body['customer']);
    }

    public function test_create_setup_token_without_customer_omits_it()
    {
        $http = (new FakeTransport)->queue(201, ['id' => 'ST1']);
        $this->client($http)->createSetupToken('https://b/r', 'https://b/c');

        $this->assertArrayNotHasKey('customer', $http->lastJson());
        $this->assertArrayNotHasKey('brand_name', $http->lastJson()['payment_source']['paypal']['experience_context']);
    }

    public function test_create_payment_token_from_setup_token()
    {
        $http = (new FakeTransport)->queue(201, ['id' => 'VAULT1']);
        $this->client($http)->createPaymentToken('ST1');

        $this->assertSame('https://api-m.paypal.com/v3/vault/payment-tokens', $http->last()['url']);
        $this->assertSame(
            ['payment_source' => ['token' => ['id' => 'ST1', 'type' => 'SETUP_TOKEN']]],
            $http->lastJson()
        );
    }

    public function test_charge_sends_vault_id_amount_and_idempotency_key()
    {
        $http = (new FakeTransport)->queue(201, ['id' => 'O1', 'status' => 'COMPLETED']);
        $this->client($http)->charge('VAULT1', 9.5, 'eur', 'REQ-1', 'Invoice 32454', '17', '32454');

        $request = $http->last();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api-m.paypal.com/v2/checkout/orders', $request['url']);
        $this->assertSame('REQ-1', $request['headers']['PayPal-Request-Id']);
        $body = $http->lastJson();
        $this->assertSame('CAPTURE', $body['intent']);
        $this->assertSame(['vault_id' => 'VAULT1'], $body['payment_source']['paypal']);
        $this->assertSame(['currency_code' => 'EUR', 'value' => '9.50'], $body['purchase_units'][0]['amount']);
        $this->assertSame('32454', $body['purchase_units'][0]['invoice_id']);
        $this->assertSame('17', $body['purchase_units'][0]['custom_id']);
    }

    public function test_charge_in_zero_decimal_currency_has_no_decimals()
    {
        $http = (new FakeTransport)->queue(201, ['id' => 'O1']);
        $this->client($http)->charge('VAULT1', 1234.56, 'JPY', 'REQ-1');

        $this->assertSame('1235', $http->lastJson()['purchase_units'][0]['amount']['value']);
    }

    public function test_full_refund_sends_an_empty_object()
    {
        $http = (new FakeTransport)->queue(201, ['id' => 'R1', 'status' => 'COMPLETED']);
        $this->client($http)->refund('CAP1', null, 'EUR', 'REQ-R');

        $this->assertSame('https://api-m.paypal.com/v2/payments/captures/CAP1/refund', $http->last()['url']);
        $this->assertSame('{}', $http->last()['body']);
    }

    public function test_partial_refund_sends_the_amount()
    {
        $http = (new FakeTransport)->queue(201, ['id' => 'R1', 'status' => 'COMPLETED']);
        $this->client($http)->refund('CAP1', '5', 'EUR', 'REQ-R');

        $this->assertSame(['amount' => ['currency_code' => 'EUR', 'value' => '5.00']], $http->lastJson());
    }

    public function test_delete_handles_an_empty_body()
    {
        $http = (new FakeTransport)->queue(204, '');
        $response = $this->client($http)->deletePaymentToken('VAULT/1');

        $this->assertTrue($response->ok());
        $this->assertSame([], $response->body);
        $this->assertSame('DELETE', $http->last()['method']);
        $this->assertStringEndsWith('/v3/vault/payment-tokens/VAULT%2F1', $http->last()['url']);
    }
}
