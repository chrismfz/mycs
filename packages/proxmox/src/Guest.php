<?php

namespace MyCS\Proxmox;

/** A VM (qemu) or container (lxc) of the cluster, as read: what it is, where it runs, how it is reached. */
final class Guest
{
    /**
     * @param  list<string>  $macs  upper case, colons
     * @param  list<string>  $ips  what its guest agent (or its container config) says, without loopback or link-local
     * @param  list<string>  $tags
     */
    public function __construct(
        public readonly int $vmid,
        public readonly string $type,
        public readonly string $node,
        public readonly string $name,
        public readonly string $status,
        public readonly bool $template,
        public readonly int $cpus,
        public readonly int $maxMemory,
        public readonly int $memory,
        public readonly float $cpu,
        public readonly int $maxDisk,
        public readonly int $uptime,
        public readonly array $macs = [],
        public readonly array $ips = [],
        public readonly array $tags = [],
    ) {}

    /** @param  array<string, mixed>  $resource  one row of /cluster/resources?type=vm */
    public static function fromResource(array $resource, array $macs = [], array $ips = []): self
    {
        $tags = array_values(array_filter(array_map('trim', preg_split('/[;,]/', (string) ($resource['tags'] ?? '')) ?: [])));

        return new self(
            (int) ($resource['vmid'] ?? 0),
            (string) ($resource['type'] ?? 'qemu'),
            (string) ($resource['node'] ?? ''),
            (string) ($resource['name'] ?? ''),
            (string) ($resource['status'] ?? 'unknown'),
            (bool) ($resource['template'] ?? false),
            (int) ($resource['maxcpu'] ?? 0),
            (int) ($resource['maxmem'] ?? 0),
            (int) ($resource['mem'] ?? 0),
            (float) ($resource['cpu'] ?? 0),
            (int) ($resource['maxdisk'] ?? 0),
            (int) ($resource['uptime'] ?? 0),
            $macs,
            $ips,
            $tags,
        );
    }

    /**
     * The MACs of a guest's network cards, from its config: qemu «net0: virtio=BC:24:11:..,bridge=vmbr0», lxc
     * «net0: name=eth0,bridge=vmbr0,hwaddr=BC:24:11:..,ip=dhcp».
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function macsOf(array $config): array
    {
        $macs = [];
        foreach ($config as $key => $value) {
            if (preg_match('/^net\d+$/', (string) $key) !== 1) {
                continue;
            }
            if (preg_match('/(?:^|,)(?:[a-z0-9]+|hwaddr)=([0-9A-Fa-f]{2}(?::[0-9A-Fa-f]{2}){5})(?=,|$)/', (string) $value, $m) === 1) {
                $macs[] = strtoupper($m[1]);
            }
        }

        return array_values(array_unique($macs));
    }

    /**
     * A container's static addresses from its config («ip=203.0.113.5/24», «ip6=2001:db8::5/64»).
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function staticIpsOf(array $config): array
    {
        $ips = [];
        foreach ($config as $key => $value) {
            if (preg_match('/^net\d+$/', (string) $key) !== 1) {
                continue;
            }
            if (preg_match_all('/(?:^|,)ip6?=([0-9a-fA-F:.]+)(?:\/\d+)?(?=,|$)/', (string) $value, $m) > 0) {
                foreach ($m[1] as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * The addresses the qemu guest agent reports (network-get-interfaces), without loopback and link-local.
     *
     * @param  array<int, mixed>  $interfaces
     * @return list<string>
     */
    public static function agentIpsOf(array $interfaces): array
    {
        $ips = [];
        foreach ($interfaces as $interface) {
            if (!is_array($interface) || ($interface['name'] ?? '') === 'lo') {
                continue;
            }
            foreach ((array) ($interface['ip-addresses'] ?? []) as $address) {
                $ip = is_array($address) ? (string) ($address['ip-address'] ?? '') : '';
                if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                    continue;
                }
                if (str_starts_with(strtolower($ip), 'fe80:') || str_starts_with($ip, '127.') || $ip === '::1' || str_starts_with($ip, '169.254.')) {
                    continue;
                }
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}
