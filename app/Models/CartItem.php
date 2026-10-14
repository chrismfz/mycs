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
use MyCS\Pricing\LinePricer;

/**
 * @property-read mixed $price
 */
#[ObservedBy(CartItemObserver::class)]
class CartItem extends Model
{
    /** The cents of {@see lineTotal()}, set when the price is worked out. */
    private ?int $lineTotalCents = null;

    /** The tax of {@see lineTotal()}, in cents. */
    private ?int $lineTaxCents = null;

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
                $line = OrderPricing::line($catalog, $discount, $client, $this->product);
                $full = $discount !== null && $line->hasDiscount() ? OrderPricing::line($catalog, null, $client, $this->product) : null;

                // The first invoice's line for the whole quantity (Δ1: net × quantity, the tax once on that; F4 of the
                // Σ7b review): what the cart adds up, so it is what checkout charges.
                $invoiceLine = LinePricer::invoiceLine($line->firstPaymentNet(), max(1, (int) $this->quantity),
                    OrderPricing::taxRate($client, $this->product));
                $this->lineTotalCents = (int) round((float) $invoiceLine['total'] * 100);
                $this->lineTaxCents = (int) round((float) $invoiceLine['tax'] * 100);

                return Price::fromLine($line, $currencyModel, $full);
            }
        )->shouldCache();
    }

    /** What this item adds to the first invoice, with tax (as its invoice line): 0 when it has no price. */
    public function lineTotal(): float
    {
        $price = $this->price;

        return $price->available ? ($this->lineTotalCents ?? 0) / 100 : 0.0;
    }

    /** The tax in {@see lineTotal()}. */
    public function lineTax(): float
    {
        $price = $this->price;

        return $price->available ? ($this->lineTaxCents ?? 0) / 100 : 0.0;
    }
}
