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

    public function calculateDiscount($price, $type = 'price')
    {
        if (!in_array($type, ['price', 'setup_fee'])) {
            throw new \InvalidArgumentException('Invalid type for coupon discount calculation');
        }
        if (!in_array($this->applies_to, ['all', $type])) {
            return 0;
        }

        $discount = 0;
        if ($this->type === 'percentage') {
            $discount = $price * $this->value / 100;
        } elseif ($this->type === 'fixed') {
            $discount = $this->value;
        }
        if ($price < $discount) {
            $discount = $price;
        }

        return $discount;
    }
}
