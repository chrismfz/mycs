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

    protected $fillable = [
        'invoice_transaction_id',
        'gateway',
        'reference',
        'amount',
        'currency_code',
        'recorded_amount',
        'origin',
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
