<?php

namespace Nixpal\PaypalVault\Tests;

use Nixpal\PaypalVault\ApiResponse;
use Nixpal\PaypalVault\StoredAccount;
use PHPUnit\Framework\TestCase;

final class StoredAccountTest extends TestCase
{
    const NOW = 1791460800; // 2026-10-08

    public function test_from_payment_token()
    {
        $response = new ApiResponse(201, [
            'id' => 'VAULT1',
            'customer' => ['id' => 'CUST1'],
            'payment_source' => ['paypal' => ['email_address' => 'payer.name@example.com']],
        ]);

        $this->assertSame([
            'client_reference_id' => 'CUST1',
            'reference_id' => 'VAULT1',
            'last4' => 'name',
            'expiration' => '204612',
            'type' => 'other',
        ], StoredAccount::fromPaymentToken($response, self::NOW));
    }

    public function test_failed_response_gives_null()
    {
        $this->assertNull(StoredAccount::fromPaymentToken(new ApiResponse(422, ['name' => 'X'])));
        $this->assertNull(StoredAccount::fromPaymentToken(new ApiResponse(201, [])));
    }

    public function test_hint()
    {
        $this->assertSame('ab', StoredAccount::hint('ab@example.com'));
        $this->assertSame('', StoredAccount::hint(''));
        $this->assertSame('5678', StoredAccount::hint('12345678'));
    }

    public function test_charge_request_id_is_stable_per_day_and_ignores_invoice_order()
    {
        $a = StoredAccount::chargeRequestId('V', 10, 'EUR', [['id' => 2, 'amount' => 4], ['id' => 1, 'amount' => 6]], self::NOW);
        $b = StoredAccount::chargeRequestId('V', '10.00', 'eur', [['id' => 1, 'amount' => 6], ['id' => 2, 'amount' => 4]], self::NOW + 3600);

        $this->assertSame($a, $b);
        $this->assertStringStartsWith('blesta-', $a);
        $this->assertLessThanOrEqual(108, strlen($a));
    }

    public function test_charge_request_id_changes_with_day_amount_or_account()
    {
        $base = StoredAccount::chargeRequestId('V', 10, 'EUR', [['id' => 1, 'amount' => 10]], self::NOW);

        $this->assertNotSame($base, StoredAccount::chargeRequestId('V', 10, 'EUR', [['id' => 1, 'amount' => 10]], self::NOW + 86400));
        $this->assertNotSame($base, StoredAccount::chargeRequestId('V', 11, 'EUR', [['id' => 1, 'amount' => 10]], self::NOW));
        $this->assertNotSame($base, StoredAccount::chargeRequestId('W', 10, 'EUR', [['id' => 1, 'amount' => 10]], self::NOW));
    }

    public function test_charge_without_invoices_gets_a_unique_id()
    {
        $this->assertNotSame(
            StoredAccount::chargeRequestId('V', 10, 'EUR', null, self::NOW),
            StoredAccount::chargeRequestId('V', 10, 'EUR', null, self::NOW)
        );
        $this->assertNotSame(
            StoredAccount::chargeRequestId('V', 10, 'EUR', [], self::NOW),
            StoredAccount::chargeRequestId('V', 10, 'EUR', [], self::NOW)
        );
    }

    public function test_refund_request_id_is_stable_per_day_and_amount()
    {
        $a = StoredAccount::refundRequestId('CAP1', 5, 'EUR', self::NOW);

        $this->assertSame($a, StoredAccount::refundRequestId('CAP1', '5.00', 'EUR', self::NOW + 600));
        $this->assertNotSame($a, StoredAccount::refundRequestId('CAP1', 6, 'EUR', self::NOW));
        $this->assertNotSame($a, StoredAccount::refundRequestId('CAP1', null, 'EUR', self::NOW));
        $this->assertNotSame($a, StoredAccount::refundRequestId('CAP1', 5, 'EUR', self::NOW + 86400));
        $this->assertStringStartsWith('blesta-refund-', $a);
    }
}
