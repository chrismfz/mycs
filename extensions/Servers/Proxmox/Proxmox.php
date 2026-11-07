<?php

namespace MyCS\Extensions\Servers\Proxmox;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Exceptions\LeftToStaff;
use App\Models\Service;
use App\Services\Proxmox\GuestLifecycle;
use App\Support\PanelConnection;
use App\Support\Proxmox\PanelTransport;
use Illuminate\Support\HtmlString;
use MyCS\Proxmox\Console\ConsoleTicket;
use MyCS\Proxmox\ProxmoxClient;
use RuntimeException;
use Throwable;

/**
 * A Proxmox VE cluster (owner 2026-10-24), read-only for now: its nodes, their graphs and its guests, each mapped to a
 * service or marked internal (Proxmox guests). One server per cluster, with up to three of its nodes' addresses: any
 * node answers for the whole cluster, so one node down hides nothing. A second cluster or a standalone node is another
 * server. Reading needs PVEAuditor on «/»; the actions the MyCS role on its pool (docs/proxmox.md).
 */
#[ExtensionMeta(
    name: 'Proxmox VE',
    description: 'A Proxmox VE cluster: its nodes and guests, read with an API token',
    version: 'builtin',
    author: 'MyCS',
)]
class Proxmox extends Server
{
    /** The cluster's side, on the server's form (owner 2026-10-24): what to run on one node, and why. */
    private const SETUP_HELP = <<<'HTML'
        <details class="text-sm">
        <summary class="cursor-pointer font-medium">How to set it up on the cluster</summary>
        <div class="mt-2 space-y-3">
        <p>On any node, as root. The token is the same for the whole cluster.</p>
        <div><p class="font-medium">1. Read (lists, graphs): changes nothing</p>
        <pre class="text-xs p-2 rounded bg-gray-100 dark:bg-white/5 overflow-x-auto">pveum user add mycs@pve --comment "MyCS control plane"
        pveum user token add mycs@pve api --privsep 1
        pveum acl modify / --roles PVEAuditor --users mycs@pve --tokens 'mycs@pve!api'</pre>
        <p class="text-gray-500">The second command prints the token's secret once: paste it below, nowhere else. PVEAuditor only reads.</p></div>
        <div><p class="font-medium">2. Suspend, start, terminate: only the VMs in the pool</p>
        <pre class="text-xs p-2 rounded bg-gray-100 dark:bg-white/5 overflow-x-auto">pvesh create /pools --poolid mycs --comment "VMs managed by MyCS"
        pveum role add MyCSLifecycle -privs "VM.Audit VM.PowerMgmt VM.Config.Options VM.Allocate VM.Snapshot VM.Snapshot.Rollback"
        pveum acl modify /pool/mycs --roles MyCSLifecycle --users mycs@pve --tokens 'mycs@pve!api'
        pvesh set /pools --poolid mycs --vms 101,102</pre>
        <p class="text-gray-500">PowerMgmt stops and starts, Config.Options turns «start at boot» off while suspended, Allocate destroys
        (only with «Terminate: Destroy»), Snapshot and Snapshot.Rollback its one
        snapshot (on products that offer it; on a role made before: <code>pveum role modify
        MyCSLifecycle -privs "…"</code> with the whole list above). A customer's VM goes in the pool once mapped (Utilities → Proxmox Guests → «Pool
        command» lists them); our own VMs stay out, and MyCS never touches them.</p></div>
        <div><p class="font-medium">3. The customer's console: the relay's own token, never MyCS's</p>
        <pre class="text-xs p-2 rounded bg-gray-100 dark:bg-white/5 overflow-x-auto">pveum user add mycs-console@pve --comment "MyCS console relay"
        pveum user token add mycs-console@pve relay --privsep 1
        pveum role add MyCSConsole -privs "VM.Console"
        pveum acl modify /pool/mycs --roles MyCSConsole --users mycs-console@pve --tokens 'mycs-console@pve!relay'</pre>
        <p class="text-gray-500">Its secret goes in the relay's config, next to the cluster, not here: MyCS only signs the
        customer's ticket, and the relay opens the console (docs/proxmox.md, «Σχέδιο: το relay στο cluster»). MyCS's own role
        needs no VM.Console.</p></div>
        </div>
        </details>
        HTML;

    /** What a product offers the customer on its VPS, beyond power and console. */
    public function getProductConfig($values = []): array
    {
        return [
            ['name' => 'snapshots', 'type' => 'checkbox', 'label' => 'Customer snapshot', 'default' => false, 'database_type' => 'boolean', 'required' => false,
                'description' => 'One snapshot of the VM\'s disks (not its RAM) that the customer takes, rolls back to and deletes from the client area. '
                    . 'It grows with every change on the disk until deleted. Needs VM.Snapshot and VM.Snapshot.Rollback in the MyCS role (Setup → Servers).'],
        ];
    }

    public function getConfig($values = []): array
    {
        $ip = PanelConnection::fields()[0];
        $node = fn (int $n) => [
            ['name' => "node{$n}_host", 'type' => 'text', 'label' => "Node {$n} address", 'placeholder' => "https://pve{$n}.example.com:8006",
                'validation' => 'nullable|url:https', 'required' => false, 'description' => 'Tried when the nodes above do not answer.'],
            ['name' => "node{$n}_ip", 'label' => "Node {$n} IP"] + $ip,
        ];

        return [
            ['name' => 'setup_help', 'type' => 'placeholder', 'label' => new HtmlString(self::SETUP_HELP)],
            ['name' => 'host', 'type' => 'text', 'label' => 'Node address', 'placeholder' => 'https://pve1.example.com:8006',
                'validation' => 'url:https', 'required' => true, 'description' => 'Any node of the cluster: it answers for all of them.'],
            ...PanelConnection::fields(),
            ...$node(2),
            ...$node(3),
            ['name' => 'token_id', 'type' => 'text', 'label' => 'API token ID', 'placeholder' => 'mycs@pve!api', 'required' => true,
                'validation' => ['required', 'regex:/^[^@\s]+@[^!\s]+![^\s]+$/']],
            ['name' => 'token_secret', 'type' => 'password', 'label' => 'API token secret', 'required' => true, 'encrypted' => true],
            ['name' => 'pool', 'type' => 'text', 'label' => 'MyCS pool', 'default' => 'mycs', 'required' => false,
                'validation' => ['nullable', 'regex:/^[A-Za-z0-9_.-]+$/'],
                'description' => 'MyCS suspends, starts and destroys only VMs in this pool, each mapped to its service (Proxmox Guests).'],
            ['name' => 'console', 'type' => 'checkbox', 'label' => 'Customer console', 'default' => false, 'required' => false,
                'description' => 'The «Console» of the customer\'s VPS, through the console relay next to the cluster. Off: no button, no ticket, and the consoles open now close within 30 seconds.'],
            ['name' => 'console_url', 'type' => 'text', 'label' => 'Console relay URL', 'placeholder' => 'wss://console.example.com/ws', 'required' => false,
                'validation' => ['nullable', 'regex:~^wss://[^/\s?#]+/[^\s?#]*$~']],
            ['name' => 'console_key', 'type' => 'password', 'label' => 'Console relay key', 'required' => false, 'encrypted' => true,
                'validation' => ['nullable', 'string', 'min:' . ConsoleTicket::MIN_KEY],
                'description' => 'Random, the same as in the relay\'s config (`openssl rand -base64 48`): it signs the tickets and the relay\'s checks.'],
            ['name' => 'terminate', 'type' => 'select', 'label' => 'Terminate', 'default' => 'stop', 'required' => false,
                'options' => [['value' => 'stop', 'label' => 'Stop the VM and leave its destroy to staff'], ['value' => 'destroy', 'label' => 'Destroy the VM and its disks']],
                'description' => 'A failsafe while MyCS is new: a terminated service\'s VM is stopped, and staff destroy it by hand (a task in Pending Module Actions).'],
        ];
    }

    /** The cluster's client: its nodes' addresses in order, each to its own IP. */
    public function client(): ProxmoxClient
    {
        $ips = [];
        foreach ([['host', 'ip'], ['node2_host', 'node2_ip'], ['node3_host', 'node3_ip']] as [$host, $ip]) {
            $base = rtrim(trim((string) $this->config($host)), '/');
            if ($base !== '') {
                $ips[$base] = trim((string) $this->config($ip)) ?: null;
            }
        }

        return new ProxmoxClient(new PanelTransport($ips, (bool) $this->config('self_signed')), array_keys($ips),
            trim((string) $this->config('token_id')), trim((string) $this->config('token_secret')));
    }

    /**
     * The customer's console relay (docs/proxmox.md, «Σχέδιο: το relay στο cluster»): its URL and key, or null when
     * the console is off or not set up (no button, no ticket, every check «no»).
     *
     * @return array{url: string, key: string}|null
     */
    public function consoleRelay(): ?array
    {
        $url = trim((string) $this->config('console_url'));
        $key = $this->consoleKey();
        if (!$this->config('console') || preg_match('~^wss://[^/\s?#]+/[^\s?#]*$~', $url) !== 1 || $key === null) {
            return null;
        }

        return ['url' => $url, 'key' => $key];
    }

    /** The console key, on or off: the relay's checks are still answered (with «no») once the console is turned off. */
    public function consoleKey(): ?string
    {
        $key = (string) $this->config('console_key');

        return strlen($key) >= ConsoleTicket::MIN_KEY ? $key : null;
    }

    /**
     * Create = staff's, by hand (owner 2026-10-28, docs/proxmox.md: from an .iso, its MAC set on the router; PR 5 brings
     * the create from a plan): a task in Pending Module Actions and a mail, as an offline machine's, never a paid VPS
     * started with nobody told (sweep2 F-PROVISIONING-OPS-1). The service starts as for a done create.
     */
    public function createServer(Service $service, $settings, $properties)
    {
        throw new LeftToStaff('By hand: create the VM' . self::what($service) . ' (no create from a plan yet), add it to the pool and map it '
            . 'to this service in Utilities → Proxmox Guests, then mark this done.');
    }

    /**
     * An upgrade (a plan or an option the customer paid for: cores, RAM, disk) is staff's too, until PR 5: the VM is
     * changed by hand, never a paid change that reaches nobody (sweep2 F-PROVISIONING-OPS-1).
     */
    public function upgradeServer(Service $service, $settings, $properties)
    {
        throw new LeftToStaff('By hand: change the VM' . self::what($service) . ' to its new plan and options (see the service), then mark this done.');
    }

    /** The service's product, plan and name, for staff's task. */
    private static function what(Service $service): string
    {
        $parts = array_filter([
            $service->product?->name !== null ? "«{$service->product->name}»" : null,
            $service->plan?->name !== null ? "plan «{$service->plan->name}»" : null,
            filled($service->domain) ? (string) $service->domain : null,
        ]);

        return " of service #{$service->id}" . ($parts !== [] ? ' (' . implode(', ', $parts) . ')' : '');
    }

    /** Suspend = stop (owner 2026-10-24): a clean shutdown, then a hard stop; not started again by a node's reboot. */
    public function suspendServer(Service $service, $settings, $properties)
    {
        $this->lifecycle($service)->suspend($service);

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $this->lifecycle($service)->unsuspend($service);

        return true;
    }

    /**
     * Terminate = destroy with its disks (owner 2026-10-24: months unpaid by then; the backups are the way back), once the
     * server's «Terminate» says so; until then (the default) stopped, the destroy left to staff.
     */
    public function terminateServer(Service $service, $settings, $properties)
    {
        $this->lifecycle($service)->terminate($service, $this->config('terminate') === 'destroy');

        return true;
    }

    public function lifecycle(Service $service): GuestLifecycle
    {
        $server = $service->provisioningServer() ?? throw new RuntimeException("Service #{$service->id} has no server.");

        return new GuestLifecycle($this->client(), $server, trim((string) $this->config('pool')) ?: 'mycs');
    }

    public function testConfig(): bool|string
    {
        try {
            $client = $this->client();
            $client->version();
            // /version and /nodes answer any valid token; a token without its ACL sees no node (review of #446).
            if ($client->nodes() === []) {
                return 'The token works but sees no node: give it PVEAuditor on «/» (pveum acl modify / --roles PVEAuditor --users mycs@pve --tokens \'mycs@pve!api\').';
            }
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return true;
    }
}
