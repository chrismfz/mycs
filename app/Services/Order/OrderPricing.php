<?php

namespace App\Services\Order;

use App\Classes\Settings;
use App\Models\Client;
use App\Models\ConfigOption;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Product;
use App\Models\TaxRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use MyCS\BillingPeriod\Prorata;
use MyCS\Pricing\Discount;
use MyCS\Pricing\LinePrice;
use MyCS\Pricing\LinePricer;

/**
 * The one place an order line is priced (Σ7, docs/orders.md): the cart shows it, checkout and the staff's «Create
 * Order» charge it, and a service's recalculation stores it. The arithmetic is `mycs/pricing`; this reads the catalog,
 * the coupon and the account's tax.
 */
final class OrderPricing
{
    /**
     * The catalog price and setup fee of a product's plan with its chosen options, in a currency.
     *
     * @param  array<int, array<string, mixed>>  $configOptions  the cart's shape: [['option_id' => …, 'value' => …], …]
     * @return array{price: string, setup_fee: string}|null null when the plan or a chosen paid option has no price in
     *                                                      that currency (it must not check out free)
     */
    public static function catalog(Product $product, Plan $plan, array $configOptions, string $currency): ?array
    {
        if ($plan->type === 'free') {
            $price = $setupFee = '0.00';
        } else {
            $row = $plan->prices->where('currency_code', $currency)->first();
            if ($row === null) {
                return null;
            }
            $price = self::amount($row->price);
            $setupFee = self::amount($row->setup_fee);
        }

        /** @var ConfigOption $option */
        foreach ($product->configOptions as $option) {
            $value = self::chosen($configOptions, $option);
            if ($value === null) {
                continue;
            }
            // A checkbox costs its one child's price when ticked; the other types choose a child.
            $child = $option->type === 'checkbox' ? $option->children->first() : $option->children->firstWhere('id', $value);
            if ($child === null) {
                continue;
            }
            $childPrice = $child->price(billing_period: $plan->billing_period, billing_unit: $plan->billing_unit, currency: $currency);
            if (!$childPrice->available) {
                return null;
            }
            // A free plan has no cycle to charge an option in: a paid option makes it unavailable, not free (ADV-5).
            if ($plan->type === 'free' && ((float) $childPrice->price > 0 || (float) $childPrice->setup_fee > 0)) {
                return null;
            }
            $price = (string) BigDecimal::of($price)->plus(self::amount($childPrice->price));
            $setupFee = (string) BigDecimal::of($setupFee)->plus(self::amount($childPrice->setup_fee));
        }

        return ['price' => $price, 'setup_fee' => $setupFee];
    }

    /** The coupon's discount for a product, or null when it does not cover it (CART-2). */
    public static function discount(?Coupon $coupon, int $productId): ?Discount
    {
        if ($coupon === null || !$coupon->covers($productId)) {
            return null;
        }

        return new Discount($coupon->type, self::amount($coupon->value), $coupon->applies_to ?? 'all');
    }

    /**
     * Price a line for an account (its country decides the tax rate).
     *
     * @param  array{price: string, setup_fee: string}  $catalog
     */
    public static function line(array $catalog, ?Discount $discount, ?Client $client, ?Product $product = null): LinePrice
    {
        return LinePricer::price($catalog['price'], $catalog['setup_fee'], $discount, self::taxRate($client, $product), self::taxMode());
    }

    /**
     * The first period of a pro rata product's service ordered on $start (decision 3, PR D): to the product's day, and the
     * next full period too when ordered on or after its «charge next month» day. Null when the product does not prorate
     * this plan (the period then starts when it is paid, docs/billing-cursor.md).
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public static function firstPeriod(Product $product, Plan $plan, CarbonInterface $start): ?array
    {
        if (!$product->proratesPlan($plan)) {
            return null;
        }
        $start = CarbonImmutable::instance($start)->startOfDay();
        $period = Prorata::firstPeriod($start, (string) $plan->billing_unit, (int) $plan->billing_period, (int) $product->prorata_day, $product->prorata_next_after_day);

        return ['start' => $start, 'end' => $period['end']];
    }

    /**
     * One unit's first invoice, net (Δ1: the invoice line adds its tax): the (discounted) price, or staff's, and the setup
     * fee. A pro rata product's price is for its first period, to its day (decision 3, PR D).
     */
    public static function firstPaymentNet(LinePrice $price, Product $product, Plan $plan, CarbonInterface $start, ?string $staffPrice = null): string
    {
        $recurring = $staffPrice ?? $price->netPrice;
        if ($product->proratesPlan($plan)) {
            $recurring = Prorata::firstPrice((string) BigDecimal::of($recurring)->toScale(2, RoundingMode::HalfUp), $start,
                (string) $plan->billing_unit, (int) $plan->billing_period, (int) $product->prorata_day, $product->prorata_next_after_day);
        }

        return (string) BigDecimal::of($recurring)->plus($price->netSetupFee)->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * The tax rate for an account, and for a product when given: none when tax is off, the account is tax exempt or
     * the product is not taxable (WHMCS `taxexempt`, `tblproducts.tax`; docs/decisions.md Δ1).
     */
    public static function taxRate(?Client $client, ?Product $product = null): ?string
    {
        if (!config('settings.tax_enabled', false) || $client?->tax_exempt || ($product !== null && !$product->taxable)) {
            return null;
        }
        $tax = Settings::tax($client);

        return $tax instanceof TaxRate ? number_format((float) $tax->rate, 4, '.', '') : null;
    }

    /**
     * A catalog amount (a proration, a difference: may be negative) without tax: as is when tax is exclusive or there is
     * none, the tax taken out when the catalog is inclusive (Δ1: what lines store is net).
     */
    public static function toNet(string $catalogAmount, ?Client $client, ?Product $product = null): string
    {
        $amount = BigDecimal::of($catalogAmount);
        $rate = self::taxRate($client, $product);
        if ($rate === null || self::taxMode() === LinePricer::TAX_EXCLUSIVE) {
            return (string) $amount->toScale(2, RoundingMode::HalfUp);
        }

        return (string) $amount->multipliedBy(100)->dividedBy(BigDecimal::of($rate)->plus(100), 2, RoundingMode::HalfUp);
    }

    public static function taxMode(): string
    {
        return config('settings.tax_type', 'exclusive') === 'exclusive' ? LinePricer::TAX_EXCLUSIVE : LinePricer::TAX_INCLUSIVE;
    }

    /** @param  array<int, array<string, mixed>>  $configOptions */
    private static function chosen(array $configOptions, ConfigOption $option): mixed
    {
        // Text and number options carry a value but no price.
        if (in_array($option->type, ['text', 'number'], true)) {
            return null;
        }
        $selected = collect($configOptions)->firstWhere('option_id', $option->id);
        // isset, not ->value: an option added after the item was put in the cart has no entry (CART-10).
        $value = is_array($selected) && isset($selected['value']) ? $selected['value'] : null;
        if ($option->type === 'checkbox') {
            return self::ticked($value) ? true : null;
        }

        return $value === null || $value === '' ? null : $value;
    }

    /** A checkbox option's value: the cart keeps its child's id, the product page true/«true». */
    public static function ticked(mixed $value): bool
    {
        return !in_array($value, [null, false, '', 0, '0', 'false'], true);
    }

    private static function amount(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
