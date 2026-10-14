<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A refund a gateway made, by its id there (PR 2c-2): recorded on its payment once, whoever told us first.
 */
class GatewayRefund extends Model
{
    public const ORIGIN_MYCS = 'mycs';

    public const ORIGIN_GATEWAY = 'gateway';

    /** Matched to a refund staff recorded by hand on the payment: not recorded again. */
    public const ORIGIN_HAND = 'hand';

    /** For a payment not recorded (or not succeeded) here yet: applied when it is. */
    public const ORIGIN_WAITING = 'waiting';

    /** Reported failed by the gateway before anything was recorded: a late «created» records nothing. */
    public const ORIGIN_FAILED = 'failed';

    /** The gateway took the payment back for a dispute (PR 2c-2b); money_event_id is its journal event. */
    public const ORIGIN_CHARGEBACK = 'chargeback';

    /** A chargeback whose dispute was won: its money came back and its journal event is reversed. */
    public const ORIGIN_CHARGEBACK_WON = 'chargeback_won';

    /** A chargeback for a payment not recorded (or not succeeded) here yet: applied when it is. */
    public const ORIGIN_CHARGEBACK_WAITING = 'chargeback_waiting';

    /** A dispute opened on the payment (reference «opened:<id>»): staff told once; counted for PayPal's wins. */
    public const ORIGIN_DISPUTE = 'dispute';

    /**
     * What MyCS itself refunded on a payment through its gateway, in cents: the gateways' idempotency key for a refund
     * (a retry of an attempt whose answer was lost keeps its key, whatever the webhook recorded meanwhile).
     */
    public static function refundedByMycsCents(InvoiceTransaction $transaction): int
    {
        return (int) self::query()->where('invoice_transaction_id', $transaction->id)->where('origin', self::ORIGIN_MYCS)
            ->get()->sum(fn (self $refund) => (int) round((float) $refund->amount * 100));
    }

    protected $fillable = [
        'invoice_transaction_id',
        'gateway',
        'reference',
        'payment_reference',
        'amount',
        'currency_code',
        'recorded_amount',
        'origin',
        'money_event_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'recorded_amount' => 'decimal:2',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(InvoiceTransaction::class, 'invoice_transaction_id');
    }
}
