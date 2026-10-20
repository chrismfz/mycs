<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class Coupon extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    protected $fillable = [
        'type',
        'applies_to',
        'time',
        'code',
        'value',
        'max_uses',
        'max_uses_per_user',
        'starts_at',
        'expires_at',
        'recurring',
        'customers',
        'once_per_client',
        'requires_existing',
        'billing_cycles',
    ];

    public const CUSTOMERS = ['all', 'new', 'existing'];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'max_uses' => 'integer',
        'max_uses_per_user' => 'integer',
        'value' => 'float',
        'once_per_client' => 'boolean',
        'requires_existing' => 'boolean',
        'billing_cycles' => 'array',
    ];

    /**
     * Get the products that belong to the option.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'coupon_products');
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Check if the user has exceeded the maximum allowed uses of this coupon
     *
     * @param  int  $clientId
     */
    public function hasExceededMaxUsesPerUser($clientId): bool
    {
        if (empty($this->max_uses_per_user)) {
            return false;
        }

        return $this->services()
            ->where('client_id', $clientId)
            ->count() >= $this->max_uses_per_user;
    }

    /**
     * The products it requires (WHMCS «Requires»): one of them in the order, or held already when requires_existing.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function requiredProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'coupon_required_products');
    }

    /**
     * Whether it discounts this product, on this plan's billing cycle when given: a coupon without products is for all of
     * them; one without cycles for every cycle (WHMCS «Billing Cycles»).
     */
    public function covers(int $productId, ?Plan $plan = null): bool
    {
        if ($this->products->isNotEmpty() && !$this->products->contains($productId)) {
            return false;
        }

        return $plan === null || empty($this->billing_cycles) || in_array(self::cycleKey($plan), $this->billing_cycles, true);
    }

    /** A plan's billing cycle as the coupon lists it: «month:1», «year:2», «one_time». */
    public static function cycleKey(Plan $plan): string
    {
        return $plan->type === 'recurring' ? $plan->billing_unit . ':' . (int) $plan->billing_period : 'one_time';
    }

    /**
     * Why this account cannot use it for these products (WHMCS «New Signups», «Existing Client», «Once Per Client»,
     * «Requires»), or null. The services of the order being placed are not made yet. A guest's (null) is checked on
     * its cart's products only: who it is, at checkout (PlaceOrderService), once signed up or in.
     *
     * @param  list<int>  $productIds  the products of the order
     */
    public function refusalFor(?Client $client, array $productIds): ?string
    {
        if ($client !== null) {
            $services = Service::query()->where('client_id', $client->id);
            if ($this->customers === 'new' && (clone $services)->exists()) {
                return __('This promotion is for new customers only.');
            }
            if ($this->customers === 'existing' && !(clone $services)->where('status', Service::STATUS_ACTIVE)->exists()) {
                return __('This promotion is for existing customers only.');
            }
            if ($this->once_per_client && (clone $services)->where('coupon_id', $this->id)->exists()) {
                return __('You have already used this promotion.');
            }
        }
        $required = $this->requiredProducts->pluck('id')->map(fn ($id) => (int) $id);
        if ($required->isNotEmpty() && $required->intersect($productIds)->isEmpty()
            && !($this->requires_existing && ($client === null || Service::query()->where('client_id', $client->id)
                ->where('status', Service::STATUS_ACTIVE)->whereIn('product_id', $required)->exists()))) {
            return __('This promotion requires another product.');
        }

        return null;
    }

    /**
     * How many billing cycles it discounts: 0 = every cycle; empty = the first only, as the form says (CART-1: an
     * empty value read as 0 gave a lifetime discount on every recalculation).
     */
    public function cycles(): int
    {
        return $this->recurring === null ? 1 : max(0, (int) $this->recurring);
    }
}
