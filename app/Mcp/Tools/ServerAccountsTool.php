<?php

namespace App\Mcp\Tools;

use App\Models\CpanelAccount;
use App\Models\MailcowDomain;
use App\Models\ProxmoxGuest;
use App\Models\Server;
use App\Services\Ops\NotFound;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * A server's accounts as its read-only sync last read them (owner 2026-11-14: «a slow server, who uses it»): cPanel's
 * accounts (Hosting Accounts) with CloudLinux's LVE peaks, Proxmox's guests, mailcow's domains; a Redfish machine's BMC.
 * Filters for what staff look for (differs from MyCS, nobody's, near a limit, suspended, mail held) and an order by use.
 * As the admin's pages, to staff who may see the servers. Nothing asks the server from here.
 */
#[Name('server_accounts')]
#[Description('A server\'s accounts as its read-only sync last read them, each with when. Without `server`: the servers (module, enabled, live services, last read). cPanel: accounts (user, domain, owner, reseller, plan, disk, inodes, bandwidth, suspended, mail held/suspended, backup, LVE peaks of 24 h, service), `sort` by disk, inodes, bandwidth, cpu, memory, entry_processes, processes, io or iops (highest first). Proxmox: guests (status, node, CPUs, memory, disk, uptime, IPs, service). mailcow: domains (mailboxes, quota, used, DNS issues, service). Redfish: the machine\'s BMC. `show`: all (default), differs (state differs from MyCS; cPanel), not_mapped (no service, not internal), near_limits, suspended, mail_held (cPanel), gone (no longer on the server). `query` matches a user, name or domain. Read only.')]
#[IsReadOnly]
#[IsIdempotent]
class ServerAccountsTool extends McpTool
{
    public const SHOW = ['all', 'differs', 'not_mapped', 'near_limits', 'suspended', 'mail_held', 'gone'];

    /** Orders of cPanel's accounts: a column, or an LVE peak (withLvePeaks). */
    public const SORT = ['disk' => 'disk_used_mb', 'inodes' => 'inodes_used', 'bandwidth' => 'bandwidth_used_mb'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'server' => $schema->string()->description('Server id or exact name (none: the list of servers).'),
            'show' => $schema->string()->description('all (default), differs, not_mapped, near_limits, suspended, mail_held or gone.'),
            'sort' => $schema->string()->description('cPanel: disk, inodes, bandwidth, cpu, memory, entry_processes, processes, io, iops (highest first).'),
            'query' => $schema->string()->description('A user, name or domain holding this text.'),
            'limit' => $schema->integer()->description('Rows (default 50, at most 200).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.servers.viewAny';
    }

    protected function run(Request $request): array
    {
        $key = trim((string) $request->get('server'));
        if ($key === '') {
            return ['servers' => Server::query()->orderBy('name')->get()->map(fn (Server $server) => [
                'id' => $server->id, 'name' => $server->name, 'module' => $server->extension, 'enabled' => (bool) $server->enabled,
                'live_services' => $server->accountsCount(),
            ])->all()];
        }
        $server = (ctype_digit($key) ? Server::query()->find((int) $key) : null) ?? Server::query()->where('name', $key)->first()
            ?? throw new NotFound("No server {$key}.");
        $show = trim((string) ($request->get('show') ?? '')) ?: 'all';
        if (!in_array($show, self::SHOW, true)) {
            throw new \InvalidArgumentException('`show` is one of: ' . implode(', ', self::SHOW) . '.');
        }
        $limit = self::limit($request, 50, 200);
        $query = trim((string) $request->get('query'));
        $head = ['server' => ['id' => $server->id, 'name' => $server->name, 'module' => $server->extension], 'show' => $show];

        return $head + match ($server->extension) {
            'CPanel' => self::cpanel($server, $show, (string) $request->get('sort'), $query, $limit),
            'Proxmox' => self::proxmox($server, $show, $query, $limit),
            'Mailcow' => self::mailcow($server, $show, $query, $limit),
            'Redfish' => ['machine' => ServiceModuleTool::machine($server->id)],
            default => ['note' => "The {$server->extension} module keeps no read of its accounts here; `service_module` shows a service's usage."],
        };
    }

    /** @return array<string, mixed> */
    private static function cpanel(Server $server, string $show, string $sort, string $query, int $limit): array
    {
        $sort = trim($sort);
        if ($sort !== '' && !isset(self::SORT[$sort]) && !isset(CpanelAccount::LVE[$sort])) {
            throw new \InvalidArgumentException('`sort` is one of: ' . implode(', ', [...array_keys(self::SORT), ...array_keys(CpanelAccount::LVE)]) . '.');
        }
        $accounts = CpanelAccount::query()->where('server_id', $server->id)->with(['service', 'server.settings']);
        CpanelAccount::withLvePeaks($accounts);
        self::shown($accounts, $show, 'cpanel_accounts');
        match ($show) {
            'differs' => CpanelAccount::whereDiffers($accounts),
            'near_limits' => $accounts->where(fn (Builder $q) => $q
                ->whereRaw('disk_limit_mb > 0 AND disk_used_mb >= ? * disk_limit_mb', [CpanelAccount::NEAR])
                ->orWhereRaw('inodes_limit > 0 AND inodes_used >= ? * inodes_limit', [CpanelAccount::NEAR])
                ->orWhereRaw('bandwidth_limit_mb > 0 AND bandwidth_used_mb >= ? * bandwidth_limit_mb', [CpanelAccount::NEAR])),
            'mail_held' => $accounts->where(fn (Builder $q) => $q->where('mail_held', true)->orWhere('mail_suspended', true)),
            default => null,
        };
        self::matching($accounts, ['user', 'domain'], $query);
        if ($sort === '') {
            $accounts->orderBy('user');
        } else {
            // Highest first; an unknown (null) sorts last on MariaDB and SQLite alike.
            $accounts->orderByDesc(self::SORT[$sort] ?? "lve_{$sort}_peak")->orderBy('user');
        }
        $total = (clone $accounts)->count();
        $rows = $accounts->limit($limit)->get();

        return [
            'read_at' => CpanelAccount::query()->where('server_id', $server->id)->max('last_seen_at'),
            'lve_read_at' => CpanelAccount::query()->where('server_id', $server->id)->max('lve_read_at'),
            'total' => $total,
            'accounts' => $rows->map(fn (CpanelAccount $a) => [
                'user' => $a->user, 'domain' => $a->domain, 'owner' => $a->owner, 'reseller' => $a->reseller, 'plan' => $a->plan, 'ip' => $a->ip,
                'disk_mb' => [$a->disk_used_mb, $a->disk_limit_mb], 'inodes' => [$a->inodes_used, $a->inodes_limit],
                'bandwidth_mb' => [$a->bandwidth_used_mb, $a->bandwidth_limit_mb],
                'suspended' => $a->suspended, 'mail_held' => $a->mail_held, 'mail_suspended' => $a->mail_suspended, 'backup' => $a->backup,
                'lve_peak_24h' => $a->suspended || $a->lve_read_at === null ? null
                    : array_map(fn (string $k) => $a->getAttribute("lve_{$k}_peak") === null ? null : (int) $a->getAttribute("lve_{$k}_peak"), array_combine(array_keys(CpanelAccount::LVE), array_keys(CpanelAccount::LVE))),
                'service_id' => $a->service_id, 'service_status' => $a->service?->status, 'internal' => $a->internal,
                'resellers_customer' => $a->resellersCustomer(), 'gone_at' => $a->gone_at?->toDateTimeString(),
            ])->all(),
            'columns' => 'disk_mb, inodes, bandwidth_mb are [used, limit]; lve_peak_24h: cpu in %, memory in MB, io in KB/s',
        ];
    }

    /** @return array<string, mixed> */
    private static function proxmox(Server $server, string $show, string $query, int $limit): array
    {
        $guests = ProxmoxGuest::query()->where('server_id', $server->id)->with('service');
        self::shown($guests, $show, 'proxmox_guests');
        if ($show === 'suspended') {
            $guests->where('status', '!=', 'running');
        }
        self::matching($guests, ['name'], $query);
        $total = (clone $guests)->count();

        return [
            'read_at' => ProxmoxGuest::query()->where('server_id', $server->id)->max('last_seen_at'),
            'total' => $total,
            'guests' => $guests->orderBy('node')->orderBy('vmid')->limit($limit)->get()->map(fn (ProxmoxGuest $g) => [
                'vmid' => $g->vmid, 'type' => $g->type, 'node' => $g->node, 'pool' => $g->pool, 'name' => $g->name, 'status' => $g->status,
                'template' => $g->template, 'cpus' => $g->cpus,
                'memory_mb' => intdiv((int) $g->max_memory, 1048576),
                'disk_gb' => round((int) $g->max_disk / 1073741824, 1),
                'uptime_hours' => round((int) $g->uptime / 3600, 1), 'ips' => $g->ips,
                'service_id' => $g->service_id, 'service_status' => $g->service?->status, 'internal' => $g->internal, 'gone_at' => $g->gone_at?->toDateTimeString(),
            ])->all(),
            'note' => $show === 'suspended' ? 'Proxmox: every guest not running.' : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function mailcow(Server $server, string $show, string $query, int $limit): array
    {
        $domains = MailcowDomain::query()->where('server_id', $server->id)->with('service');
        self::shown($domains, $show, 'mailcow_domains');
        match ($show) {
            'near_limits' => $domains->whereRaw('quota_bytes > 0 AND used_bytes >= ? * quota_bytes', [CpanelAccount::NEAR]),
            'suspended' => $domains->where('active', false),
            default => null,
        };
        self::matching($domains, ['domain'], $query);
        $total = (clone $domains)->count();

        return [
            'read_at' => MailcowDomain::query()->where('server_id', $server->id)->max('last_seen_at'),
            'total' => $total,
            'domains' => $domains->orderBy('domain')->limit($limit)->get()->map(fn (MailcowDomain $d) => [
                'domain' => $d->domain, 'active' => $d->active, 'mailboxes' => [$d->mailboxes, $d->mailbox_limit], 'aliases' => [$d->aliases, $d->alias_limit],
                'quota_mb' => intdiv((int) $d->quota_bytes, 1048576),
                'used_mb' => intdiv((int) $d->used_bytes, 1048576),
                'dns_status' => $d->dns_status, 'dns_issues' => $d->dns_issues,
                'service_id' => $d->service_id, 'service_status' => $d->service?->status, 'internal' => $d->internal, 'gone_at' => $d->gone_at?->toDateTimeString(),
            ])->all(),
        ];
    }

    /**
     * The rows still on the server (or, for `gone`, the ones no longer there); `not_mapped`: on no service and not
     * marked internal; `suspended` (cPanel) suspended there.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private static function shown(Builder $query, string $show, string $table): void
    {
        $show === 'gone' ? $query->whereNotNull("{$table}.gone_at") : $query->whereNull("{$table}.gone_at");
        if ($show === 'not_mapped') {
            $query->whereNull("{$table}.service_id")->where("{$table}.internal", false);
        }
        if ($show === 'suspended' && $table === 'cpanel_accounts') {
            $query->where('cpanel_accounts.suspended', true);
        }
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  list<string>  $columns  ours, never the caller's
     */
    private static function matching(Builder $query, array $columns, string $text): void
    {
        if ($text === '') {
            return;
        }
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . '%';
        $query->where(function (Builder $q) use ($columns, $like) {
            foreach ($columns as $column) {
                $q->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$like]);
            }
        });
    }
}
