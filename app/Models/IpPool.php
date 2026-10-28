<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * Addresses added to the pool, one a line: an address, a range («a.b.c.d-a.b.c.e») or a network («a.b.c.0/27», its
     * hosts); an IPv6 pool's, prefixes (the pool's length when none is given). Each in the pool's network and family; the
     * gateway never. One already in a pool (this or another) is skipped, not moved.
     *
     * @return array{added: int, skipped: list<string>}
     *
     * @throws InvalidArgumentException a line that is none of these, or out of the pool's network
     */
    public function add(string $lines): array
    {
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
        foreach (array_chunk($new, 500) as $chunk) {
            IpAddress::query()->insertOrIgnore(array_map(fn (string $address) => ['ip_pool_id' => $this->id, 'address' => $address,
                'reserved' => false, 'created_at' => now(), 'updated_at' => now()], $chunk));
        }

        return ['added' => count($new), 'skipped' => array_values($taken)];
    }

    /** @return list<string> */
    private function expand(string $line): array
    {
        if ($this->family === 6) {
            $prefix = IpRange::normalize(str_contains($line, '/') ? $line : $line . '/' . $this->prefix);
            if ((int) explode('/', $prefix)[1] !== $this->prefix) {
                throw new InvalidArgumentException("{$line}: this pool gives /{$this->prefix} prefixes.");
            }
            $addresses = [$prefix];
        } elseif (str_contains($line, '-')) {
            [$from, $to] = array_map('trim', explode('-', $line, 2));
            $addresses = IpRange::range($from, $to, $this->gateway);
        } elseif (str_contains($line, '/')) {
            $addresses = IpRange::hosts($line, $this->gateway);
        } else {
            $addresses = [IpRange::normalize($line)];
        }
        foreach ($addresses as $address) {
            if (IpRange::isV4($address) !== ($this->family === 4)) {
                throw new InvalidArgumentException("{$address} is not IPv{$this->family}.");
            }
            if (filled($this->network) && !IpRange::contains($this->network, $address)) {
                throw new InvalidArgumentException("{$address} is not in {$this->network}.");
            }
            if ($address === $this->gateway) {
                throw new InvalidArgumentException("{$address} is the pool's gateway.");
            }
        }

        return $addresses;
    }
}
