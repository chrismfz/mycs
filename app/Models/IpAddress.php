<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use MyCS\Proxmox\IpRange;

/**
 * One address of a pool (an IPv6 pool's: one prefix), free, reserved or a service's. Assigned and released only through
 * IpAllocator, which never gives one address to two services.
 *
 * @property bool $reserved
 * @property Carbon|null $assigned_at
 * @property Carbon|null $released_at
 */
class IpAddress extends Model
{
    public const FREE = 'free';

    public const RESERVED = 'reserved';

    public const ASSIGNED = 'assigned';

    /** A service ended (terminated, cancelled) still holds it: for staff to release, never given meanwhile. */
    public const HELD = 'held';

    /** A service in these is done with its address. */
    public const ENDED = Service::ENDED_STATUSES;

    protected $fillable = ['ip_pool_id', 'address', 'service_id', 'reserved', 'note', 'assigned_at', 'released_at'];

    protected function casts(): array
    {
        return ['reserved' => 'boolean', 'assigned_at' => 'datetime', 'released_at' => 'datetime'];
    }

    /** @return BelongsTo<IpPool, $this> */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(IpPool::class, 'ip_pool_id');
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function state(): string
    {
        return match (true) {
            $this->reserved => self::RESERVED,
            $this->service_id === null => self::FREE,
            in_array($this->service?->status, self::ENDED, true) => self::HELD,
            default => self::ASSIGNED,
        };
    }

    /** Neither reserved nor anyone's. */
    public static function whereFree(Builder $query): Builder
    {
        return $query->whereNull('service_id')->where('reserved', false);
    }

    /** Free and none of a pool's gateway, network or broadcast address: what is given (round 3 of #505). */
    public static function whereGivable(Builder $query): Builder
    {
        return self::whereFree($query)->whereNotIn('address', array_keys(IpPool::forbidden()['v4']));
    }

    /** Still a service's that ended. */
    public static function whereHeld(Builder $query): Builder
    {
        return $query->whereHas('service', fn (Builder $service) => $service->whereIn('status', self::ENDED));
    }

    /**
     * The guests this address is seen on (proxmox:sync's read of their agents and configs); a prefix, any address of it.
     * The guests are read once a request, into a map by address: a pool's list asks it of each row (round 3 of #505).
     *
     * @return list<ProxmoxGuest>
     */
    public function seenOn(): array
    {
        [$byAddress, $v6] = self::guests();
        if (!str_contains($this->address, '/')) {
            return $byAddress[$this->address] ?? [];
        }
        $seen = [];
        foreach ($v6 as [$ip, $guest]) {
            if (IpRange::contains($this->address, $ip) && !in_array($guest, $seen, true)) {
                $seen[] = $guest;
            }
        }

        return $seen;
    }

    /** @return array{0: array<string, list<ProxmoxGuest>>, 1: list<array{0: string, 1: ProxmoxGuest}>} */
    private static function guests(): array
    {
        return once(function () {
            $byAddress = [];
            $v6 = [];
            foreach (ProxmoxGuest::query()->whereNotNull('ips')->whereNull('gone_at')->get() as $guest) {
                foreach ((array) $guest->ips as $ip) {
                    $ip = explode('/', (string) $ip, 2)[0];
                    if (!in_array($guest, $byAddress[$ip] ?? [], true)) {
                        $byAddress[$ip][] = $guest;
                    }
                    if (str_contains($ip, ':')) {
                        $v6[] = [$ip, $guest];
                    }
                }
            }

            return [$byAddress, $v6];
        });
    }
}
