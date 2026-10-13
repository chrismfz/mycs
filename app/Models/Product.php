<?php

namespace App\Models;

use App\Exceptions\DisplayException;
use App\Models\Traits\HasPlans;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use OwenIt\Auditing\Contracts\Auditable;

class Product extends Model implements Auditable
{
    use HasFactory, HasPlans, Traits\Auditable;

    protected $guarded = [];

    protected $auditInclude = [
        'name',
        'description',
        'category_id',
        'enabled',
    ];

    /**
     * Get the category of the product.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the configurable options of the product.
     */
    public function configOptions(): HasManyThrough
    {
        return $this->hasManyThrough(ConfigOption::class, ConfigOptionProduct::class, 'product_id', 'id', 'id', 'config_option_id')->where('config_options.hidden', false)->orderBy('config_options.sort', 'asc')->orderBy('config_options.id', 'desc');
    }

    /**
     * Get the extension of the product.
     *
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Get all services using this product.
     */
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
        return $this->services()->exists() || ServiceUpgrade::query()->where('product_id', $this->id)->exists();
    }

    protected static function booted(): void
    {
        static::deleting(function (self $model) {
            if ($model->inUse()) {
                throw new DisplayException(__('This product is used by services or upgrades: hide it instead of deleting it.'));
            }
        });
    }

    /**
     * Get the settings of the product.
     */
    public function settings(): MorphMany
    {
        return $this->morphMany(Setting::class, 'settingable');
    }

    /**
     * Get all available products upgrades
     *
     * @return BelongsToMany<Product, $this>
     */
    public function upgrades(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_upgrades', 'product_id', 'upgrade_id');
    }

    /**
     * Gets all upgradable config options for the product.
     */
    public function upgradableConfigOptions(): HasManyThrough
    {
        return $this->hasManyThrough(ConfigOption::class, ConfigOptionProduct::class, 'product_id', 'id', 'id', 'config_option_id')->where('config_options.hidden', false)->where('config_options.upgradable', true)->orderBy('config_options.sort', 'asc')->orderBy('config_options.id', 'desc');
    }
}
