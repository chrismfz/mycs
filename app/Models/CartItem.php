<?php

namespace App\Models;

use App\Classes\Price;
use App\Observers\CartItemObserver;
use App\Services\Order\OrderPricing;
use App\Support\CurrentClient;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * @property-read mixed $price
 */
#[ObservedBy(CartItemObserver::class)]
class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'product_id',
        'plan_id',
        'config_options',
        'checkout_config',
        'quantity',
    ];

    protected $casts = [
        'config_options' => 'array',
        'checkout_config' => 'array',
    ];

    // Set default loads

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * What this item costs, priced the way checkout charges it (Σ7, `OrderPricing`): in the cart's currency (the
     * invoice inherits it, while the session may have gone back to the default one), with the cart's coupon where it
     * covers the product and the current account's tax.
     *
     * @return Attribute<mixed, mixed>
     */
    public function price(): Attribute
    {
        return Attribute::make(
            get: function () {
                $currency = $this->cart->currency_code ?? session('currency', config('settings.default_currency'));
                $catalog = OrderPricing::catalog($this->product, $this->plan, $this->config_options ?? [], $currency);
                // A plan or paid option without a price row makes the whole item unavailable, not free.
                if ($catalog === null) {
                    return new Price((object) ['price' => null, 'setup_fee' => null, 'currency' => null]);
                }
                $currencyModel = Currency::query()->find($currency);
                $client = Auth::guard('web')->check() ? CurrentClient::model() : null;

                $discount = $this->cart->coupon_id ? OrderPricing::discount($this->cart->coupon, $this->product_id) : null;
                $line = OrderPricing::line($catalog, $discount, $client);
                $full = $discount !== null && $line->hasDiscount() ? OrderPricing::line($catalog, null, $client) : null;

                return Price::fromLine($line, $currencyModel, $full);
            }
        );
    }
}
