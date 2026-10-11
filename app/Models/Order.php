<?php

namespace App\Models;

use App\Classes\Price;
use App\Observers\OrderObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property-read mixed $total
 * @property-read mixed $formatted_total
 * @property-read mixed $formattedTotal
 * @property-read mixed $invoices
 */
#[ObservedBy([OrderObserver::class])]
class Order extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $fillable = ['user_id', 'currency_code'];

    public bool $send_create_email = true;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Get the currency corresponding to the service.
     *
     * @return HasOne<Currency, $this>
     */
    public function currency(): HasOne
    {
        return $this->hasOne(Currency::class, 'code', 'currency_code');
    }

    /**
     * Total of the order.
     *
     * @return Attribute<mixed, mixed>
     */
    public function total(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->services->sum(fn ($service) => $service->price * $service->quantity)
        );
    }

    /**
     * Total of the order.
     *
     * @return Attribute<mixed, mixed>
     */
    public function formattedTotal(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->total, 'currency' => $this->currency])
        );
    }

    /**
     * Get all invoices for the order.
     *
     * @return Attribute<mixed, mixed>
     */
    public function invoices(): Attribute
    {
        // Each service has invoices (it is a hasManyThrough relationship order -> service -> invoiceItem -> invoice)
        $invoicesId = $this->services->map(fn ($service) => $service->invoiceItems->map(fn ($invoiceItem) => $invoiceItem->invoice_id))->flatten();

        return new Attribute(
            get: fn () => Invoice::whereIn('id', $invoicesId)
        );
    }
}
