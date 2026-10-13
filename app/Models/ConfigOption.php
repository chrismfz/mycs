<?php

namespace App\Models;

use App\Exceptions\DisplayException;
use App\Models\Traits\HasPlans;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class ConfigOption extends Model implements Auditable
{
    use HasFactory, HasPlans, Traits\Auditable;

    protected $dontShowUnavailablePrice = true;

    protected $fillable = [
        'name',
        'description',
        'env_variable',
        'type',
        'sort',
        'hidden',
        'parent_id',
        'upgradable',
    ];

    /**
     * Get the parent option.
     *
     * @return BelongsTo<ConfigOption, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ConfigOption::class, 'parent_id');
    }

    /**
     * Get the options that belong to the parent. (children or options)
     *
     * @return HasMany<ConfigOption, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(ConfigOption::class, 'parent_id')->orderBy('sort');
    }

    /**
     * Get the products that belong to the option.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'config_option_products');
    }

    /**
     * Get the service configs that belong to the option.
     *
     * @return HasMany<ServiceConfig, $this>
     */
    public function serviceConfigs(): HasMany
    {
        return $this->hasMany(ServiceConfig::class, 'config_option_id');
    }

    /** A value (a child option) services use: never deleted with them (DATA-8). */
    public function valueInUse(): bool
    {
        return $this->parent_id !== null && ServiceConfig::query()->where('config_value_id', $this->id)->exists();
    }

    protected static function booted(): void
    {
        static::deleting(function (self $option) {
            if ($option->valueInUse()) {
                throw new DisplayException(__('This option value is used by services: move them to another value first.'));
            }
        });
    }
}
