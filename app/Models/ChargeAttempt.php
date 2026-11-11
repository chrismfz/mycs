<?php

namespace App\Models;

use App\Services\Payment\SavedMethodCharge;
use Illuminate\Database\Eloquent\Builder;
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

    /** Taken, and its payment recorded another way than from the gateway (a transfer, Mark Paid): settled by staff (ResolveCharge). */
    public const STATUS_SETTLED = 'settled';

    /**
     * No answer about the money (a timeout, a 5xx): maybe taken. Holds the invoice until settled (SavedMethodCharge::unresolved),
     * and the next charge of the same amount to the same method is sent again with its key while its gateway keeps it
     * (SavedMethodCharge::keyReuseHours).
     */
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * The gateway was not reached before the charge, or ran nothing (an outage, a timeout, a rate limit, no token, our key
     * refused: ChargeNotSent): nothing taken, nothing declined. Holds nothing, is not counted as a decline, and the customer
     * is not told it failed (sweep 3 L-FAILURES-2); the cron tries again as set. Only a first send: a resend of an unknown
     * outcome that is not sent stays unknown (S8-PAYMENTS-1).
     */
    public const STATUS_NOT_SENT = 'not_sent';

    /** The bank asks the customer to confirm (3-D Secure): not retried until they pay themselves. */
    public const STATUS_ACTION_REQUIRED = 'action_required';

    /**
     * A started attempt this old has had no answer: a crash, an unknown outcome (sent again with its key while its gateway
     * keeps it; held until settled either way: predeploy S1).
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

    /**
     * Waiting on staff however old (held until settled): taken an hour ago and no payment of it recorded, or an unknown
     * outcome (no answer past HOLD_MINUTES too). Ops\Charges::attentionOf's reading in SQL: the dashboard's «Needs
     * attention» and the invoices' «Charge to settle» filter count the same.
     *
     * @param  Builder<ChargeAttempt>  $query
     * @return Builder<ChargeAttempt>
     */
    public function scopeNeedsStaff(Builder $query): Builder
    {
        return SavedMethodCharge::notRecorded($query->where(fn (Builder $query) => $query->where('status', self::STATUS_UNKNOWN)
            ->orWhere(fn (Builder $stale) => $stale->where('status', self::STATUS_STARTED)->where('sent_at', '<=', now()->subMinutes(self::HOLD_MINUTES)))
            ->orWhere(fn (Builder $taken) => $taken->where('status', self::STATUS_SUCCEEDED)->where('sent_at', '<=', now()->subHour()))));
    }
}
