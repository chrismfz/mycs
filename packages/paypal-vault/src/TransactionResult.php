<?php

namespace Nixpal\PaypalVault;

/**
 * Maps PayPal responses to the transaction array Blesta merchant gateways return:
 * status (approved, declined, pending, refunded, error), reference_id, transaction_id, message.
 *
 * Messages are PayPal issue codes or the codes below, never prose: the gateway turns
 * them into language strings.
 *
 * Replays: charges and refunds carry a PayPal-Request-Id that is stable for a day, so a
 * retry after a timeout gets PayPal's first answer instead of moving money twice. An
 * answer for something PayPal created more than REPLAY_WINDOW seconds ago is therefore an
 * earlier transaction, not this one, and is reported as an error (DUPLICATE_REQUEST)
 * instead of being recorded again.
 */
final class TransactionResult
{
    /** Order/charge issues that mean "the payer's PayPal account said no", not a system fault. */
    const DECLINE_ISSUES = [
        'INSTRUMENT_DECLINED',
        'PAYER_ACTION_REQUIRED',
        'PAYER_CANNOT_PAY',
        'PAYEE_BLOCKED_TRANSACTION',
        'TRANSACTION_REFUSED',
        'PAYMENT_DENIED',
        'MAX_NUMBER_OF_PAYMENT_ATTEMPTS_EXCEEDED',
        'TRANSACTION_LIMIT_EXCEEDED',
        'COMPLIANCE_VIOLATION',
        'INVALID_RESOURCE_ID', // vault id deleted or revoked at PayPal
        'PAYMENT_SOURCE_INFO_CANNOT_BE_VERIFIED',
        'PAYMENT_SOURCE_DECLINED_BY_PROCESSOR',
    ];

    const REPLAY_WINDOW = 900;

    const DUPLICATE_REQUEST = 'DUPLICATE_REQUEST';

    const REFUND_PENDING = 'REFUND_PENDING';

    /**
     * From the response of PaypalVaultClient::charge() (POST /v2/checkout/orders).
     * reference_id = order id, transaction_id = capture id (what refunds need).
     *
     * @param  int|null  $now  Unix time (tests)
     */
    public static function fromCharge(ApiResponse $response, $now = null)
    {
        $body = $response->body;
        $orderId = isset($body['id']) ? (string) $body['id'] : null;
        $capture = isset($body['purchase_units'][0]['payments']['captures'][0])
            ? $body['purchase_units'][0]['payments']['captures'][0]
            : null;

        if (!$response->ok()) {
            $declined = in_array($response->issue(), self::DECLINE_ISSUES, true)
                || $response->status === 404;

            return self::result($declined ? 'declined' : 'error', $orderId, null, $response->errorMessage());
        }

        if (self::isReplay($body, $now)) {
            return self::result('error', $orderId, null, self::DUPLICATE_REQUEST);
        }

        $captureStatus = $capture && isset($capture['status']) ? $capture['status'] : null;
        $captureId = $capture && isset($capture['id']) ? (string) $capture['id'] : null;
        switch ($captureStatus) {
            case 'COMPLETED':
                return self::result('approved', $orderId, $captureId, null);
            case 'PENDING':
                $reason = isset($capture['status_details']['reason']) ? $capture['status_details']['reason'] : null;

                return self::result('pending', $orderId, $captureId, $reason);
            case 'DECLINED':
            case 'FAILED':
                return self::result('declined', $orderId, $captureId, $captureStatus);
        }

        // Order created but not captured (e.g. PAYER_ACTION_REQUIRED): nothing was charged.
        $orderStatus = isset($body['status']) ? $body['status'] : 'UNKNOWN';

        return self::result('declined', $orderId, $captureId, $orderStatus);
    }

    /**
     * From the response of PaypalVaultClient::refund().
     */
    public static function fromRefund(ApiResponse $response, $captureId, $now = null)
    {
        if (!$response->ok()) {
            return self::result('error', null, $captureId, $response->errorMessage());
        }
        if (self::isReplay($response->body, $now)) {
            return self::result('error', null, $captureId, self::DUPLICATE_REQUEST);
        }
        $status = isset($response->body['status']) ? $response->body['status'] : null;
        $refundId = isset($response->body['id']) ? (string) $response->body['id'] : null;
        if ($status === 'COMPLETED') {
            return self::result('refunded', $refundId, $captureId, null);
        }
        if ($status === 'PENDING') {
            return self::result('pending', $refundId, $captureId, self::REFUND_PENDING);
        }

        return self::result('error', $refundId, $captureId, 'REFUND_' . ($status ?: 'UNKNOWN'));
    }

    /** True when PayPal answered with a resource it created well before this request. */
    private static function isReplay(array $body, $now)
    {
        if (empty($body['create_time'])) {
            return false;
        }
        $created = strtotime((string) $body['create_time']);
        $now = $now === null ? time() : (int) $now;

        return $created !== false && $created < $now - self::REPLAY_WINDOW;
    }

    public static function error($message)
    {
        return self::result('error', null, null, $message);
    }

    private static function result($status, $referenceId, $transactionId, $message)
    {
        return [
            'status' => $status,
            'reference_id' => $referenceId,
            'transaction_id' => $transactionId,
            'message' => $message,
        ];
    }
}
