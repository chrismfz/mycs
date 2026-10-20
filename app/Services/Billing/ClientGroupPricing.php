<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

/**
 * An account's group discount changed (it moved group, or its group's percentage changed; ROADMAP 5): the services
 * whose price is the one the system computes at the old discount take the one it computes now, from their next
 * renewal (an open invoice keeps its lines, as WHMCS). A price staff set by hand (any other) is kept: staff decide.
 * Under the service's row lock, as a renewal that reprices it (RenewalBilling).
 */
final class ClientGroupPricing
{
    /** @return int how many services were repriced */
    public static function reprice(Client $client, ?string $before): int
    {
        $ids = Service::query()->where('client_id', $client->id)
            ->whereIn('status', [Service::STATUS_PENDING, Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
            ->whereNotNull('plan_id')->pluck('id');
        $repriced = 0;
        foreach ($ids as $id) {
            $repriced += DB::transaction(function () use ($id, $before) {
                $service = Service::query()->whereKey($id)->lockForUpdate()->first();
                if ($service === null || $service->plan === null) {
                    return 0;
                }
                $was = $service->calculatePrice(groupPercent: $before ?? '0');
                if (bccomp(number_format((float) $service->price, 2, '.', ''), $was, 2) !== 0) {
                    return 0; // staff's price
                }
                $now = $service->calculatePrice();
                if (bccomp($was, $now, 2) === 0) {
                    return 0;
                }
                $service->price = $now;
                $service->save(); // a gateway subscription follows (Service\Updated)

                return 1;
            });
        }

        return $repriced;
    }
}
