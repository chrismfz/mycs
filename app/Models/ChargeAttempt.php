<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One charge of a saved payment method (PR H, docs/payments.md «Αυτόματη χρέωση»), written under the payment locks before
 * the gateway is called: its key is the gateway's idempotency key, and while it is started the invoice is not charged
 * again (PAY-3). The payment itself is recorded as always (RecordPaymentService), from the gateway's answer or webhook.
 *
 * @property int $id
 * @property string $key
 * @property int $invoice_id
 * @property int $client_id
 * @property int|null $billing_agreement_id
 * @property int|null $payment_group_id
 * @property string $amount
 * @property string $currency_code
 * @property string $source
 * @property string $status
 * @property string|null $error
 * @property Carbon $started_at the first time it was sent (its key's age)
 * @property Carbon $sent_at the last time it was sent
 * @property Carbon|null $finished_at
 */
class ChargeAttempt extends Model
{
    public const SOURCE_CRON = 'cron';

    public const SOURCE_CLIENT = 'client';

    /** The first invoice of a billing cycle change (ChangeCycle). */
    public const SOURCE_CHANGE = 'change';

    /** A customer's Mass Pay group (PR F2). */
    public const SOURCE_GROUP = 'group';

    /** Sent to the gateway, no answer yet: holds the invoice. */
    public const STATUS_STARTED = 'started';

    /** The gateway took it (the payment is recorded from its answer or webhook). */
    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /**
     * No answer about the money (a timeout, a 5xx): maybe taken. Holds the invoice, and the next charge of the same amount
     * to the same method is sent again with its key (SavedMethodCharge::KEY_REUSE_HOURS).
     */
    public const STATUS_UNKNOWN = 'unknown';

    /** The bank asks the customer to confirm (3-D Secure): not retried until they pay themselves. */
    public const STATUS_ACTION_REQUIRED = 'action_required';

    /**
     * A started or succeeded attempt holds the invoice this long: no answer yet, or the money taken while its payment is
     * still to be recorded (Stripe's webhook). Older, a started one had no answer (a crash) and holds it no longer.
     */
    public const HOLD_MINUTES = 15;

    protected $fillable = [
        'key', 'invoice_id', 'client_id', 'billing_agreement_id', 'payment_group_id', 'amount', 'currency_code', 'source', 'status',
        'error', 'started_at', 'sent_at', 'finished_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'started_at' => 'datetime',
        'sent_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Sent when it starts; sent_at moves on when an unknown outcome is sent again with its key.
        static::creating(function (self $attempt) {
            $attempt->sent_at ??= $attempt->started_at;
        });
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<BillingAgreement, $this> */
    public function billingAgreement(): BelongsTo
    {
        return $this->belongsTo(BillingAgreement::class)->withTrashed();
    }
}
