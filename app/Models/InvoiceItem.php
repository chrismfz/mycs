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
        'taxed',
        'tax_rate',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'taxed' => 'boolean',
        'tax_rate' => 'decimal:4',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'period_start' => 'immutable_date',
        'period_end' => 'immutable_date',
        'applied_at' => 'immutable_datetime',
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

    /** What the line charges, tax included (Δ1: net × quantity + its tax, rounded per line). */
    public function total(): float
    {
        return (float) $this->line_total;
    }

    /** The line's amount in cents, tax included: what invoice totals, balances and checks add up. */
    public function totalCents(): int
    {
        return (int) round((float) $this->line_total * 100);
    }

    /** The line without tax (net × quantity), as shown on the invoice. */
    public function net(): float
    {
        return round((float) $this->line_total - (float) $this->tax_amount, 2);
    }

    /** @return Attribute<mixed, mixed> */
    public function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->net(), 'currency' => $this->invoice->currency], tax: 0)
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->price, 'currency' => $this->invoice->currency], tax: 0)
        );
    }
}
