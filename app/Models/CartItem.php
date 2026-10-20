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
        'domain',
        'epp_code',
    ];

    protected $casts = [
        'config_options' => 'array',
        'checkout_config' => 'array',
        // A domain to register or transfer (D8b): {fqdn, tld_id, operation, years}; its EPP code apart, encrypted.
        'domain' => 'array',
        'epp_code' => 'encrypted',
    ];

    protected $hidden = ['epp_code'];

    /** A domain to register or transfer (D8b), not a product. */
    public function isDomain(): bool
    {
        return is_array($this->domain) && isset($this->domain['fqdn']);
    }

    /** What the cart shows for it: the product, or «nixpal.com: registration, 1 year». */
    public function title(): string
    {
        if ($this->isDomain()) {
            return trans_choice('domains.order.cart_' . $this->domain['operation'], (int) $this->domain['years'],
                ['domain' => $this->domain['fqdn'], 'years' => (int) $this->domain['years']]);
        }

        return (string) $this->product?->name;
    }

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
                if ($this->isDomain()) {
                    return $this->domainPrice($currency);
                }
                $catalog = OrderPricing::catalog($this->product, $this->plan, $this->config_options ?? [], $currency);
                // A plan or paid option without a price row makes the whole item unavailable, not free.
                if ($catalog === null) {
                    return new Price((object) ['price' => null, 'setup_fee' => null, 'currency' => null]);
                }
                $currencyModel = Currency::query()->find($currency);
                $client = Auth::guard('web')->check() ? CurrentClient::model() : null;

                $discount = $this->cart->coupon_id ? OrderPricing::discount($this->cart->coupon, $this->product_id, $this->plan, $currency) : null;
                $line = OrderPricing::line($catalog, $discount, $client, $this->product);
                $full = $discount !== null && $line->hasDiscount() ? OrderPricing::line($catalog, null, $client, $this->product) : null;

                // The first invoice's line for the whole quantity (Δ1: net × quantity, the tax once on that; F4 of the
                // Σ7b review): what the cart adds up, so it is what checkout charges.
                // A pro rata product's first period runs to its day (decision 3, PR D).
                $invoiceLine = LinePricer::invoiceLine(OrderPricing::firstPaymentNet($line, $this->product, $this->plan, now()), max(1, (int) $this->quantity),
                    OrderPricing::taxRate($client, $this->product));
                $this->lineTotalCents = (int) round((float) $invoiceLine['total'] * 100);
                $this->lineTaxCents = (int) round((float) $invoiceLine['tax'] * 100);

                return Price::fromLine($line, $currencyModel, $full);
            }
        )->shouldCache();
    }

    /** A domain's: its TLD's price for the operation and years, as the catalog shows it, taxed as any domain line (D-19). */
    private function domainPrice(string $currency): Price
    {
        $tld = DomainTld::query()->with('prices')->find($this->domain['tld_id'] ?? null);
        $amount = $tld?->priceFor((string) ($this->domain['operation'] ?? ''), (int) ($this->domain['years'] ?? 0), $currency);
        if ($amount === null) {
            return new Price((object) ['price' => null, 'setup_fee' => null, 'currency' => null]);
        }
        $client = Auth::guard('web')->check() ? CurrentClient::model() : null;
        $line = OrderPricing::line(['price' => $amount, 'setup_fee' => '0.00'], null, $client);
        $invoiceLine = LinePricer::invoiceLine($line->netPrice, 1, OrderPricing::taxRate($client));
        $this->lineTotalCents = (int) round((float) $invoiceLine['total'] * 100);
        $this->lineTaxCents = (int) round((float) $invoiceLine['tax'] * 100);

        return Price::fromLine($line, Currency::query()->find($currency));
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
