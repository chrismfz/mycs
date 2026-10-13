<?php

namespace App\Models;

use App\Classes\Price;
use App\Enums\AdjustmentNoteStatus;
use App\Observers\AdjustmentNoteObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property-read mixed $formatted_amount
 * @property-read mixed $formattedAmount
 */
#[ObservedBy([AdjustmentNoteObserver::class])]
class AdjustmentNote extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $fillable = [
        'invoice_id',
        'type',
        'number',
        'amount',
        'description',
        'status',
        'is_cancellation',
        'is_refund',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'status' => AdjustmentNoteStatus::class,
        'is_cancellation' => 'boolean',
        'is_refund' => 'boolean',
    ];

    /**
     * Staff void or unvoid only the notes they wrote: a refund's note follows its transaction, a cancellation's the
     * invoice's status (docs/money-trail.md, MT-1c).
     */
    public function isVoidableByStaff(): bool
    {
        return !$this->is_refund && !$this->is_cancellation;
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Formatted amount of the adjustment note.
     *
     * @return Attribute<mixed, mixed>
     */
    public function formattedAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->amount, 'currency' => $this->invoice->currency])
        );
    }
}
