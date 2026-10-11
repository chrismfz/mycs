<?php

namespace App\Models;

use App\Classes\Price;
use App\Classes\Settings;
use App\Models\Traits\HasProperties;
use App\Observers\ServiceObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property-read mixed $formatted_price
 * @property-read mixed $formattedPrice
 * @property-read mixed $label
 * @property-read mixed $base_label
 * @property-read mixed $baseLabel
 * @property-read mixed $description
 * @property-read mixed $cancellable
 * @property-read mixed $upgradable
 */
#[ObservedBy([ServiceObserver::class])]
class Service extends Model implements Auditable
{
    use HasFactory, HasProperties, Traits\Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'order_id',
        'product_id',
        'plan_id',
        'quantity',
        'price',
        'expires_at',
        'subscription_id',
        'status',
        'coupon_id',
        'user_id',
        'currency_code',
        'billing_agreement_id',
        'suspend_hold_until',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'suspend_hold_until' => 'date',
    ];

    /**
     * Get the order that owns the service.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the coupon that owns the service.
     *
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
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
     * Get the user that owns the service.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Price of the service.
     *
     * @return Attribute<mixed, mixed>
     */
    public function formattedPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => new Price(['price' => $this->price * $this->quantity, 'currency' => $this->currency])
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function label(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ?: $this->baseLabel
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function baseLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->product?->name . ' #' . $this->id
        );
    }

    /**
     * Get the description for the next invoice item.
     *
     * @return Attribute<mixed, mixed>
     */
    public function description(): Attribute
    {
        if ($this->plan->type == 'free' || $this->plan->type == 'one-time') {
            return Attribute::make(
                get: fn () => $this->product->name
            );
        }
        $date = $this->expires_at ?? now();
        $endDate = $date->copy()->{'add' . ucfirst($this->plan->billing_unit) . 's'}($this->plan->billing_period);

        return Attribute::make(
            get: fn () => $this->product->name . ' (' . $date->format('M d, Y') . ' - ' . $endDate->format('M d, Y') . ')'
        );
    }

    /**
     * Calculate next due date.
     */
    public function calculateNextDueDate()
    {
        if ($this->plan->type == 'one-time' || $this->plan->type == 'free') {
            return null;
        }
        if (!$this->expires_at || $this->status != self::STATUS_ACTIVE) {
            // Make sure that if a service is being renewed after suspension or pending, we use the current date as base
            $date = now();
        } else {
            $date = $this->expires_at;
        }

        return $date->{'add' . ucfirst($this->plan->billing_unit) . 's'}($this->plan->billing_period);
    }

    /**
     * Get the product corresponding to the service.
     */
    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the plan corresponding to the service.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Get the service's configurations.
     *
     * @return MorphMany<ServiceConfig, $this>
     */
    public function configs(): MorphMany
    {
        return $this->morphMany(ServiceConfig::class, 'configurable');
    }

    /**
     * Get invoiceItems
     *
     * @return MorphMany<InvoiceItem, $this>
     */
    public function invoiceItems(): MorphMany
    {
        return $this->morphMany(InvoiceItem::class, 'reference');
    }

    /**
     * Get invoices
     *
     * @return HasManyThrough<Invoice, InvoiceItem, $this>
     */
    public function invoices(): HasManyThrough
    {
        return $this->hasManyThrough(Invoice::class, InvoiceItem::class, 'reference_id', 'id', 'id', 'invoice_id')->where('reference_type', Service::class);
    }

    /**
     * Get cancellation requests
     *
     * @return HasOne<ServiceCancellation, $this>
     */
    public function cancellation(): HasOne
    {
        return $this->hasOne(ServiceCancellation::class);
    }

    /** @return Attribute<mixed, mixed> */
    public function cancellable(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->status !== 'cancelled' && $this->plan->type != 'free' && $this->plan->type != 'one-time' && !$this->cancellation?->exists()
        );
    }

    /** @return Attribute<mixed, mixed> */
    public function upgradable(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->productUpgrades()->count() > 0 || $this->product->upgradableConfigOptions()->count() > 0) && $this->status == 'active' && $this->upgrade->where('status', ServiceUpgrade::STATUS_PENDING)->count() == 0
        );
    }

    public function productUpgrades()
    {
        return $this->product->upgrades->filter(function ($product) {
            // Check stock
            if ($product->stock !== null && ($product->stock - $this->quantity) < 0) {
                return null;
            }
            $plan = $product->plans()->where('billing_unit', $this->plan->billing_unit)->where('billing_period', $this->plan->billing_period)->get();
            // Only get the upgrades that have the exact same billing cycle as the service
            if ($plan->count() > 0) {
                $product->plan = $plan->first();

                return $product;
            }

            return null;
        });
    }

    public function calculatePrice()
    {
        // Calculate the price based on the plan and config options
        $price = $this->plan->price($this->currency_code)->price;

        $this->configs->each(function ($config) use (&$price) {
            $configValue = $config->configValue;
            if ($configValue) {
                $price += $configValue->price(null, $this->plan->billing_period, $this->plan->billing_unit, $this->currency_code)->price;
            }
        });

        // Add coupon discount if applicable
        if ($this->coupon) {
            $invoices = $this->invoices()->where('status', 'paid')->count() + 1;
            // If it already used for the recurring period, do not apply the discount
            if ($this->coupon->recurring == 0 || $invoices <= $this->coupon->recurring) {
                $discount = $this->coupon->calculateDiscount($price);
                $price -= $discount;
            }
        }

        $price = (new Price([
            'price' => $price,
            'currency' => $this->currency,
        ], apply_exclusive_tax: true, tax: Settings::tax($this->user)))->price;

        return number_format($price, 2, '.', '');
    }

    /** @return HasMany<ServiceUpgrade, $this> */
    public function upgrade(): HasMany
    {
        return $this->hasMany(ServiceUpgrade::class);
    }

    /** @return BelongsTo<BillingAgreement, $this> */
    public function billingAgreement(): BelongsTo
    {
        return $this->belongsTo(BillingAgreement::class, 'billing_agreement_id');
    }
}
