<?php

namespace App\Services\Ip;

use App\Exceptions\DisplayException;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use MyCS\Proxmox\IpRange;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * Gives a service an address of a pool and takes it back (docs/proxmox.md, B3). Each change is one conditional update:
 * of two at once (two staff, two tabs) only one gets the address, on any database. The one released longest ago is given
 * first, so an address just freed is not on another customer's VM the next minute. The service's dedicated IP follows its
 * first IPv4 (and its next one when that goes); each change is audited on the service.
 */
final class IpAllocator
{
    /** An address of the pool, or that one; refused when none is free. */
    public static function assign(Service $service, IpPool $pool, ?IpAddress $address = null): IpAddress
    {
        if (in_array($service->status, Service::ENDED_STATUSES, true)) {
            throw new DisplayException("Service #{$service->id} has ended: it is given no address.");
        }
        if ($address !== null && $address->ip_pool_id !== $pool->id) {
            throw new DisplayException("{$address->address} is not of the pool {$pool->name}.");
        }
        // Never a pool's gateway, or its network or broadcast address, however it got in (ADV-505-R2-1).
        $forbidden = array_keys(IpPool::forbidden()['v4']);
        $candidates = IpAddress::query()->where('ip_pool_id', $pool->id)->tap(fn ($q) => IpAddress::whereFree($q))->whereNotIn('address', $forbidden)
            ->when($address !== null, fn ($q) => $q->whereKey($address->id))
            ->orderByRaw('released_at is not null')->orderBy('released_at')->orderBy('id')->limit(20)->pluck('id');
        foreach ($candidates as $id) {
            $claimed = DB::transaction(function () use ($id, $service) {
                // Its status as the row has it now, under its lock: a model loaded before its termination gets nothing
                // (ADV-505-8).
                $status = Service::query()->whereKey($service->id)->lockForUpdate()->value('status');
                if ($status === null || in_array($status, Service::ENDED_STATUSES, true)) {
                    throw new DisplayException("Service #{$service->id} has ended: it is given no address.");
                }
                $won = IpAddress::query()->whereKey($id)->tap(fn ($q) => IpAddress::whereFree($q))
                    ->update(['service_id' => $service->id, 'assigned_at' => now(), 'released_at' => null, 'updated_at' => now()]) === 1;
                if ($won) {
                    $ip = IpAddress::query()->findOrFail($id);
                    if (IpRange::isV4($ip->address)) {
                        // Only an empty one: a dedicated IP staff typed stays theirs.
                        Service::query()->whereKey($service->id)->where(fn ($q) => $q->whereNull('dedicated_ip')->orWhere('dedicated_ip', ''))
                            ->update(['dedicated_ip' => $ip->address]);
                    }
                }

                return $won;
            });
            if ($claimed) {
                $ip = IpAddress::query()->findOrFail($id);
                self::audit($service, 'ip_assigned', $ip);

                return $ip;
            }
        }

        throw new DisplayException($address !== null ? "{$address->address} is no longer free." : "No free address in {$pool->name}.");
    }

    /** Back to the pool, only from the service it is with (a page open since another change acts on nothing). */
    public static function release(IpAddress $address, ?int $fromServiceId = null): void
    {
        $serviceId = $fromServiceId ?? $address->service_id;
        if ($serviceId === null) {
            return;
        }
        $released = DB::transaction(function () use ($address, $serviceId) {
            // The service's row first, as assign and DeleteService take them (round 2 of #505: opposite orders deadlock).
            Service::query()->whereKey($serviceId)->lockForUpdate()->value('id');
            $done = IpAddress::query()->whereKey($address->id)->where('service_id', $serviceId)
                ->update(['service_id' => null, 'assigned_at' => null, 'released_at' => now(), 'updated_at' => now()]) === 1;
            if ($done) {
                // The dedicated IP passes to its next IPv4 of the pools, if it still has one (ADV-505-9).
                $next = IpAddress::query()->where('service_id', $serviceId)->where('address', 'not like', '%:%')->orderBy('assigned_at')->orderBy('id')->value('address');
                Service::query()->whereKey($serviceId)->where('dedicated_ip', $address->address)->update(['dedicated_ip' => $next]);
            }

            return $done;
        });
        if (!$released) {
            throw new DisplayException("{$address->address} is no longer with service #{$serviceId}.");
        }
        $address->refresh();
        if ($service = Service::query()->find($serviceId)) {
            self::audit($service, 'ip_released', $address);
        }
    }

    /** Kept out of the pool (ours, a router's), only while free; and given back to it. */
    public static function reserve(IpAddress $address, bool $reserved, ?string $note = null): void
    {
        // A gateway, or a prefix holding one, stays reserved: «back to the pool» would give the router's address
        // (ADV-505-R2-1).
        if (!$reserved && ($why = self::notGiven($address->address)) !== null) {
            throw new DisplayException("{$address->address} is {$why}: it stays reserved.");
        }
        $query = IpAddress::query()->whereKey($address->id)->whereNull('service_id');
        $done = $query->update(['reserved' => $reserved, 'note' => $note ?? $address->note, 'updated_at' => now()]) === 1;
        if (!$done) {
            throw new DisplayException("{$address->address} is a service's: release it first.");
        }
        $address->refresh();
    }

    /** Why an address is never given (a pool's gateway, network or broadcast address), or null. */
    private static function notGiven(string $address): ?string
    {
        $forbidden = IpPool::forbidden();
        if (isset($forbidden['v4'][$address])) {
            return $forbidden['v4'][$address];
        }
        if (str_contains($address, '/')) {
            foreach ($forbidden['gateways6'] as $gateway) {
                if (IpRange::contains($address, $gateway)) {
                    return "the prefix of the gateway {$gateway}";
                }
            }
        }

        return null;
    }

    private static function audit(Service $service, string $event, IpAddress $address): void
    {
        $service->auditEvent = $event;
        $service->isCustomEvent = true;
        $service->auditCustomOld = [];
        $service->auditCustomNew = ['address' => $address->address, 'pool' => $address->ip_pool_id, 'staff_id' => Auth::guard('staff')->id()];
        event(new AuditCustom($service));
        $service->isCustomEvent = false; // a later save of this model is audited as itself
    }
}
