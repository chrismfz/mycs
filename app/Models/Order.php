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
use Illuminate\Support\Collection;
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

    protected $casts = ['accepted_at' => 'datetime', 'started_unpaid_at' => 'datetime'];

    public bool $send_create_email = true;

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Its own invoice (Σ1-b2), the one placing it made: its payment status in Pending Orders.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'accepted_by_staff_id');
    }

    /**
     * A pending order stays pending until staff accept it, as WHMCS (owner 2026-10-19, replacing Δ4's «active once
     * set up»): Accept is staff's look at what came in, its services set up or not, paid or not. Only cancelled here,
     * once all of its services and domains ended (never paid). Staff's own status (accepted, cancelled, fraud) is not
     * undone here, except by the cron's «cancel unpaid orders» ($unpaid): an order staff only looked at (accepted), never
     * paid, is cancelled with its services, as WHMCS.
     */
    public function refreshStatus(bool $unpaid = false): void
    {
        DB::transaction(fn () => $this->refreshStatusLocked($unpaid));
    }

    private function refreshStatusLocked(bool $unpaid): void
    {
        $locked = self::query()->whereKey($this->id)->lockForUpdate()->first();
        if ($locked === null || !in_array($locked->status, $unpaid ? [self::STATUS_PENDING, self::STATUS_ACTIVE] : [self::STATUS_PENDING], true)) {
            return;
        }
        $statuses = Service::query()->where('order_id', $this->id)->pluck('status');
        // Its domains too (D8b): pending until registered or transferred, ended when cancelled.
        $domains = Domain::query()->where('order_id', $this->id)->pluck('status');
        if ($statuses->isEmpty() && $domains->isEmpty()) {
            return;
        }
        if ($statuses->every(fn (string $status) => in_array($status, Service::ENDED_STATUSES, true))
            && $domains->every(fn (DomainStatus $s) => $s->isTerminal())) {
            $locked->forceFill(['status' => self::STATUS_CANCELLED])->save();
            $this->setRawAttributes($locked->getAttributes(), true);
        }
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** @return Collection<int, InvoiceItem> its domains' registration and transfer lines */
    private function domainLines(): Collection
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->whereIn('reference_id', $this->domains->pluck('id'))
            ->whereIn('kind', [InvoiceItem::KIND_REGISTER, InvoiceItem::KIND_TRANSFER])->get();
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
            // What its services renew at, with tax (Δ1: their prices are net); and what its domains are billed (D8b,
            // adversarial ADV-6), their registration or transfer lines with tax.
            get: fn () => ($this->services->sum(fn (Service $service) => $service->recurringGrossCents())
                + $this->domainLines()->sum(fn (InvoiceItem $line) => $line->totalCents())) / 100
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
            get: fn () => Price::ofTotal($this->total, ($this->services->sum(fn (Service $service) => (int) round((float) $service->recurringLine()['tax'] * 100))
                + $this->domainLines()->sum(fn (InvoiceItem $line) => (int) round((float) $line->tax_amount * 100))) / 100, $this->currency)
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
            // Nor one with domains (D8b, code review L1): cancel it, and its pending domains with it.
            if ($order->domains()->exists()) {
                throw new DisplayException(__('This order has domains: cancel it instead.'));
            }
        });
    }
}
