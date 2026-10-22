<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request to a gateway's routes and what came of it (docs/money-trail.md §4, MT-2a). Written only by
 * App\Services\Money\GatewayLog. Field names, never values: no payload, token, card or signature is kept.
 */
class PaymentGatewayEvent extends Model
{
    public const OUTCOME_RECEIVED = 'received';

    public const OUTCOME_RECORDED = 'recorded';

    public const OUTCOME_IGNORED = 'ignored';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_ERROR = 'error';

    /** About a payment this instance did not make or has no record of (GatewayReference::foreign): staff check it. */
    public const OUTCOME_FOREIGN = 'foreign';

    protected $fillable = [
        'gateway',
        'endpoint',
        'method',
        'event_type',
        'fields',
        'signature_verified',
        'outcome',
        'reason',
        'amount',
        'currency_code',
        'external_id',
        'invoice_id',
        'invoice_transaction_id',
        'http_status',
    ];

    protected $casts = [
        'fields' => 'array',
        'signature_verified' => 'boolean',
        'amount' => 'decimal:2',
        'http_status' => 'integer',
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<InvoiceTransaction, $this> */
    public function invoiceTransaction(): BelongsTo
    {
        return $this->belongsTo(InvoiceTransaction::class);
    }
}
