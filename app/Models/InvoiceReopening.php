<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paid invoice became open again: when it had been paid, when it was reopened and what its lines were worth then
 * (net and with tax, credit purchases left out). Written once by InvoiceObserver, read by the year's income report
 * (App\Services\Reports\IncomeReport: a closed year never changes).
 *
 * @property int $invoice_id
 * @property CarbonImmutable $paid_at
 * @property CarbonImmutable $reopened_at
 * @property string $net
 * @property string $total
 */
class InvoiceReopening extends Model
{
    public $timestamps = false;

    protected $fillable = ['invoice_id', 'paid_at', 'reopened_at', 'net', 'total'];

    protected $casts = [
        'paid_at' => 'immutable_datetime',
        'reopened_at' => 'immutable_datetime',
        'net' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
