<?php

namespace App\Services\Service;

use App\Models\Coupon;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * Takes a coupon off a service (sweep ADMIN-4): staff's «Remove coupon», and the coupon deleted.
 *
 * A coupon for more than one cycle, or for ever, is in the service's renewal price from checkout (PlaceOrderService),
 * and only RenewalBilling took it back out, when its cycles end and only while the service still has it. Clearing
 * coupon_id alone made the discount permanent. So a coupon still running (not ended: coupon_expired_at null) that is
 * in the price gives the full price back, as RenewalBilling does when it ends. A first-cycle coupon was never in the
 * renewal price, and an ended one is out of it already: their price (perhaps staff's) is kept.
 *
 * The service is locked, as RenewalBilling locks it, so the cron does not bill it with a price half changed.
 */
class RemoveCoupon
{
    public static function handle(Service $service): void
    {
        DB::transaction(function () use ($service) {
            $locked = Service::query()->whereKey($service->id)->lockForUpdate()->first();
            if ($locked === null || $locked->coupon_id === null) {
                return;
            }
            if (self::discountInPrice($locked)) {
                $locked->price = $locked->calculatePrice(applyCoupon: false);
            }
            $locked->coupon_id = null;
            $locked->save();
            $service->setRawAttributes($locked->getAttributes(), true);
            $service->unsetRelation('coupon');
        });
    }

    /**
     * The service's coupon is still running and in its renewal price: taken off, the full price comes back. Also for
     * staff clearing the Coupon on the service page or through the API (Service::applyStaffChanges, review REV-ADMIN-1).
     */
    public static function discountInPrice(Service $locked): bool
    {
        $coupon = $locked->coupon_id === null ? null : $locked->coupon;

        return $coupon !== null && $coupon->cycles() !== 1 && $locked->coupon_expired_at === null;
    }

    /** Before the coupon is deleted: the foreign key would set coupon_id null with no model event, its discount kept. */
    public static function fromAll(Coupon $coupon): void
    {
        Service::query()->where('coupon_id', $coupon->id)->orderBy('id')->pluck('id')
            ->each(fn ($id) => self::handle(Service::query()->findOrFail($id)));
    }
}
