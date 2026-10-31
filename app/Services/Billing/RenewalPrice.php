<?php

namespace App\Services\Billing;

use App\Models\Product;
use App\Models\Service;
use Carbon\CarbonImmutable;

/**
 * The service as the billing prices the period starting on $start (RenewalBilling's rules, never saved here): on its
 * next plan from a cycle change «from the next renewal» (SVC-14b), at the full price once a coupon «for N cycles» is past
 * or the product its coupon requires is gone.
 * Shared by the portal's Upcoming renewals and the revenue forecast (#275), so they never disagree with the billing.
 */
final class RenewalPrice
{
    public static function for(Service $service, CarbonImmutable $start, bool $first): Service
    {
        $next = $service->nextPlan;
        if ($first && $service->next_plan_id !== null && $next !== null && $next->priceable_type === Product::class
            && (int) $next->priceable_id === (int) $service->product_id) {
            $service = clone $service;
            $service->setRelation('plan', $next);
            $service->plan_id = $next->id;
            $service->price = (float) $service->calculatePrice();
        }
        if ($service->coupon && $service->coupon->cycles() > 1 && $service->coupon_expired_at === null
            && $service->cycleNumber($start) > $service->coupon->cycles()) {
            $service = clone $service;
            $service->price = (float) $service->calculatePrice(applyCoupon: false);
            $service->coupon_expired_at = CarbonImmutable::now();
        }
        // Its required product gone: the discount ends with it, as RenewalBilling bills it (C4 of #274; ADV-PM-R2-1).
        if ($service->coupon && $service->coupon_expired_at === null && !$service->coupon->requirementHeldBy($service->client)) {
            $service = clone $service;
            $service->price = (float) $service->calculatePrice(applyCoupon: false);
            $service->coupon_expired_at = CarbonImmutable::now();
        }

        return $service;
    }
}
