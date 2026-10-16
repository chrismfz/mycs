<?php

namespace App\Models;

use App\Classes\Price as PriceClass;
use App\Exceptions\DisplayException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property-read mixed $billing_duration
 * @property-read mixed $billingDuration
 */
class Plan extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    /** Never followed from a staff-edited template: they lead to every customer's records (PR #158 review). */
    public const TEMPLATE_HIDDEN = ['services'];

    public $timestamps = false;

    protected $fillable = [
        'name',
        'type',
        'billing_period',
        'billing_unit',
        'sort',
    ];

    protected $casts = [
        'billing_period' => 'integer',
    ];

    /**
     * Get the available prices of the plan.
     *
     * @return HasMany<Price, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    /**
     * Get the priceable model of the plan.
     *
     * @return MorphTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the price of the plan.
     *
     * @param  string|null  $currency  Optional currency code to get the price for. If not provided, it will use the current session currency or default currency.
     */
    public function price($currency = null): PriceClass
    {
        if ($this->type === 'free') {
            return new PriceClass(['currency' => Currency::find($currency ?? session('currency', config('settings.default_currency')))], free: true);
        }
        $currency = $currency ?? session('currency', config('settings.default_currency'));
        $price = $this->prices->where('currency_code', $currency)->first();

        // No price row in this currency: return unavailable instead of dereferencing null.
        if (!$price) {
            return new PriceClass((object) ['price' => null, 'setup_fee' => null, 'currency' => null]);
        }

        return new PriceClass((object) [
            'price' => $price,
            'setup_fee' => $price->setup_fee,
            'currency' => $price->currency,
        ]);
    }

    // Time between billing periods
    /** @return Attribute<mixed, mixed> */
    public function billingDuration(): Attribute
    {
        if ($this->type === 'free' || $this->type == 'one-time') {
            return Attribute::make(get: fn () => 0);
        }
        $diffInDays = match ($this->billing_unit) {
            'day' => 1,
            'week' => 7,
            'month' => 30,
            'year' => 365,
        };

        return Attribute::make(
            get: fn () => $diffInDays * $this->billing_period
        );
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Used by a service or an upgrade (any status: their history keeps it): never deleted (audit sweep BIL-6, as WHMCS).
     * Staff hide it instead.
     */
    public function inUse(): bool
    {
        return $this->services()->exists() || ServiceUpgrade::query()->where('plan_id', $this->id)->exists();
    }

    protected static function booted(): void
    {
        static::deleting(function (self $model) {
            if ($model->inUse()) {
                throw new DisplayException(__('This plan is used by services or upgrades: hide it instead of deleting it.'));
            }
        });
    }
}
