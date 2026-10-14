<?php

namespace App\Services\Order;

use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Jobs\Server\CreateJob;
use App\Models\Order;
use App\Models\Service;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

/**
 * Staff's «Accept Order» (Σ1-b2, WHMCS): the order turns active and every pending service of it is set up, paid or not
 * (staff have full freedom, decision 4). «Run module create»: a service with a server gets its account (CreateJob: it
 * turns active once the account exists); one without, or with the create not run (built by hand), turns active now.
 * Its dates follow (Service::datesOnStart): paid and waited, the period counts from today; unpaid, due from the order.
 */
final class AcceptOrder
{
    /** @throws DisplayException when it is not pending, or nothing of it is left to set up (all ended) */
    public static function handle(Order $order, ?Staff $staff, bool $runCreate = true, bool $sendEmail = true): void
    {
        DB::transaction(function () use ($order, $staff, $runCreate, $sendEmail) {
            // Its services, then the order: the order a payment or a create finishing takes (review R8).
            $services = Service::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Order::STATUS_PENDING) {
                throw new DisplayException(__('Only a pending order can be accepted.'));
            }
            if ($services->isNotEmpty() && $services->every(fn (Service $service) => $service->isEnded())) {
                throw new DisplayException(__('Every service of this order has ended: there is nothing to accept.')); // ADV-6
            }
            $locked->forceFill(['status' => Order::STATUS_ACTIVE, 'accepted_at' => now(), 'accepted_by_staff_id' => $staff?->id])->save();

            foreach ($services->where('status', Service::STATUS_PENDING) as $service) {
                if ($runCreate && $service->provisioningServer() !== null) {
                    CreateJob::dispatch($service, $sendEmail)->afterCommit();

                    continue;
                }
                $service->status = Service::STATUS_ACTIVE;
                $service->save();
                // «Send welcome email» on its own (review R5): also for a service built by hand.
                if ($sendEmail) {
                    $id = $service->id;
                    DB::afterCommit(fn () => ($started = Service::query()->find($id)) && $started->client
                        ? NotificationHelper::serverCreatedNotification($started->client, $started) : null);
                }
            }
            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
