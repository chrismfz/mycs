<?php

namespace App\Services\Payment;

use App\Models\Service;
use App\Services\Money\PaymentsLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * A gateway subscription that ended at the gateway (the customer cancelled it there, it expired, the gateway suspended
 * it): nothing bills it any more, so its services keep no subscription id. Kept, the cron would leave their invoices
 * (AutoCharge::due), the reminders would say they pay themselves and Mass Pay would refuse them: nothing would pay them.
 *
 * The id is kept on the service (`ended_subscription_id:<id>`), so a sale of it that comes after (webhooks come in any
 * order) still finds them: recorded on their invoices, or as credit when they were paid meanwhile, never lost.
 */
final class SubscriptionEnded
{
    /** The property kept per ended id, `ended_subscription_id:<id>`: one each, so a second ended one never hides the first. */
    public const KEPT = 'ended_subscription_id';

    /**
     * @param  list<string>  $markers  the gateway's own marker properties of a subscription (has_paypal_subscription)
     * @return list<int> the services it was cleared from
     */
    public static function clear(string $gateway, string $subscriptionId, string $why, array $markers = []): array
    {
        if ($subscriptionId === '') {
            return [];
        }
        $cleared = [];
        foreach (Service::query()->where('subscription_id', $subscriptionId)->orderBy('id')->pluck('id') as $id) {
            DB::transaction(function () use ($id, $gateway, $subscriptionId, $why, $markers, &$cleared) {
                $service = Service::query()->whereKey($id)->lockForUpdate()->first();
                // As the database matched it (MariaDB: case and trailing spaces aside); another id: staff changed it meanwhile.
                if ($service === null || strcasecmp(rtrim((string) $service->subscription_id), $subscriptionId) !== 0) {
                    return;
                }
                // The marker first: the gateway's listener of the service's update never cancels what has ended.
                $service->properties()->whereIn('key', $markers)->delete();
                self::keep($service, $subscriptionId);
                $service->update(['subscription_id' => null]);
                $service->auditEvent = 'subscription_ended_at_gateway';
                $service->isCustomEvent = true;
                $service->auditCustomOld = ['subscription_id' => $subscriptionId];
                $service->auditCustomNew = ['subscription_id' => null, 'gateway' => $gateway, 'why' => $why];
                Event::dispatch(new AuditCustom($service));
                $cleared[] = (int) $id;
            });
        }
        if ($cleared !== []) {
            PaymentsLog::info(sprintf('The %s subscription %s ended at the gateway (%s): cleared from service #%s, whose invoices are charged and paid as any other now.',
                $gateway, $subscriptionId, $why, implode(', #', $cleared)), ['gateway' => $gateway, 'subscription_id' => $subscriptionId, 'services' => $cleared]);
        }

        return $cleared;
    }

    /**
     * The id kept on a service whose subscription no longer pays it here (ended at the gateway, or cancelled by staff):
     * a payment of it that comes after still finds the service.
     */
    public static function keep(Service $service, string $subscriptionId): void
    {
        $service->properties()->updateOrCreate(['key' => self::keptKey($subscriptionId)], ['name' => 'Ended subscription', 'value' => $subscriptionId]);
        $service->unsetRelation('properties');
    }

    /**
     * The services a payment of the subscription is for: those it pays, or else those it paid until it ended.
     *
     * @return Builder<Service>
     */
    public static function services(string $subscriptionId): Builder
    {
        $paying = Service::query()->where('subscription_id', $subscriptionId);
        if ($subscriptionId === '') {
            return $paying->whereRaw('1 = 0'); // an empty id is no subscription's
        }
        if ((clone $paying)->exists()) {
            return $paying;
        }

        return self::ended($subscriptionId);
    }

    /** @return Builder<Service> the services the subscription paid until it ended here */
    public static function ended(string $subscriptionId): Builder
    {
        return Service::query()->whereHas('properties', fn ($kept) => $kept->where('key', self::keptKey($subscriptionId)));
    }

    public static function keptKey(string $subscriptionId): string
    {
        return self::KEPT . ':' . $subscriptionId;
    }

    /** Its ended ids go: a service moved to another customer is never paid by the first one's late sale. */
    public static function forget(Service $service): void
    {
        $service->properties()->where('key', 'like', self::KEPT . ':%')->delete();
        $service->unsetRelation('properties');
    }
}
