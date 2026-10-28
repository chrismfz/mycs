<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

    /** Still a service's that ended. */
    public static function whereHeld(Builder $query): Builder
    {
        return $query->whereHas('service', fn (Builder $service) => $service->whereIn('status', self::ENDED));
    }

    /**
     * The guests this address is seen on (proxmox:sync's read of their agents and configs); a prefix, any address of it.
     * Every guest read once a request: a pool's list asks it of each row.
     *
     * @return list<ProxmoxGuest>
     */
    public function seenOn(): array
    {
        $prefix = str_contains($this->address, '/');

        return self::guests()->filter(fn (ProxmoxGuest $guest) => collect((array) $guest->ips)->contains(function ($ip) use ($prefix) {
            $ip = explode('/', (string) $ip, 2)[0];

            return $prefix ? IpRange::contains($this->address, $ip) : $ip === $this->address;
        }))->values()->all();
    }

    /** @return Collection<int, ProxmoxGuest> */
    private static function guests(): Collection
    {
        return once(fn () => ProxmoxGuest::query()->whereNotNull('ips')->whereNull('gone_at')->get());
    }
}
