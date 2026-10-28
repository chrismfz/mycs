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
        return $this->call('GET', $path);
    }

    /**
     * A call that changes something: its «data» (a task's UPID for most). On a node that cannot be reached it goes to
     * the next one like a read; every change made here is safe to ask twice (a stop of a stopped guest, a destroy of
     * one gone fails and is read as done by the caller).
     *
     * @param  array<string, scalar>  $form
     * @return mixed
     */
    public function write(string $method, string $path, array $form = [])
    {
        return $this->call($method, $path, $form);
    }

    /**
     * @param  array<string, scalar>  $form
     * @return mixed
     */
    private function call(string $method, string $path, array $form = [])
    {
        $headers = ['Authorization' => "PVEAPIToken={$this->tokenId}={$this->secret}", 'Accept' => 'application/json'];
        $errors = [];
        foreach ($this->bases as $i => $base) {
            [$status, $body] = $this->http->send($method, $base, '/api2/json/' . ltrim($path, '/'), $headers, $form);
            if ($status === 401) {
                throw new ProxmoxException('The token was refused (401): check its ID and secret.', 401);
            }
            if ($status === 403) {
                // The token is fine, this path is not its: a guest outside its pool, a privilege it lacks.
                throw new ProxmoxException("Permission denied for {$path} (403): check the token's role and pool.", 403);
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
                $message = json_decode($body, true);
                $reason = is_array($message) ? (string) ($message['message'] ?? '') : '';
                throw new ProxmoxException("{$base}: HTTP {$status} for {$path}" . ($reason !== '' ? ': ' . trim($reason) : '.'), $status);
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

    /** One of a guest's timeframes for its graphs (rrddata). */
    public const TIMEFRAMES = ['hour', 'day', 'week'];

    /**
     * A guest's graph points (rrddata: cpu, mem/maxmem, netin/netout, diskread/diskwrite per second) for an hour, a day
     * or a week, at its node now.
     *
     * @param  array<string, mixed>  $resource  as the cluster lists it (its node, type, vmid)
     * @return list<array<string, mixed>>
     */
    public function guestRrd(array $resource, string $timeframe = 'hour'): array
    {
        if (!in_array($timeframe, self::TIMEFRAMES, true)) {
            throw new ProxmoxException("Not a timeframe: {$timeframe}");
        }

        return array_values(array_filter((array) $this->get(self::guestPath($resource) . '/rrddata?timeframe=' . $timeframe . '&cf=AVERAGE'), 'is_array'));
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

    /**
     * One guest as the cluster lists it now (its node may have changed since the last read), or null when it is not
     * there any more.
     *
     * @return array<string, mixed>|null
     */
    public function resource(int $vmid): ?array
    {
        foreach ((array) $this->get('cluster/resources?type=vm') as $resource) {
            if (is_array($resource) && (int) ($resource['vmid'] ?? 0) === $vmid) {
                return $resource;
            }
        }

        return null;
    }

    /** «nodes/{node}/{qemu|lxc}/{vmid}», a guest's API path. */
    public static function guestPath(array $resource): string
    {
        return 'nodes/' . rawurlencode((string) $resource['node']) . '/' . (($resource['type'] ?? 'qemu') === 'lxc' ? 'lxc' : 'qemu') . '/' . (int) $resource['vmid'];
    }

    /**
     * Waits for a task (its UPID) to end, polling every $every seconds up to $timeout: true when it ended OK, false when
     * it still runs; a task that failed throws with its exit status.
     */
    public function waitTask(string $upid, int $timeout = 90, int $every = 2, ?callable $sleep = null): bool
    {
        $sleep ??= 'sleep';
        if (preg_match('/^UPID:([^:]+):/', $upid, $m) !== 1) {
            throw new ProxmoxException("Not a task id: {$upid}");
        }
        $path = 'nodes/' . rawurlencode($m[1]) . '/tasks/' . rawurlencode($upid) . '/status';
        for ($waited = 0; ; $waited += $every) {
            $task = (array) $this->get($path);
            if (($task['status'] ?? '') === 'stopped') {
                $exit = (string) ($task['exitstatus'] ?? '');
                if ($exit !== 'OK' && !str_starts_with($exit, 'WARNINGS')) {
                    throw new ProxmoxException("The task failed: {$exit}");
                }

                return true;
            }
            if ($waited >= $timeout) {
                return false;
            }
            $sleep($every);
        }
    }
}
