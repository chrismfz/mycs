<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\Service;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * An account's services follow its group discount (ROADMAP 5; reviews of #272): each live service whose price holds
 * another percentage (services.group_percent) than its account's now takes the account's, from its next renewal (an open
 * invoice keeps its lines, as WHMCS). The price the system computes when it was that one; any other (an old catalog's,
 * an import's, staff's, another tax decision's) by ratio: price × (100 − now) / (100 − then). Never a service staff
 * locked («Keep this price»). Under the service's row lock, reading the account's group in it: run again, it changes
 * nothing; two changes at once end at the last. The daily cron runs it for any account left behind (reconcile()).
 */
final class ClientGroupPricing
{
    /** @return int how many services were repriced */
    public static function reprice(Client $client): int
    {
        $repriced = 0;
        foreach (self::live()->where('client_id', $client->id)->pluck('id') as $id) {
            try {
                $repriced += DB::transaction(fn () => self::one((int) $id));
            } catch (Throwable $e) {
                report($e); // a lock wait, a failing save: the cron's reconcile() takes it up again
            }
        }

        return $repriced;
    }

    /** The cron's: the accounts with a live service whose price holds another group discount than theirs. */
    public static function reconcile(): int
    {
        $clients = self::live()
            ->join('clients', 'clients.id', '=', 'services.client_id')
            ->leftJoin('client_groups', 'client_groups.id', '=', 'clients.client_group_id')
            ->whereRaw('services.group_percent <> COALESCE(client_groups.discount_percent, 0)')
            ->distinct()->pluck('services.client_id');
        $repriced = 0;
        foreach (Client::query()->whereKey($clients)->get() as $client) {
            $repriced += self::reprice($client);
        }

        return $repriced;
    }

    /** @return Builder<Service> */
    private static function live(): Builder
    {
        return Service::query()->whereIn('services.status', [Service::STATUS_PENDING, Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
            ->where('services.price_locked', false);
    }

    private static function one(int $id): int
    {
        $service = Service::query()->whereKey($id)->lockForUpdate()->first();
        if ($service === null || $service->price_locked) {
            return 0;
        }
        $now = Client::query()->with('group')->find($service->client_id)?->groupPercent() ?? '0.00';
        $then = number_format((float) $service->group_percent, 2, '.', '');
        $now = number_format((float) $now, 2, '.', '');
        if (bccomp($then, $now, 2) === 0) {
            return 0;
        }
        $price = number_format((float) $service->price, 2, '.', '');
        if ($service->plan !== null && bccomp($price, $service->calculatePrice(groupPercent: $then), 2) === 0) {
            $new = $service->calculatePrice(groupPercent: $now);
        } elseif (bccomp($then, '100', 2) === 0) {
            // A whole discount leaves nothing to scale: the computed price, or staff decide.
            if ($service->plan === null) {
                return 0;
            }
            $new = $service->calculatePrice(groupPercent: $now);
        } else {
            $new = (string) BigDecimal::of($price)->multipliedBy(BigDecimal::of('100')->minus($now))
                ->dividedBy(BigDecimal::of('100')->minus($then), 2, RoundingMode::HalfUp);
        }
        $service->price = $new;
        $service->group_percent = $now;
        $service->save(); // a gateway subscription follows (Service\Updated)

        return 1;
    }
}
