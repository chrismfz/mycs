<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * An offer (ROADMAP 6α): /offer/{slug} puts its products in the cart and applies its coupon (WHMCS's Bundles; nixpal's
 * «Zonecloud Kit» with FREETRIAL). Its coupon's rules still apply at checkout (Coupon::refusalFor).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $active
 */
class Offer extends Model implements Auditable
{
    use Traits\Auditable;

    protected $fillable = ['name', 'slug', 'description', 'coupon_id', 'active', 'starts_at', 'expires_at'];

    protected $casts = [
        'active' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** @return HasMany<OfferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OfferItem::class)->orderBy('sort')->orderBy('id');
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** Open now: active and within its dates. */
    public function isOpen(): bool
    {
        return $this->active && ($this->starts_at === null || $this->starts_at->isPast()) && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function url(): string
    {
        return route('offers.show', ['offer' => $this->slug]);
    }
}
