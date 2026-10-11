<?php

namespace Nixpal\PaypalVault;

use Nixpal\PaypalVault\Http\Transport;

/**
 * PayPal REST client for vaulted PayPal accounts:
 *  - Vault v3: setup token (payer approval) -> payment token (the stored "vault id")
 *  - Orders v2: merchant-initiated charge on a vault id, refunds on its capture
 *
 * The same REST app credentials that the WHMCS "PayPal Payments" (paypal_ppcpv)
 * gateway uses can read and charge the tokens WHMCS vaulted (verified on nixpal
 * 2026-10-08, docs/importer.md), so imported tokens keep working.
 */
final class PaypalVaultClient
{
    const LIVE = 'https://api-m.paypal.com';

    const SANDBOX = 'https://api-m.sandbox.paypal.com';

    /** @var Transport */
    private $http;

    /** @var string */
    private $clientId;

    /** @var string */
    private $secret;

    /** @var string */
    private $base;

    /** @var callable(string,string,?string,int,string):void|null */
    private $logger;

    /** @var callable(): int */
    private $clock;

    /** @var string|null */
    private $token;

    /** @var int */
    private $tokenExpires = 0;

    /**
     * @param  callable|null  $logger  fn(method, url, requestBody, status, responseBody); the client
     *                                 never passes credentials or the access token to it
     */
    public function __construct(Transport $http, $clientId, $secret, $sandbox = false, $logger = null, $clock = null)
    {
        $this->http = $http;
        $this->clientId = (string) $clientId;
        $this->secret = (string) $secret;
        $this->base = $sandbox ? self::SANDBOX : self::LIVE;
        $this->logger = $logger;
        $this->clock = $clock ?: 'time';
    }

    /**
     * Starts saving a PayPal account: the payer approves this setup token in the JS SDK popup.
     *
     * @param  string|null  $customerId  PayPal customer id to attach the new token to (a client's earlier token)
     * @return ApiResponse body.id is the setup token id
     */
    public function createSetupToken($returnUrl, $cancelUrl, $brandName = null, $customerId = null)
    {
        $context = array_filter([
            'brand_name' => $brandName ? substr((string) $brandName, 0, 127) : null,
            'shipping_preference' => 'NO_SHIPPING',
            'return_url' => (string) $returnUrl,
            'cancel_url' => (string) $cancelUrl,
        ]);
        $body = [
            'payment_source' => [
                'paypal' => [
                    'usage_type' => 'MERCHANT',
                    'customer_type' => 'CONSUMER',
                    // Renewals are billed in advance, on a schedule, with amounts that can change.
                    'usage_pattern' => 'RECURRING_PREPAID',
                    'experience_context' => $context,
                ],
            ],
        ];
        if ($customerId) {
            $body['customer'] = ['id' => (string) $customerId];
        }

        return $this->call('POST', '/v3/vault/setup-tokens', $body);
    }

    /**
     * Turns an approved setup token into a payment token.
     *
     * @return ApiResponse body.id = vault id, body.customer.id, body.payment_source.paypal.email_address
     */
    public function createPaymentToken($setupTokenId)
    {
        return $this->call('POST', '/v3/vault/payment-tokens', [
            'payment_source' => ['token' => ['id' => (string) $setupTokenId, 'type' => 'SETUP_TOKEN']],
        ]);
    }

    public function getPaymentToken($vaultId)
    {
        return $this->call('GET', '/v3/vault/payment-tokens/' . rawurlencode((string) $vaultId));
    }

    public function deletePaymentToken($vaultId)
    {
        return $this->call('DELETE', '/v3/vault/payment-tokens/' . rawurlencode((string) $vaultId));
    }

    /**
     * Merchant-initiated charge on a vaulted PayPal account: one order, captured at once.
     *
     * @param  string  $requestId  PayPal-Request-Id: a retry with the same id returns the first
     *                             result instead of charging twice
     */
    public function charge($vaultId, $amount, $currency, $requestId, $description = null, $customId = null, $invoiceId = null)
    {
        $unit = array_filter([
            'amount' => ['currency_code' => strtoupper((string) $currency), 'value' => Money::format($amount, $currency)],
            'description' => $description !== null ? substr((string) $description, 0, 127) : null,
            'custom_id' => $customId !== null ? substr((string) $customId, 0, 127) : null,
            'invoice_id' => $invoiceId !== null ? substr((string) $invoiceId, 0, 127) : null,
        ]);

        return $this->call('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [$unit],
            'payment_source' => ['paypal' => ['vault_id' => (string) $vaultId]],
        ], ['PayPal-Request-Id' => (string) $requestId]);
    }

    /**
     * Refunds a capture; a null amount refunds what is left of it.
     */
    public function refund($captureId, $amount, $currency, $requestId)
    {
        $body = $amount === null ? new \stdClass : [
            'amount' => ['currency_code' => strtoupper((string) $currency), 'value' => Money::format($amount, $currency)],
        ];

        return $this->call(
            'POST',
            '/v2/payments/captures/' . rawurlencode((string) $captureId) . '/refund',
            $body,
            ['PayPal-Request-Id' => (string) $requestId]
        );
    }

    /** OAuth access token, cached until shortly before it expires. */
    public function accessToken()
    {
        $now = call_user_func($this->clock);
        if ($this->token !== null && $now < $this->tokenExpires) {
            return $this->token;
        }

        $url = $this->base . '/v1/oauth2/token';
        [$status, $raw] = $this->http->send('POST', $url, [
            'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->secret),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], 'grant_type=client_credentials');
        $body = json_decode($raw, true);
        $this->log('POST', $url, null, $status, $status >= 200 && $status < 300 ? '{"access_token":"***"}' : $raw);

        if ($status < 200 || $status >= 300 || empty($body['access_token'])) {
            throw new PaypalException('PayPal authentication failed (HTTP ' . $status . ')');
        }
        $this->token = (string) $body['access_token'];
        $this->tokenExpires = $now + max(60, (int) (isset($body['expires_in']) ? $body['expires_in'] : 0) - 60);

        return $this->token;
    }

    /**
     * @param  array|\stdClass|null  $body
     * @param  array<string,string>  $headers
     */
    private function call($method, $path, $body = null, array $headers = [])
    {
        $url = $this->base . $path;
        $json = $body === null ? null : json_encode($body);
        [$status, $raw] = $this->http->send($method, $url, $headers + [
            'Authorization' => 'Bearer ' . $this->accessToken(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Prefer' => 'return=representation',
        ], $json);
        $this->log($method, $url, $json, $status, $raw);

        $decoded = $raw === '' ? [] : json_decode($raw, true);

        return new ApiResponse($status, is_array($decoded) ? $decoded : []);
    }

    private function log($method, $url, $request, $status, $response)
    {
        if ($this->logger) {
            call_user_func($this->logger, $method, $url, $request, (int) $status, (string) $response);
        }
    }
}
