<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    // The cached currency queries cleared when a currency is created, saved or deleted: only those (CacheableBuilder),
    // never the whole store, whose file driver keeps the locks there too (sweep 3, L-DEPLOY-MIGRATIONS-1).
    protected static function booted()
    {
        static::saved(fn () => Builders\CacheableBuilder::flushQueryCache());
        static::deleted(fn () => Builders\CacheableBuilder::flushQueryCache());
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
