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
    public static function line(array $catalog, ?Discount $discount, ?Client $client): LinePrice
    {
        return LinePricer::price($catalog['price'], $catalog['setup_fee'], $discount, self::taxRate($client), self::taxMode());
    }

    public static function taxRate(?Client $client): ?string
    {
        if (!config('settings.tax_enabled', false)) {
            return null;
        }
        $tax = Settings::tax($client);

        return $tax instanceof TaxRate ? number_format((float) $tax->rate, 4, '.', '') : null;
    }

    public static function taxMode(): string
    {
        return config('settings.tax_type', 'inclusive') === 'exclusive' ? LinePricer::TAX_EXCLUSIVE : LinePricer::TAX_INCLUSIVE;
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
