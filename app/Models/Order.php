<?php

namespace App\Models;

use App\Classes\Price;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Observers\OrderObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
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

    /** WHMCS's order statuses (Σ1-b2, Δ4). */
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FRAUD = 'fraud';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_CANCELLED, self::STATUS_FRAUD];

    protected $fillable = ['client_id', 'currency_code', 'notes'];

    protected $casts = ['accepted_at' => 'datetime'];

    public bool $send_create_email = true;

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'accepted_by_staff_id');
    }

    /**
     * A pending order follows its services (Δ4): active once none of them waits (set up on order or on payment, or by
     * staff), cancelled once all of them ended (never paid). Staff's own status (active by Accept, cancelled, fraud)
     * is not undone here.
     */
    public function refreshStatus(): void
    {
        DB::transaction(fn () => $this->refreshStatusLocked());
    }

    private function refreshStatusLocked(): void
    {
        $locked = self::query()->whereKey($this->id)->lockForUpdate()->first();
        if ($locked === null || $locked->status !== self::STATUS_PENDING) {
            return;
        }
        $statuses = Service::query()->where('order_id', $this->id)->pluck('status');
        // Its domains too (D8b): pending until registered or transferred, ended when cancelled.
        $domains = Domain::query()->where('order_id', $this->id)->pluck('status');
        if ($statuses->isEmpty() && $domains->isEmpty()) {
            return;
        }
        $pendingDomain = $domains->contains(fn (DomainStatus $s) => in_array($s, [DomainStatus::PendingRegister, DomainStatus::PendingTransfer], true));
        $status = match (true) {
            $statuses->every(fn (string $status) => in_array($status, Service::ENDED_STATUSES, true))
                && $domains->every(fn (DomainStatus $s) => $s->isTerminal()) => self::STATUS_CANCELLED,
            !$statuses->contains(Service::STATUS_PENDING) && !$pendingDomain => self::STATUS_ACTIVE,
            default => null,
        };
        if ($status !== null) {
            $locked->forceFill(['status' => $status])->save();
            $this->setRawAttributes($locked->getAttributes(), true);
        }
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** @return HasMany<Domain, $this> the domains it registers or transfers (D8b) */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
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
        $invoicesId = $this->services->map(fn ($service) => $service->invoiceItems->map(fn ($invoiceItem) => $invoiceItem->invoice_id))->flatten()
            ->concat($this->domains->map(fn (Domain $domain) => $domain->invoiceItems->pluck('invoice_id'))->flatten())
            ->push($this->invoice_id)->filter()->unique();

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
