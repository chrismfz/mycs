<?php

namespace App\Models;

use App\Observers\CartObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $client_id
 */
#[ObservedBy(CartObserver::class)]
class Cart extends Model
{
    use HasUlids;

    protected $fillable = [
        'client_id',
        'currency_code',
        'coupon_id',
    ];

    public function uniqueIds()
    {
        return [
            'ulid',
        ];
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** How long a cart nobody touched is kept (sweep 9γ, owner 2026-10-30: deleted after a normal time). */
    public const KEEP_DAYS = 30;

    /**
     * The carts nobody touched for KEEP_DAYS, the cart nor any of its items (the daily cron): deleted with their items. A
     * checkout deletes its own cart; these are the ones left behind, with what they hold (a domain's EPP code, a
     * customer's choices). Returns how many.
     */
    public static function prune(): int
    {
        $cutoff = now()->subDays(self::KEEP_DAYS);
        $count = 0;
        self::query()->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('items', fn ($items) => $items->where('updated_at', '>=', $cutoff))
            ->select('id')->chunkById(500, function ($carts) use (&$count, $cutoff) {
                // The condition again in the delete: an item added since the chunk was read keeps its cart (ADV-CART-1).
                // Its items go with it (cart_items.cart_id cascades).
                $count += self::query()->whereKey($carts->pluck('id'))->where('updated_at', '<', $cutoff)
                    ->whereDoesntHave('items', fn ($items) => $items->where('updated_at', '>=', $cutoff))->delete();
            });

        return $count;
    }
}
