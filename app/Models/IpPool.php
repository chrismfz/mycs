<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use MyCS\Proxmox\IpRange;

/**
 * A network the VPS take their addresses from (docs/proxmox.md, B3): its gateway, the addresses' prefix length (an IPv4
 * network's own; an IPv6 pool's, each service's prefix) and its DNS, told to the customer with the address.
 *
 * @property int $family
 * @property int $prefix
 * @property int|null $free_count withCount, the lists' own
 */
class IpPool extends Model
{
    protected $fillable = ['name', 'family', 'network', 'prefix', 'gateway', 'nameservers', 'server_id', 'whmcs_id', 'note'];

    /** @var array{v4: array<string, string>, gateways6: list<string>} what add() never takes, read once an add */
    private array $forbidden = ['v4' => [], 'gateways6' => []];

    protected function casts(): array
    {
        return ['family' => 'integer', 'prefix' => 'integer', 'whmcs_id' => 'integer'];
    }

    /** @return HasMany<IpAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    /** IPv4's dotted mask; an IPv6 pool has none. */
    public function netmask(): ?string
    {
        return $this->family === 4 ? IpRange::netmask($this->prefix) : null;
    }

    /** @return list<string> */
    public function nameserverList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $this->nameservers) ?: [])));
    }

    /**
     * Its IPv4 prefix is its network's (review of #505: a mask other than the network's tells customers a wrong one); its
     * gateway is kept normalized, as the addresses are.
     */
    protected static function booted(): void
    {
        static::saving(function (IpPool $pool) {
            if ($pool->family === 4 && filled($pool->network)) {
                $pool->prefix = (int) explode('/', IpRange::network((string) $pool->network))[1];
            }
            if (filled($pool->gateway)) {
                $pool->gateway = IpRange::normalize((string) $pool->gateway);
                // Checked again as it is written, under the pools' lock (the form's rule ran before it: round 3 of #505).
                if ($pool->isDirty('gateway') && ($conflict = self::gatewayConflict((string) $pool->gateway)) !== null) {
                    throw new InvalidArgumentException($conflict);
                }
            }
        });
    }

    /**
     * Why a gateway cannot be this pool's: it is an address of a pool, not reserved, or inside a prefix of one (ADV-505-5:
     * edited onto an address a customer holds, two machines on one address).
     */
    public static function gatewayConflict(string $gateway): ?string
    {
        $gateway = IpRange::normalize($gateway);
        $taken = IpAddress::query()->where('address', $gateway)->first();
        if ($taken !== null && !$taken->reserved) {
            return "{$gateway} is an address of the pool «{$taken->pool->name}»" . ($taken->service_id ? ", service #{$taken->service_id}'s" : '') . ': reserve it first.';
        }
        if (!IpRange::isV4($gateway)) {
            foreach (IpAddress::query()->where('address', 'like', '%/%')->get() as $prefix) {
                if (IpRange::contains($prefix->address, $gateway)) {
                    return "{$gateway} is inside {$prefix->address}, a prefix of the pool «{$prefix->pool->name}».";
                }
            }
        }

        return null;
    }

    /**
     * Addresses added to the pool, one a line: an address, a range («a.b.c.d-a.b.c.e») or a network («a.b.c.64/27»: its
     * addresses); an IPv6 pool's, prefixes (the pool's length when none is given). Each in the pool's network and family.
     * A range or a network leaves out the gateway and the network's own network and broadcast addresses; a single one of
     * these is refused (ADV-505-4). An IPv6 prefix overlapping one of any pool is refused (ADV-505-2); one already in a
     * pool, as it is, is skipped, not moved.
     *
     * @return array{added: int, skipped: list<string>}
     *
     * @throws InvalidArgumentException a line that is none of these, or out of the pool's network: nothing is added
     */
    public function add(string $lines): array
    {
        return self::writing(fn () => $this->addLocked($lines));
    }

    /**
     * One write of the pools at a time (round 3 of #505): what add() and a gateway's change check (overlaps, gateways,
     * edges) is read and written under it, so two tabs never pass the same check.
     *
     * @template T
     *
     * @param  \Closure(): T  $write
     * @return T
     */
    public static function writing(\Closure $write): mixed
    {
        return Cache::lock('ip-pools.write', 60)->block(15, $write);
    }

    /** @return array{added: int, skipped: list<string>} */
    private function addLocked(string $lines): array
    {
        $this->forbidden = self::forbidden();
        $wanted = [];
        foreach (preg_split('/[\r\n,;]+/', $lines) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $wanted = [...$wanted, ...$this->expand($line)];
            if (count($wanted) > IpRange::MAX) {
                throw new InvalidArgumentException('More than ' . IpRange::MAX . ' addresses at once.');
            }
        }
        $wanted = array_values(array_unique($wanted));
        $taken = IpAddress::query()->whereIn('address', $wanted)->pluck('address')->all();
        $new = array_values(array_diff($wanted, $taken));
        if ($this->family === 6) {
            $this->refuseOverlaps($new);
        }
        $added = 0;
        foreach (array_chunk($new, 500) as $chunk) {
            $added += IpAddress::query()->insertOrIgnore(array_map(fn (string $address) => ['ip_pool_id' => $this->id, 'address' => $address,
                'reserved' => false, 'created_at' => now(), 'updated_at' => now()], $chunk));
        }

        return ['added' => $added, 'skipped' => array_values($taken)];
    }

    /** @return list<string> */
    private function expand(string $line): array
    {
        if ($this->family === 6) {
            $prefix = IpRange::normalize(str_contains($line, '/') ? $line : $line . '/' . $this->prefix);
            if ((int) explode('/', $prefix)[1] !== $this->prefix) {
                throw new InvalidArgumentException("{$line}: this pool gives /{$this->prefix} prefixes.");
            }
            if (IpRange::isV4($prefix) || IpRange::overlaps('::ffff:0:0/96', $prefix)) {
                // An IPv4-mapped address is another spelling of an IPv4 one (ADV-505-11).
                throw new InvalidArgumentException("{$line} is not IPv6.");
            }
            if (filled($this->network) && !IpRange::contains((string) $this->network, $prefix)) {
                throw new InvalidArgumentException("{$prefix} is not in {$this->network}.");
            }
            // Any pool's gateway, not only this one's (ADV-505-R2-2).
            foreach ($this->forbidden['gateways6'] as $gateway) {
                if (IpRange::contains($prefix, $gateway)) {
                    throw new InvalidArgumentException("{$prefix} holds the gateway {$gateway}.");
                }
            }

            return [$prefix];
        }
        $single = !str_contains($line, '-') && !str_contains($line, '/');
        $addresses = match (true) {
            str_contains($line, '-') => IpRange::range(...array_map('trim', explode('-', $line, 2))),
            str_contains($line, '/') => IpRange::range(...IpRange::bounds($line)),
            default => [IpRange::normalize($line)],
        };
        $out = [];
        foreach ($addresses as $address) {
            if (!IpRange::isV4($address)) {
                throw new InvalidArgumentException("{$address} is not IPv4.");
            }
            if (filled($this->network) && !IpRange::contains((string) $this->network, $address)) {
                throw new InvalidArgumentException("{$address} is not in {$this->network}.");
            }
            $edges = $this->prefix < 31 ? IpRange::bounds(IpRange::networkOf($address, $this->prefix)) : [];
            // This pool's, and any pool's gateway or network or broadcast address (ADV-505-R2-2).
            $reason = match (true) {
                $address === $this->gateway => 'the pool\'s gateway',
                in_array($address, $edges, true) => "the /{$this->prefix} network's " . ($address === $edges[0] ? 'network' : 'broadcast') . ' address',
                default => $this->forbidden['v4'][$address] ?? null,
            };
            if ($reason === null) {
                $out[] = $address;
            } elseif ($single) {
                throw new InvalidArgumentException("{$address} is {$reason}.");
            }
        }

        return $out;
    }

    /** @param list<string> $prefixes */
    private function refuseOverlaps(array $prefixes): void
    {
        // One sweep over every prefix of every pool and the new ones (ADV-505-R2-4: each against each was minutes).
        $existing = IpAddress::query()->where('address', 'like', '%/%')->pluck('address')->all();
        $overlap = IpRange::firstOverlap([...$existing, ...$prefixes]);
        if ($overlap !== null) {
            [$a, $b] = in_array($overlap[1], $prefixes, true) ? $overlap : array_reverse($overlap);
            throw new InvalidArgumentException(in_array($a, $prefixes, true) ? "{$b} overlaps {$a}, both in this list." : "{$b} overlaps {$a}, already in a pool.");
        }
    }

    /**
     * What no pool gives: every pool's gateway, and every IPv4 pool network's network and broadcast address.
     *
     * @return array{v4: array<string, string>, gateways6: list<string>}
     */
    public static function forbidden(): array
    {
        $out = ['v4' => [], 'gateways6' => []];
        foreach (self::query()->get(['name', 'family', 'network', 'prefix', 'gateway']) as $pool) {
            if (filled($pool->gateway)) {
                if (IpRange::isV4((string) $pool->gateway)) {
                    $out['v4'][(string) $pool->gateway] = "the gateway of the pool «{$pool->name}»";
                } else {
                    $out['gateways6'][] = (string) $pool->gateway;
                }
            }
            if ($pool->family === 4 && filled($pool->network) && $pool->prefix < 31) {
                [$first, $last] = IpRange::bounds((string) $pool->network);
                $out['v4'][$first] = "the network address of the pool «{$pool->name}»";
                $out['v4'][$last] = "the broadcast address of the pool «{$pool->name}»";
            }
        }

        return $out;
    }
}
