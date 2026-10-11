<?php

namespace App\Models;

use App\Classes\Price;
use App\Observers\InvoiceItemObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property-read mixed $formatted_total
 * @property-read mixed $formattedTotal
 * @property-read mixed $formatted_price
 * @property-read mixed $formattedPrice
 */
#[ObservedBy([InvoiceItemObserver::class])]
class InvoiceItem extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $fillable = [
        'invoice_id',
        'quantity',
        'price',
        'description',
        'gateway_id',
        'reference_id',
        'reference_type',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'period_start' => 'immutable_date',
        'period_end' => 'immutable_date',
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return MorphTo<\Illuminate\Database\Eloquent\Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Gateway, $this> */
    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }

    public function total()
    {
        return round((float) $this->price * $this->quantity, 2);
    }

    /** @return Attribute<mixed, mixed> */
    public function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->total(), 'currency' => $this->invoice->currency])
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->price, 'currency' => $this->invoice->currency])
        );
    }
}
