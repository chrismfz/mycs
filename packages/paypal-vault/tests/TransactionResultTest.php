<?php

namespace Nixpal\PaypalVault\Tests;

use Nixpal\PaypalVault\ApiResponse;
use Nixpal\PaypalVault\TransactionResult;
use PHPUnit\Framework\TestCase;

final class TransactionResultTest extends TestCase
{
    private function order($captureStatus, array $extra = [])
    {
        return new ApiResponse(201, [
            'id' => 'O1',
            'status' => 'COMPLETED',
            'purchase_units' => [['payments' => ['captures' => [array_merge(['id' => 'CAP1', 'status' => $captureStatus], $extra)]]]],
        ]);
    }

    public function test_completed_capture_is_approved()
    {
        $this->assertSame(
            ['status' => 'approved', 'reference_id' => 'O1', 'transaction_id' => 'CAP1', 'message' => null],
            TransactionResult::fromCharge($this->order('COMPLETED'))
        );
    }

    public function test_pending_capture_is_pending_with_reason()
    {
        $result = TransactionResult::fromCharge($this->order('PENDING', ['status_details' => ['reason' => 'ECHECK']]));

        $this->assertSame('pending', $result['status']);
        $this->assertSame('CAP1', $result['transaction_id']);
        $this->assertSame('ECHECK', $result['message']);
    }

    public function test_declined_capture_is_declined()
    {
        $this->assertSame('declined', TransactionResult::fromCharge($this->order('DECLINED'))['status']);
    }

    public function test_instrument_declined_is_a_decline()
    {
        $response = new ApiResponse(422, [
            'name' => 'UNPROCESSABLE_ENTITY',
            'details' => [['issue' => 'INSTRUMENT_DECLINED', 'description' => 'The instrument presented was declined.']],
        ]);
        $result = TransactionResult::fromCharge($response);

        $this->assertSame('declined', $result['status']);
        $this->assertSame('INSTRUMENT_DECLINED: The instrument presented was declined.', $result['message']);
    }

    public function test_unknown_vault_id_is_a_decline()
    {
        $response = new ApiResponse(404, ['name' => 'RESOURCE_NOT_FOUND', 'message' => 'The specified resource does not exist.']);

        $this->assertSame('declined', TransactionResult::fromCharge($response)['status']);
    }

    public function test_server_error_is_an_error()
    {
        $response = new ApiResponse(500, ['name' => 'INTERNAL_SERVER_ERROR', 'message' => 'oops']);
        $result = TransactionResult::fromCharge($response);

        $this->assertSame('error', $result['status']);
        $this->assertSame('INTERNAL_SERVER_ERROR: oops', $result['message']);
    }

    public function test_transport_failure_is_an_error()
    {
        $result = TransactionResult::fromCharge(new ApiResponse(0, ['name' => 'TRANSPORT_ERROR', 'message' => 'timeout']));

        $this->assertSame('error', $result['status']);
    }

    public function test_order_without_capture_is_declined()
    {
        $result = TransactionResult::fromCharge(new ApiResponse(201, ['id' => 'O1', 'status' => 'PAYER_ACTION_REQUIRED']));

        $this->assertSame('declined', $result['status']);
        $this->assertNull($result['transaction_id']);
        $this->assertSame('PAYER_ACTION_REQUIRED', $result['message']);
    }

    public function test_completed_refund()
    {
        $this->assertSame(
            ['status' => 'refunded', 'reference_id' => 'R1', 'transaction_id' => 'CAP1', 'message' => null],
            TransactionResult::fromRefund(new ApiResponse(201, ['id' => 'R1', 'status' => 'COMPLETED']), 'CAP1')
        );
    }

    public function test_failed_refund()
    {
        $response = new ApiResponse(422, ['details' => [['issue' => 'CAPTURE_FULLY_REFUNDED', 'description' => 'already refunded']]]);
        $result = TransactionResult::fromRefund($response, 'CAP1');

        $this->assertSame('error', $result['status']);
        $this->assertSame('CAPTURE_FULLY_REFUNDED: already refunded', $result['message']);
    }

    public function test_charge_answered_with_an_old_order_is_a_replay_not_an_approval()
    {
        $now = strtotime('2026-10-08T12:00:00Z');
        $old = $this->order('COMPLETED');
        $old->body['create_time'] = '2026-10-08T09:00:00Z';
        $fresh = $this->order('COMPLETED');
        $fresh->body['create_time'] = '2026-10-08T11:58:00Z';

        $this->assertSame(
            ['status' => 'error', 'reference_id' => 'O1', 'transaction_id' => null, 'message' => 'DUPLICATE_REQUEST'],
            TransactionResult::fromCharge($old, $now)
        );
        $this->assertSame('approved', TransactionResult::fromCharge($fresh, $now)['status']);
    }

    public function test_refund_answered_with_an_old_refund_is_a_replay()
    {
        $now = strtotime('2026-10-08T12:00:00Z');
        $response = new ApiResponse(200, ['id' => 'R1', 'status' => 'COMPLETED', 'create_time' => '2026-10-08T08:00:00Z']);

        $this->assertSame('DUPLICATE_REQUEST', TransactionResult::fromRefund($response, 'CAP1', $now)['message']);
        $this->assertSame('error', TransactionResult::fromRefund($response, 'CAP1', $now)['status']);
    }

    public function test_pending_refund_uses_a_code()
    {
        $result = TransactionResult::fromRefund(new ApiResponse(201, ['id' => 'R1', 'status' => 'PENDING']), 'CAP1');

        $this->assertSame(['pending', 'REFUND_PENDING'], [$result['status'], $result['message']]);
    }
}
