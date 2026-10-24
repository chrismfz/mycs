<?php

namespace MyCS\Proxmox;

/**
 * A Proxmox VE cluster read with an API token. Any node answers for the whole cluster: its addresses are tried in turn,
 * so one node down hides nothing. A refused token is never tried elsewhere (it is the same token everywhere).
 */
final class ProxmoxClient
{
    /** @var list<string> */
    private array $bases;

    /** @param  list<string>  $bases  the nodes' addresses, «https://host:8006», the preferred first */
    public function __construct(
        private readonly Transport $http,
        array $bases,
        private readonly string $tokenId,
        private readonly string $secret,
    ) {
        $this->bases = array_values(array_filter(array_map(fn ($b) => rtrim(trim((string) $b), '/'), $bases), fn ($b) => $b !== ''));
        if ($this->bases === []) {
            throw new ProxmoxException('No node address: set at least one.');
        }
        if (preg_match('/^[^@\s]+@[^!\s]+![^\s]+$/', $tokenId) !== 1) {
            throw new ProxmoxException('The token ID must look like «user@realm!name».');
        }
    }

    /**
     * GET an API path, its «data».
     *
     * @return mixed
     */
    public function get(string $path)
    {
        $headers = ['Authorization' => "PVEAPIToken={$this->tokenId}={$this->secret}", 'Accept' => 'application/json'];
        $errors = [];
        foreach ($this->bases as $i => $base) {
            [$status, $body] = $this->http->send('GET', $base, '/api2/json/' . ltrim($path, '/'), $headers);
            if ($status === 401 || $status === 403) {
                throw new ProxmoxException("The token was refused ({$status}): check its ID, secret and permissions.");
            }
            if ($status >= 200 && $status < 300) {
                $decoded = json_decode($body, true);
                if (!is_array($decoded) || !array_key_exists('data', $decoded)) {
                    throw new ProxmoxException("{$base}: not a Proxmox API answer.");
                }
                if ($i > 0) {
                    // This node first from now on: a node down costs its timeout once, not on every call.
                    array_splice($this->bases, $i, 1);
                    array_unshift($this->bases, $base);
                }

                return $decoded['data'];
            }
            if ($status !== 0 && !in_array($status, [502, 503, 504], true)) {
                // An answer of the cluster about this path (e.g. a 500 «QEMU guest agent is not running»): another
                // node would say the same.
                throw new ProxmoxException("{$base}: HTTP {$status} for {$path}.");
            }
            // Not reached, or its proxy not serving (restarting, a gateway in front of a node down): the next node.
            $errors[] = $status === 0 ? "{$base}: not reached" : "{$base}: HTTP {$status}";
        }

        throw new ProxmoxException('No node answered: ' . implode('; ', $errors) . '.');
    }

    /** @return array<string, mixed> its version, as a connection test */
    public function version(): array
    {
        return (array) $this->get('version');
    }

    /** @return list<array<string, mixed>> the cluster's nodes: node, status, cpu, maxcpu, mem, maxmem, uptime… */
    public function nodes(): array
    {
        return array_values(array_filter((array) $this->get('nodes'), 'is_array'));
    }

    /** @return list<array<string, mixed>> a node's graph points (rrddata) for hour, day, week, month or year */
    public function nodeRrd(string $node, string $timeframe = 'hour'): array
    {
        return array_values(array_filter((array) $this->get('nodes/' . rawurlencode($node) . '/rrddata?timeframe=' . rawurlencode($timeframe) . '&cf=AVERAGE'), 'is_array'));
    }

    /**
     * Every VM and container of the cluster, with its MACs (its config) and addresses (its guest agent, or a
     * container's static ones). A guest whose details cannot be read is listed all the same, without them.
     *
     * @return list<Guest>
     */
    public function guests(): array
    {
        $guests = [];
        foreach ((array) $this->get('cluster/resources?type=vm') as $resource) {
            if (!is_array($resource) || !isset($resource['vmid'], $resource['node'])) {
                continue;
            }
            $type = (string) ($resource['type'] ?? 'qemu');
            $base = 'nodes/' . rawurlencode((string) $resource['node']) . '/' . ($type === 'lxc' ? 'lxc' : 'qemu') . '/' . (int) $resource['vmid'];
            $macs = $ips = [];
            try {
                $config = (array) $this->get("{$base}/config");
                $macs = Guest::macsOf($config);
                if ($type === 'lxc') {
                    $ips = Guest::staticIpsOf($config);
                }
            } catch (ProxmoxException) {
                // listed without them
            }
            if ($type !== 'lxc' && ($resource['status'] ?? '') === 'running') {
                try {
                    $agent = $this->get("{$base}/agent/network-get-interfaces");
                    $ips = Guest::agentIpsOf((array) (is_array($agent) && isset($agent['result']) ? $agent['result'] : $agent));
                } catch (ProxmoxException) {
                    // no agent: no addresses from it
                }
            }
            $guests[] = Guest::fromResource($resource, $macs, $ips);
        }

        return $guests;
    }
}
