<?php

namespace App\Models;

use App\Classes\Price;
use App\Exceptions\DisplayException;
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
 * @property int $client_id
 */
#[ObservedBy([OrderObserver::class])]
class Order extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $fillable = ['client_id', 'currency_code'];

    public bool $send_create_email = true;

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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
            // What its services renew at, with tax (Δ1: their prices are net).
            get: fn () => $this->services->sum(fn (Service $service) => $service->recurringGrossCents()) / 100
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
            // With the tax its services' renewals carry (Δ1), for the order e-mail.
            get: fn () => Price::ofTotal($this->total, $this->services->sum(fn (Service $service) => (int) round((float) $service->recurringLine()['tax'] * 100)) / 100, $this->currency)
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

    /** An order with services is not deleted (DATA-2): delete its services first, one way (DeleteService). */
    protected static function booted(): void
    {
        static::deleting(function (self $order) {
            if ($order->services()->exists()) {
                throw new DisplayException(__('This order has services: delete them first.'));
            }
        });
    }
}
