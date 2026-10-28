<?php

namespace App\Services\Proxmox;

use App\Exceptions\DisplayException;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Server;
use App\Models\Service;
use App\Services\Ip\IpAllocator;
use InvalidArgumentException;
use MyCS\Proxmox\IpRange;
use PDO;
use Throwable;

/**
 * The IP pools of WHMCS's Proxmox module (docs/proxmox.md, A5): its pools (mod_pvewhmcs_ip_pools, with their gateway),
 * their addresses (mod_pvewhmcs_ip_addresses, with their mask) and which VPS holds which (mod_pvewhmcs_vms, by service
 * id: the services are imported with the same ids). Re-runnable: a pool is matched by its WHMCS id and kept as staff left
 * it, an address already here is not moved, one already given is not given again. Read-only on WHMCS.
 */
final class WhmcsIpPoolImport
{
    /** @var array{pools: int, addresses: int, given: int, without_service: int, conflicts: list<string>, outside: list<string>} */
    private array $counts = ['pools' => 0, 'addresses' => 0, 'given' => 0, 'without_service' => 0, 'conflicts' => [], 'outside' => []];

    public function __construct(private readonly PDO $whmcs) {}

    /** @return array{pools: int, addresses: int, given: int, without_service: int, conflicts: list<string>, outside: list<string>} */
    public function run(): array
    {
        if (!$this->hasTable('mod_pvewhmcs_ip_pools') || !$this->hasTable('mod_pvewhmcs_ip_addresses')) {
            return $this->counts;
        }
        $clusters = Server::query()->where('extension', 'Proxmox')->pluck('id');
        $addresses = $this->whmcs->query('SELECT pool_id, ipaddress, mask FROM mod_pvewhmcs_ip_addresses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($this->whmcs->query('SELECT id, title, gateway FROM mod_pvewhmcs_ip_pools ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows = array_values(array_filter($addresses, fn (array $a) => (int) $a['pool_id'] === (int) $row['id']));
            $pool = $this->pool($row, $rows, $clusters->count() === 1 ? (int) $clusters->first() : null);
            if ($pool === null) {
                continue;
            }
            $result = $pool->add(implode("\n", array_filter(array_map(fn (array $a) => trim((string) $a['ipaddress']), $rows),
                fn (string $ip) => $ip !== '' && $ip !== trim((string) $row['gateway']))));
            $this->counts['addresses'] += $result['added'];
        }
        if ($this->hasTable('mod_pvewhmcs_vms')) {
            $this->assignments();
        }

        return $this->counts;
    }

    /** @param list<array{pool_id: mixed, ipaddress: mixed, mask: mixed}> $rows */
    private function pool(array $row, array $rows, ?int $serverId): ?IpPool
    {
        if ($existing = IpPool::query()->where('whmcs_id', (int) $row['id'])->first()) {
            return $existing;
        }
        $gateway = trim((string) $row['gateway']);
        $sample = $rows[0] ?? null;
        try {
            $prefix = IpRange::prefix((string) ($sample['mask'] ?? ''));
            $network = IpRange::networkOf(trim((string) ($sample['ipaddress'] ?? $gateway)), $prefix);
        } catch (InvalidArgumentException) {
            $this->counts['conflicts'][] = "Pool «{$row['title']}»: no address with a mask to tell its network; add it by hand.";

            return null;
        }
        $this->counts['pools']++;

        return IpPool::query()->create(['whmcs_id' => (int) $row['id'], 'name' => (string) $row['title'], 'family' => 4, 'network' => $network,
            'prefix' => $prefix, 'gateway' => filter_var($gateway, FILTER_VALIDATE_IP) ? $gateway : null, 'server_id' => $serverId,
            'note' => 'From WHMCS (pvewhmcs).']);
    }

    /** Each VPS's address from mod_pvewhmcs_vms, to its service; never one given to another (told, for staff). */
    private function assignments(): void
    {
        foreach ($this->whmcs->query('SELECT id, ipaddress FROM mod_pvewhmcs_vms ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $vm) {
            $ip = trim((string) $vm['ipaddress']);
            if ($ip === '') {
                continue;
            }
            $address = IpAddress::query()->with('pool')->where('address', rescue(fn () => IpRange::normalize($ip), $ip, false))->first();
            $service = Service::query()->find((int) $vm['id']);
            if ($address === null) {
                $this->counts['outside'][] = "{$ip} (service #{$vm['id']})";

                continue;
            }
            if ($service === null) {
                $this->counts['without_service']++;

                continue;
            }
            if ($address->service_id === $service->id) {
                continue;
            }
            if ($address->service_id !== null || $address->reserved) {
                $this->counts['conflicts'][] = "{$ip}: WHMCS gives it to service #{$service->id}, here it is " . ($address->reserved ? 'reserved' : "service #{$address->service_id}'s") . '.';

                continue;
            }
            try {
                IpAllocator::assign($service, $address->pool, $address);
                $this->counts['given']++;
            } catch (DisplayException $e) {
                $this->counts['conflicts'][] = "{$ip}: {$e->getMessage()}";
            }
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            $this->whmcs->query("SELECT 1 FROM {$table} LIMIT 1");

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
