<?php

namespace App\Services\Service;

use App\Models\Plan;
use App\Models\Product;

/**
 * An addon is always on its parent's billing cycle (owner 2026-11-09, KISS, docs/addons.md): with its parent's due date
 * too, it renews on its parent's invoice every time. The one rule «Add addon» and a parent's change of cycle (now, or
 * from the next renewal) both ask: which plan of the addon's product goes with a cycle.
 */
final class AddonCycle
{
    /**
     * The plan of the addon product $product for the cycle of $cycle (its parent's plan, or the one its parent moves to):
     * its recurring plan of the same period, priced in $currency. None: staff add that price in the catalogue first.
     * A product with no recurring plan at all is a free addon: its free plan, whatever the cycle (it bills nothing).
     */
    public static function plan(Product $product, ?Plan $cycle, ?string $currency): ?Plan
    {
        $recurring = $product->plans->where('type', 'recurring');
        if ($recurring->isEmpty()) {
            return $product->plans->firstWhere('type', 'free');
        }

        return $recurring->first(fn (Plan $plan) => self::same($plan, $cycle) && $plan->hasPriceIn($currency));
    }

    /** Two recurring plans of the same billing cycle (unit and count, as an upgrade matches them: Service::productUpgrades). */
    public static function same(?Plan $a, ?Plan $b): bool
    {
        return $a !== null && $b !== null && $a->type === 'recurring' && $b->type === 'recurring'
            && $a->billing_unit === $b->billing_unit && (int) $a->billing_period === (int) $b->billing_period;
    }
}
