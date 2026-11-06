<?php

namespace MyCS\Proxmox\Console;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * The relay as it runs next to the cluster (docs/proxmox.md, «Σχέδιο: το relay στο cluster»), from one config file
 * (bin/console-relay): the sites it serves, their clusters, and for each cluster its key (as MyCS has it on that
 * cluster's server) and the relay's own console-only token. MyCS's token is never here, and MyCS never has this one.
 *
 * Config (a PHP file returning an array; secrets, so 0600 and never in a repository):
 *
 *     'listen' => '127.0.0.1:8091', 'path' => '/ws',
 *     'sites' => [[
 *         'origin' => 'https://my.example.com',          // the MyCS site, exactly as its pages' Origin
 *         'check_url' => 'https://my.example.com/api/proxmox/console/check', // optional: this by default
 *         'clusters' => [7 => [                             // MyCS's server id of the cluster
 *             'keys' => ['…'],                             // its server's «Console relay key»; the old one second while rotating
 *             'nodes' => [['base' => 'https://pve1.example.com:8006', 'ip' => '10.0.0.11']],
 *             'self_signed' => false,                      // a self-signed certificate is accepted only with each node's IP
 *             'token_id' => 'mycs-console@pve!relay', 'token_secret' => '…',  // VM.Console on the pool, nothing else
 *         ]],
 *     ]],
 */
final class StandaloneRelay
{
    /** A VNC proxy asked of one node, at most: two nodes and the websocket within the relay's HANDSHAKE (review of #675). */
    public const PROXY_TIMEOUT = 4;

    /**
     * @var array<string, array{origin: string, check_url: string, clusters: array<int, array{keys: list<string>,
     *      nodes: list<array{base: string, ip: string|null}>, self_signed: bool, authorization: string}>}>
     */
    private array $sites = [];

    /** @var array<string, int> the tickets used, until they expire: each opens one console */
    private array $used = [];

    private readonly int $started;

    /**
     * @param  array<string, mixed>  $config
     * @param  (Closure(): int)|null  $clock
     */
    public function __construct(array $config, private readonly AsyncHttp $http = new AsyncHttp, private readonly ?Closure $clock = null)
    {
        $keys = [];
        foreach ((array) ($config['sites'] ?? []) as $n => $site) {
            $this->addSite(is_array($site) ? $site : [], (string) $n, $keys);
        }
        if ($this->sites === []) {
            throw new InvalidArgumentException('The relay serves no site: set `sites` in its config.');
        }
        // A ticket issued before this process started is not taken: a restart forgets the used ones (they are in memory).
        $this->started = $this->now();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  Closure(string, array<string, mixed>): void|null  $log
     */
    public static function relay(array $config, ?Closure $log = null): Relay
    {
        $relay = new self($config);

        return $relay->build((string) ($config['listen'] ?? '127.0.0.1:8091'), (string) ($config['path'] ?? '/ws'), $log);
    }

    /** @param  Closure(string, array<string, mixed>): void|null  $log */
    public function build(string $listen, string $path, ?Closure $log = null): Relay
    {
        return new Relay($listen, array_keys($this->sites), $path, fn (string $token) => $this->ticket($token),
            fn (array $ticket, Closure $done, ?Closure $waiting = null) => $this->prepare($ticket, $done, $waiting),
            fn (array $ticket, array $proxy) => $this->connect($ticket, $proxy),
            fn (array $tickets, Closure $done) => $this->check($tickets, $done), fn () => $this->http->poll(), $log);
    }

    /**
     * A token's console: signed by the key of the cluster it names, of the site it names, not yet used, and not older
     * than this process.
     *
     * @return array<string, mixed>|null
     */
    public function ticket(string $token): ?array
    {
        $now = $this->now();
        $this->used = array_filter($this->used, fn (int $expires) => $expires + ConsoleTicket::SKEW >= $now);
        $claims = ConsoleTicket::peek($token);
        $cluster = $claims === null ? null : ($this->sites[$claims['origin']]['clusters'][$claims['server_id']] ?? null);
        $ticket = $cluster === null ? null : ConsoleTicket::verify($token, $cluster['keys'], $now);
        // Issued before the start, within the clocks' skew too: a ticket used just before a restart is not taken again
        // (review of #675: MyCS's clock a few seconds ahead).
        if ($ticket === null || $ticket['origin'] !== $claims['origin'] || $ticket['server_id'] !== $claims['server_id']
            || $ticket['iat'] < $this->started + ConsoleTicket::SKEW || isset($this->used[$ticket['jti']])) {
            return null;
        }
        $this->used[$ticket['jti']] = $ticket['exp'];

        return $ticket;
    }

    /**
     * The guest's VNC proxy, asked of its cluster's nodes in turn (the first that answers), with the relay's token. No
     * further node is asked once the console no longer waits (`waiting`): each answer starts a proxy on the cluster.
     *
     * @param  array<string, mixed>  $ticket
     * @param  Closure(array{port: int, ticket: string, base: string}|null, string|null): void  $done
     * @param  (Closure(): bool)|null  $waiting
     */
    public function prepare(array $ticket, Closure $done, ?Closure $waiting = null, int $from = 0, ?string $error = null): void
    {
        $cluster = $this->cluster($ticket);
        $node = $cluster['nodes'][$from] ?? null;
        if ($node === null || ($from > 0 && $waiting !== null && !$waiting())) {
            $done(null, $error ?? 'no node');

            return;
        }
        $path = '/api2/json/nodes/' . rawurlencode((string) $ticket['node']) . '/' . ($ticket['type'] === 'lxc' ? 'lxc' : 'qemu') . '/' . (int) $ticket['vmid'] . '/vncproxy';
        $this->http->request('POST', $node['base'] . $path, ['Authorization: ' . $cluster['authorization'], 'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded'], 'websocket=1', ['ip' => $node['ip'], 'self_signed' => $cluster['self_signed']],
            function (int $status, string $body, ?string $failed) use ($ticket, $done, $waiting, $from, $node) {
                $data = $status === 200 ? (json_decode($body, true)['data'] ?? null) : null;
                if (is_array($data) && is_numeric($data['port'] ?? null) && is_string($data['ticket'] ?? null) && $data['ticket'] !== '') {
                    $done(['port' => (int) $data['port'], 'ticket' => $data['ticket'], 'base' => $node['base']], null);

                    return;
                }
                // Another node may answer (this one down); a refusal (403) is the same everywhere, but costs little.
                $this->prepare($ticket, $done, $waiting, $from + 1, $failed ?? "{$node['base']} answered {$status}");
            }, self::PROXY_TIMEOUT);
    }

    /**
     * The node that made the proxy: its websocket must be opened there. Connection begun, TLS spoken by the relay.
     *
     * @param  array<string, mixed>  $ticket
     * @param  array{port: int, ticket: string, base: string}  $proxy
     * @return array{stream: resource, host: string, port: int, authorization: string, tls: bool}
     */
    public function connect(array $ticket, array $proxy): array
    {
        $cluster = $this->cluster($ticket);
        $node = current(array_filter($cluster['nodes'], fn (array $n) => $n['base'] === $proxy['base']));
        if ($node === false) {
            throw new RuntimeException('Not a node of this cluster.');
        }
        $parts = (array) parse_url($node['base']);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $port = (int) ($parts['port'] ?? 8006);
        $selfSigned = $cluster['self_signed'];
        $context = stream_context_create(['ssl' => [
            'peer_name' => $host, 'SNI_enabled' => true,
            'verify_peer' => !$selfSigned, 'verify_peer_name' => !$selfSigned, 'allow_self_signed' => $selfSigned,
        ]]);
        $target = $node['ip'] ?? $host;
        // Begun, not waited for: the TCP connect and the TLS handshake finish from the relay's loop (ADV-506-3).
        $stream = @stream_socket_client('tcp://' . (str_contains($target, ':') ? "[{$target}]" : $target) . ':' . $port, $code, $message, 5,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $context);
        if ($stream === false) {
            throw new RuntimeException("{$host}: {$message}");
        }

        return ['stream' => $stream, 'host' => $host, 'port' => $port, 'authorization' => $cluster['authorization'], 'tls' => true];
    }

    /**
     * Whether the consoles are still their customers': one call to each site for each of its clusters, signed with that
     * cluster's key (MyCS answers a key only for its own server's consoles). One not answered leaves its consoles
     * «unknown» (null).
     *
     * @param  array<int, array<string, mixed>>  $tickets
     * @param  Closure(array<int, bool|null>): void  $done
     */
    public function check(array $tickets, Closure $done): void
    {
        $groups = [];
        foreach ($tickets as $id => $ticket) {
            $groups[(string) $ticket['origin'] . '#' . (int) $ticket['server_id']][$id] = $ticket;
        }
        $answers = [];
        $waiting = count($groups);
        foreach ($groups as $consoles) {
            $first = current($consoles);
            $site = $this->sites[(string) $first['origin']];
            $key = $site['clusters'][(int) $first['server_id']]['keys'][0];
            $body = (string) json_encode(['ts' => $this->now(), 'consoles' => array_map(fn (int $id, array $t) => ['id' => $id,
                'jti' => $t['jti'], 'server_id' => $t['server_id'], 'service_id' => $t['service_id'], 'user_id' => $t['user_id'], 'vmid' => $t['vmid']],
                array_keys($consoles), $consoles)], JSON_UNESCAPED_SLASHES);
            $this->http->request('POST', $site['check_url'], ['Content-Type: application/json', 'Accept: application/json',
                'X-Console-Signature: ' . ConsoleTicket::checkSignature($body, $key)], $body, [],
                function (int $status, string $answer) use ($consoles, &$answers, &$waiting, $done) {
                    $allowed = $status === 200 ? (json_decode($answer, true)['allowed'] ?? null) : null;
                    foreach (array_keys($consoles) as $id) {
                        $answers[$id] = is_array($allowed) && is_bool($allowed[(string) $id] ?? null) ? $allowed[(string) $id] : null;
                    }
                    if (--$waiting === 0) {
                        $done($answers);
                    }
                });
        }
        if ($waiting === 0) {
            $done([]);
        }
    }

    /**
     * @param  array<string, mixed>  $ticket
     * @return array{keys: list<string>, nodes: list<array{base: string, ip: string|null}>, self_signed: bool, authorization: string}
     */
    private function cluster(array $ticket): array
    {
        return $this->sites[(string) $ticket['origin']]['clusters'][(int) $ticket['server_id']]
            ?? throw new RuntimeException('Not a cluster of this site.');
    }

    /**
     * @param  array<string, mixed>  $site
     * @param  array<string, string>  $keys  every key seen, and where: one key is one cluster's (review of #675)
     */
    private function addSite(array $site, string $n, array &$keys): void
    {
        $origin = (string) ($site['origin'] ?? '');
        if (preg_match('#^https://[A-Za-z0-9.-]+(:\d+)?$#', $origin) !== 1) {
            throw new InvalidArgumentException("Site {$n}: `origin` is the MyCS site's scheme and host, as «https://my.example.com».");
        }
        if (isset($this->sites[$origin])) {
            throw new InvalidArgumentException("Site {$origin} twice: one entry each.");
        }
        $clusters = [];
        foreach ((array) ($site['clusters'] ?? []) as $id => $cluster) {
            $cluster = (array) $cluster;
            $where = "Site {$origin}, cluster {$id}";
            $given = (array) ($cluster['keys'] ?? []);
            $clusterKeys = array_values(array_filter($given, fn ($k) => is_string($k) && strlen($k) >= ConsoleTicket::MIN_KEY));
            if ($clusterKeys === [] || count($clusterKeys) !== count($given)) {
                throw new InvalidArgumentException("{$where}: each of `keys` has at least " . ConsoleTicket::MIN_KEY . ' characters.');
            }
            foreach ($clusterKeys as $key) {
                if (isset($keys[$key])) {
                    throw new InvalidArgumentException("{$where}: a key of {$keys[$key]} too. Each cluster has its own.");
                }
                $keys[$key] = $where;
            }
            $selfSigned = (bool) ($cluster['self_signed'] ?? false);
            $nodes = [];
            foreach ((array) ($cluster['nodes'] ?? []) as $node) {
                $base = rtrim((string) ($node['base'] ?? ''), '/');
                if (preg_match('#^https://[^/\s]+$#', $base) !== 1) {
                    throw new InvalidArgumentException("{$where}: a node's `base` is «https://host:8006».");
                }
                $host = trim((string) parse_url($base, PHP_URL_HOST), '[]');
                // A node named by its IP is reached there: its IP for TLS and the requests alike (review of #675).
                $ip = is_string($node['ip'] ?? null) && $node['ip'] !== '' ? $node['ip'] : (filter_var($host, FILTER_VALIDATE_IP) ? $host : null);
                if ($selfSigned && $ip === null) {
                    throw new InvalidArgumentException("{$where}: a self-signed certificate needs each node's `ip`.");
                }
                $nodes[] = ['base' => $base, 'ip' => $ip];
            }
            $tokenId = (string) ($cluster['token_id'] ?? '');
            $secret = (string) ($cluster['token_secret'] ?? '');
            if ($nodes === [] || preg_match('/^[^@\s]+@[^!\s]+![^\s=]+$/', $tokenId) !== 1 || $secret === '' || preg_match('/\s/', $secret) === 1) {
                throw new InvalidArgumentException("{$where}: `nodes`, `token_id` and `token_secret` are needed.");
            }
            $clusters[(int) $id] = ['keys' => $clusterKeys, 'nodes' => $nodes, 'self_signed' => $selfSigned, 'authorization' => "PVEAPIToken={$tokenId}={$secret}"];
        }
        if ($clusters === []) {
            throw new InvalidArgumentException("Site {$origin}: no `clusters`.");
        }
        // Its answer is not signed: only TLS keeps another from answering «yes» (review of #675).
        $check = (string) ($site['check_url'] ?? $origin . '/api/proxmox/console/check');
        if (preg_match('#^https://[^/\s]+/\S*$#', $check) !== 1) {
            throw new InvalidArgumentException("Site {$origin}: `check_url` is an https:// URL.");
        }
        $this->sites[$origin] = ['origin' => $origin, 'check_url' => $check, 'clusters' => $clusters];
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
