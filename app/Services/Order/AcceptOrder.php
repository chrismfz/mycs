<?php

namespace App\Services\Order;

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
    public static function handle(Order $order, ?Staff $staff, bool $runCreate = true, bool $sendEmail = true): void
    {
        DB::transaction(function () use ($order, $staff, $runCreate, $sendEmail) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['status' => Order::STATUS_ACTIVE, 'accepted_at' => now(), 'accepted_by_staff_id' => $staff?->id])->save();

            $services = Service::query()->where('order_id', $order->id)->where('status', Service::STATUS_PENDING)->orderBy('id')->lockForUpdate()->get();
            foreach ($services as $service) {
                if ($runCreate && $service->provisioningServer() !== null) {
                    CreateJob::dispatch($service, $sendEmail)->afterCommit();

                    continue;
                }
                $service->status = Service::STATUS_ACTIVE;
                $service->save();
                if ($sendEmail && $runCreate) {
                    $id = $service->id;
                    DB::afterCommit(fn () => ($started = Service::query()->find($id)) && $started->client
                        ? NotificationHelper::serverCreatedNotification($started->client, $started) : null);
                }
            }
            $order->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
