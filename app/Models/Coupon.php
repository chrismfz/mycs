<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class Coupon extends Model implements Auditable
{
    use HasFactory, Traits\Auditable;

    /** Never followed from a staff-edited template: they lead to every customer's records (PR #158 review). */
    public const TEMPLATE_HIDDEN = ['services'];

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
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'max_uses' => 'integer',
        'max_uses_per_user' => 'integer',
        'value' => 'float',
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

    /** Whether it discounts this product: a coupon without products is for all of them. */
    public function covers(int $productId): bool
    {
        return $this->products->isEmpty() || $this->products->contains($productId);
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
