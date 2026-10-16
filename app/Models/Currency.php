<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Currency extends Model
{
    use HasFactory;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'code';

    protected $fillable = [
        'code',
        'name',
        'prefix',
        'suffix',
        'format',
    ];

    public function newEloquentBuilder($query)
    {
        return new Builders\CacheableBuilder($query);
    }

    // Clear cache when model is updated, created, or deleted
    protected static function booted()
    {
        // Currencies change infrequently, so we can clear the entire cache on changes
        static::created(function ($currency) {
            Cache::flush();
        });

        static::saved(function ($currency) {
            Cache::flush();
        });

        static::deleted(function ($currency) {
            Cache::flush();
        });
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'currency_code', 'code');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'currency_code', 'code');
    }

    /** @return HasMany<Credit, $this> */
    public function credits(): HasMany
    {
        return $this->hasMany(Credit::class, 'currency_code', 'code');
    }

    /** @return HasMany<Cart, $this> */
    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class, 'currency_code', 'code');
    }
}
