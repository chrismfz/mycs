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
    /**
     * A charge on a domain or a service that is not its renewal (an owner change, a fee billed apart; a WHMCS setup fee,
     * promotion, addon or upgrade the importer brought): kind, not a period. It never renews or bills a period.
     */
    public const KIND_CHARGE = 'charge';

    /** Asked for when made (the Admin API): «this is the domain's renewal, give it its period». Never stored. */
    public const KIND_RENEWAL = 'renewal';

    /** A domain's registration and transfer (D8b): what its payment starts at the registrar, never a renewal. */
    public const KIND_REGISTER = 'register';

    public const KIND_TRANSFER = 'transfer';

    /** The fees of a domain's stage (D5b-1): the only kinds that change into each other (grace's into redemption's). */
    public const STAGE_FEES = ['grace_fee', 'redemption_fee'];

    use HasFactory, Traits\Auditable;

    protected $fillable = [
        'invoice_id',
        'quantity',
        'price',
        'description',
        'gateway_id',
        'reference_id',
        'reference_type',
        'kind',
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
        // Its period given up on (Δ3): the invoice was cancelled or refunded, and the cron does not bill it again.
        'forgone_at' => 'immutable_datetime',
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
