<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One transaction of a gateway's own list as the daily comparison read it (gateways:reconcile, GatewayReconcile;
 * docs/payments-tracking.md), and whether this site has it recorded the same; or a payment recorded here as the
 * gateway's that its list does not have. Written by the comparison only; staff mark a difference «known / handled»
 * with a note. Nothing about money hangs on it.
 *
 * @property string $amount
 * @property string|null $recorded_amount
 * @property Carbon $occurred_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $resolved_at when a difference stopped being one (recorded since, corrected)
 * @property Carbon|null $last_seen_at
 */
class GatewayTransaction extends Model
{
    /** On both sides, the same. */
    public const MATCHED = 'matched';

    /** Every kind that is a difference. */
    public const DIFFERENCES = [self::NOT_RECORDED, self::NOT_AT_GATEWAY, self::MISMATCH];

    /** At the gateway, not recorded here. */
    public const NOT_RECORDED = 'not_recorded';

    /** Recorded here as the gateway's, not in the gateway's list. */
    public const NOT_AT_GATEWAY = 'not_at_gateway';

    /** On both sides, with another amount or currency. */
    public const MISMATCH = 'mismatch';

    /** Nothing to see to. */
    public const OK = 'ok';

    /** A difference staff have not seen to. */
    public const OPEN = 'open';

    /** Staff know it: not ours, or handled. */
    public const ACKNOWLEDGED = 'acknowledged';

    protected $fillable = ['gateway', 'what', 'reference', 'kind', 'occurred_at', 'amount', 'currency_code', 'agreement', 'parent_reference',
        'recorded_amount', 'recorded_currency', 'invoice_id', 'status', 'note', 'acknowledged_by', 'acknowledged_at', 'resolved_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'recorded_amount' => 'decimal:2', 'occurred_at' => 'datetime', 'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<Staff, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'acknowledged_by');
    }

    /** One line for staff: the same from day to day, so the money check mails it once (then weekly). */
    public function describe(): string
    {
        $at = $this->occurred_at->toDateString();
        $money = trim($this->amount . ' ' . $this->currency_code);

        return match ($this->kind) {
            self::NOT_RECORDED => "at {$this->gateway} on {$at} for {$money}, not recorded here"
                . ($this->parent_reference !== null ? " (of payment {$this->parent_reference})" : '')
                . ($this->agreement !== null ? " (subscription or agreement {$this->agreement})" : ''),
            self::NOT_AT_GATEWAY => "recorded here on {$at} for {$money}" . ($this->invoice_id !== null ? " (invoice #{$this->invoice_id})" : '')
                . ", not in {$this->gateway}'s list",
            default => "{$money} at {$this->gateway} on {$at}, recorded here as " . trim($this->recorded_amount . ' ' . $this->recorded_currency)
                . ($this->invoice_id !== null ? " (invoice #{$this->invoice_id})" : ''),
        };
    }
}
