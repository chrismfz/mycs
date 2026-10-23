<?php

namespace App\Models;

use App\Classes\Price as PriceClass;
use App\Exceptions\DisplayException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property-read mixed $billing_duration
 * @property-read mixed $billingDuration
 */
class Plan extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

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

    /**
     * Its cycle as the customer reads it, after a price: «έτος», «2 έτη», «εφάπαξ», «» for a free plan (the shop polish
     * of 2026-10-20: a price without its cycle said nothing).
     */
    public function cycle(): string
    {
        if ($this->type === 'free') {
            return '';
        }
        if ($this->type !== 'recurring') {
            return __('product.one_time');
        }
        $unit = trans_choice('services.billing_cycles.' . $this->billing_unit, (int) $this->billing_period);

        return $this->billing_period > 1 ? $this->billing_period . ' ' . $unit : $unit;
    }

    /** After a price: «/ έτος» for a recurring plan, «εφάπαξ» for a one-time one, «» for a free one (review of #268). */
    public function perCycle(): string
    {
        return $this->type === 'recurring' ? '/ ' . $this->cycle() : $this->cycle();
    }

    /** WHMCS's names of its cycles: a plan named so is shown by its cycle, in the customer's language. */
    private const CYCLE_NAMES = ['monthly', 'quarterly', 'semi-annually', 'semiannually', 'annually', 'biennially', 'triennially',
        'one time', 'onetime', 'one-time', 'free'];

    /** The plan as a customer reads it: its cycle, or a name of staff's own with its cycle. */
    public function label(): string
    {
        $cycle = Str::ucfirst($this->cycle());
        $name = trim((string) $this->name);
        if ($name === '' || in_array(mb_strtolower($name), self::CYCLE_NAMES, true)) {
            return $cycle !== '' ? $cycle : $name;
        }

        return $cycle !== '' ? "{$name} ({$this->cycle()})" : $name;
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
        // Billed every cycle and paid for by live services: it does not become free or one-time under them (they would
        // never be billed again, billing-cursor §5). Staff move them to another plan first, each confirmed.
        static::updating(function (self $model) {
            if ($model->isDirty('type') && $model->getOriginal('type') === 'recurring' && in_array($model->type, ['free', 'one-time'], true)) {
                $paying = $model->services()->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])->where('price', '>', 0)->count();
                if ($paying > 0) {
                    throw new DisplayException(trans_choice('{1} A service pays for this plan every cycle: move it to another plan first.|[2,*] :count services pay for this plan every cycle: move them to another plan first.', $paying));
                }
            }
        });
        static::deleting(function (self $model) {
            if ($model->inUse()) {
                throw new DisplayException(__('This plan is used by services or upgrades: hide it instead of deleting it.'));
            }
        });
    }
}
