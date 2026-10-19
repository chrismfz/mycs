<?php

namespace App\Models;

use App\Events\Service\RenewalTaxChanged;
use App\Exceptions\DisplayException;
use App\Models\Traits\HasPlans;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use MyCS\BillingPeriod\Prorata;
use OwenIt\Auditing\Contracts\Auditable;

class Product extends Model implements Auditable
{
    use HasFactory, HasPlans, Traits\Auditable;

    /** When its service is set up (WHMCS «Auto Setup», Σ1-b): with the order, the first payment, staff's Accept, never. */
    public const AUTOSETUP_ORDER = 'order';

    public const AUTOSETUP_PAYMENT = 'payment';

    public const AUTOSETUP_ACCEPT = 'accept';

    public const AUTOSETUP_NEVER = 'never';

    /** WHMCS's tblproducts.autosetup → ours. */
    public const AUTOSETUP_FROM_WHMCS = ['order' => self::AUTOSETUP_ORDER, 'payment' => self::AUTOSETUP_PAYMENT, 'on' => self::AUTOSETUP_ACCEPT, '' => self::AUTOSETUP_NEVER];

    /** Set up by the app when paid (or ordered): not waiting for staff. */
    public function setsUpOnPayment(): bool
    {
        return in_array($this->autosetup ?? self::AUTOSETUP_PAYMENT, [self::AUTOSETUP_ORDER, self::AUTOSETUP_PAYMENT], true);
    }

    protected $guarded = [];

    protected $casts = [
        'taxable' => 'boolean',
        'prorata_day' => 'integer',
        'prorata_next_after_day' => 'integer',
    ];

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
     *
     * @return HasManyThrough<ConfigOption, ConfigOptionProduct, $this>
     */
    public function configOptions(): HasManyThrough
    {
        return $this->hasManyThrough(ConfigOption::class, ConfigOptionProduct::class, 'product_id', 'id', 'id', 'config_option_id')->where('config_options.hidden', false)->orderBy('config_options.sort', 'asc')->orderBy('config_options.id', 'desc');
    }

    /** @return BelongsTo<ServerGroup, $this> new accounts go to a server of this group (WHMCS's servergroup) */
    public function serverGroup(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class);
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
        // Taxable or not changes what its services' subscriptions bill (Δ1).
        static::updated(function (self $model) {
            if ($model->wasChanged('taxable')) {
                RenewalTaxChanged::forSubscribed(Service::query()->where('product_id', $model->id));
            }
        });
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

    /**
     * Its services of $plan renew on its pro rata day (decision 3, PR D): on, and a month or year cycle (a day or week
     * cycle, a one-time or free plan has no day of the month to align to).
     */
    public function proratesPlan(?Plan $plan): bool
    {
        return $this->prorata_day !== null && $this->prorata_day >= 1 && $this->prorata_day <= Prorata::MAX_DAY
            && $plan !== null && $plan->type === 'recurring'
            && in_array($plan->billing_unit, ['month', 'year'], true) && (int) $plan->billing_period >= 1;
    }
}
