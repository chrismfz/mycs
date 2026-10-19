<?php

namespace App\Services\Order;

use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Jobs\Server\CreateJob;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

/**
 * Staff's «Accept Order» (Σ1-b2, WHMCS): staff looked at what came in, and the order turns active. Every order waits for
 * it, set up or not, paid or not (owner 2026-10-19, as WHMCS): Accept is that look, not what sets things up.
 *
 * It sets up the pending services that wait for staff: a product set up «when the order is manually accepted» or never
 * automatically, and one whose payment came but whose setup has not (a create that failed). One still waiting for its
 * payment (a product set up on payment, an open invoice) keeps waiting: it is set up when paid, and cancelled by the
 * cron if never paid. Staff start those too with $startUnpaid (decision 4: full freedom, paid or not).
 *
 * «Run module create»: a service with a server gets its account (CreateJob: it turns active once the account exists);
 * one without, or with the create not run (built by hand), turns active now. Its dates follow (Service::datesOnStart):
 * paid and waited, the period counts from today; unpaid, due from the order.
 */
final class AcceptOrder
{
    /** @throws DisplayException when it is not pending, or nothing of it is left to set up (all ended) */
    public static function handle(Order $order, ?Staff $staff, bool $runCreate = true, bool $sendEmail = true, bool $startUnpaid = false): void
    {
        DB::transaction(function () use ($order, $staff, $runCreate, $sendEmail, $startUnpaid) {
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
                if (!$startUnpaid && self::waitsForPayment($service)) {
                    continue;
                }
                // Started not paid (staff asked, or its product waits for Accept): the cron no longer cancels it as unpaid.
                // This service only: another of the order left waiting for its payment still is (code review, 2026-10-19).
                if ($service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->exists()) {
                    $service->forceFill(['started_unpaid_at' => now()])->save();
                }
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

    /**
     * Set up on its first payment, by its product, and not paid yet: an open invoice of its own. One set up on the order
     * that is still pending had its create fail: it waits for staff, and Accept tries it again (code review, 2026-10-19).
     */
    public static function waitsForPayment(Service $service): bool
    {
        return ($service->product->autosetup ?? Product::AUTOSETUP_PAYMENT) === Product::AUTOSETUP_PAYMENT
            && $service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->exists();
    }
}
