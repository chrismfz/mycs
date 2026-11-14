<?php

namespace App\Mcp\Tools;

use App\Models\CpanelAccount;
use App\Models\MailcowDomain;
use App\Models\MailcowMailbox;
use App\Models\ProxmoxGuest;
use App\Models\RedfishMachine;
use App\Models\Service;
use App\Models\ServiceHostedDomain;
use App\Models\Staff;
use App\Services\Ops\Lookup;
use App\Services\Ops\Scrub;
use App\Support\ServiceIdentifier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * What the modules last read of one service (owner 2026-11-14: «what each module offers»): its usage, the domains its
 * account hosts, its cPanel account (reseller and its accounts, inodes, mail, CloudLinux's LVE), its Proxmox guest, its
 * mailcow domains and mailboxes, its SSL order, its machine's BMC. From the tables the read-only syncs fill (cpanel:sync,
 * services:usage, LVE, Proxmox, mailcow, Redfish, SSL), each with when it was read: nothing asks a server from here.
 * The server's own records (accounts, guests, mail domains, the BMC) only to staff who may see the servers.
 */
#[Name('service_module')]
#[Description('What the modules last read of one service, each part with when it was read (the read-only syncs: cPanel every 30 min, LVE hourly, usage every 4 h, Proxmox and mailcow every 15 min, Redfish hourly; nothing asks the server now): usage (disk, bandwidth, a reseller\'s accounts, the server\'s IP), hosted domains (main, addon, parked, sub), its cPanel account (owner, reseller and the accounts it owns, plan, disk, inodes, bandwidth against limits, suspended and why, mail held or suspended, emails per hour, backup, CloudLinux LVE peaks of 24 h and now against limits, what differs from MyCS), Proxmox guest (status, node, CPUs, memory, disk, uptime, IPs), mailcow domains and mailboxes (quota, used, last login, DNS issues), SSL order (status, dates, validation, sync error), the BMC of its machine (power, health, watts, attention). By id, license key or domain. Server records only if your role may see the servers. Read only.')]
#[IsReadOnly]
#[IsIdempotent]
class ServiceModuleTool extends McpTool
{
    /** A reseller's accounts listed. */
    public const RESOLD = 100;

    public function schema(JsonSchema $schema): array
    {
        return ['service' => $schema->string()->description('Service id, license key, or domain.')->required()];
    }

    protected function permission(): string
    {
        return 'admin.services.viewAny';
    }

    protected function run(Request $request): array
    {
        $service = Lookup::service((string) $request->get('service'))->load([...ServiceIdentifier::RELATIONS, 'usage', 'hostedDomains', 'sslOrder', 'ipAddresses']);
        $staff = $request->user();
        $servers = $staff instanceof Staff && $staff->hasPermission('admin.servers.viewAny');
        $server = $service->provisioningServer();
        $usage = $service->usage;

        return [
            'service' => '#' . $service->id . ' ' . ServiceIdentifier::named($service, trim((string) ($service->product->name ?? ''))),
            'status' => $service->status,
            'server' => $server ? ['id' => $server->id, 'name' => $server->name, 'module' => $server->extension] : null,
            'ips' => $service->ipAddresses->pluck('address')->all(),
            'usage' => $usage === null ? null : [
                'read_at' => $usage->read_at->toDateTimeString(), 'disk_used_mb' => $usage->disk_used_mb, 'disk_limit_mb' => $usage->disk_limit_mb,
                'bandwidth_used_mb' => $usage->bandwidth_used_mb, 'bandwidth_limit_mb' => $usage->bandwidth_limit_mb,
                'accounts' => $usage->accounts, 'accounts_limit' => $usage->accounts_limit, 'server_ip' => $usage->server_ip,
            ],
            'hosted_domains' => $service->hostedDomains->isEmpty() ? null : [
                'read_at' => (string) $service->hostedDomains->max('read_at'),
                'domains' => $service->hostedDomains->map(fn (ServiceHostedDomain $d) => ['domain' => $d->domain, 'type' => $d->type, 'account' => $d->account])->all(),
            ],
            'ssl' => $service->sslOrder === null ? null : self::ssl($service),
            'cpanel_account' => $servers ? self::cpanel($service) : null,
            'proxmox_guest' => $servers ? self::guest($service) : null,
            'mailcow_domains' => $servers ? self::mailcow($service) : null,
            'machine' => $servers && $server !== null ? self::machine($server->id) : null,
            'server_records' => $servers ? 'shown' : 'hidden: your role may not see the servers',
        ];
    }

    /** @return array<string, mixed>|null */
    private static function cpanel(Service $service): ?array
    {
        $account = $service->cpanelAccount()->first();
        if (!$account instanceof CpanelAccount) {
            return null;
        }
        $resold = CpanelAccount::query()->where('server_id', $account->server_id)->where('owner', $account->user)->where('user', '!=', $account->user)
            ->whereNull('gone_at')->orderBy('user')->limit(self::RESOLD)->get();

        return [
            'read_at' => $account->last_seen_at?->toDateTimeString(), 'user' => $account->user, 'domain' => $account->domain,
            'owner' => $account->owner, 'reseller' => $account->reseller, 'plan' => $account->plan, 'ip' => $account->ip,
            'disk' => ['used_mb' => $account->disk_used_mb, 'limit_mb' => $account->disk_limit_mb],
            'inodes' => ['used' => $account->inodes_used, 'limit' => $account->inodes_limit],
            'bandwidth' => ['used_mb' => $account->bandwidth_used_mb, 'limit_mb' => $account->bandwidth_limit_mb],
            'near_limits' => $account->nearLimits(),
            'suspended' => $account->suspended, 'suspend_reason' => $account->suspend_reason === null ? null : Scrub::text($account->suspend_reason),
            'suspended_at' => $account->suspended_at?->toDateTimeString(),
            'mail_held' => $account->mail_held, 'mail_suspended' => $account->mail_suspended, 'max_email_per_hour' => $account->max_email_per_hour,
            'backup' => $account->backup, 'created_on_server' => $account->created_on_server_at?->toDateString(),
            'lve' => ['read_at' => $account->lve_read_at?->toDateTimeString(), 'peak_24h_now_limit' => $account->lve(), 'near' => $account->lveNear()],
            'differs' => ($differs = $account->differs()) === null ? null : CpanelAccount::DIFFERS[$differs],
            'note' => $account->note,
            'resold_accounts' => $account->reseller ? $resold->map(fn (CpanelAccount $a) => ['user' => $a->user, 'domain' => $a->domain, 'plan' => $a->plan,
                'disk_used_mb' => $a->disk_used_mb, 'suspended' => $a->suspended, 'service_id' => $a->service_id])->all() : null,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function guest(Service $service): ?array
    {
        $guest = ProxmoxGuest::query()->where('service_id', $service->id)->whereNull('gone_at')->latest('id')->first();

        return $guest === null ? null : [
            'read_at' => $guest->last_seen_at?->toDateTimeString(), 'vmid' => $guest->vmid, 'type' => $guest->type, 'node' => $guest->node,
            'pool' => $guest->pool, 'name' => $guest->name, 'status' => $guest->status, 'cpus' => $guest->cpus,
            'memory_mb' => intdiv((int) $guest->max_memory, 1048576),
            'disk_gb' => round((int) $guest->max_disk / 1073741824, 1),
            'uptime_hours' => round((int) $guest->uptime / 3600, 1),
            'ips' => $guest->ips, 'tags' => $guest->tags, 'note' => $guest->note,
        ];
    }

    /** @return list<array<string, mixed>>|null */
    private static function mailcow(Service $service): ?array
    {
        $domains = MailcowDomain::query()->where('service_id', $service->id)->whereNull('gone_at')->with('mailboxList')->orderBy('domain')->get();

        return $domains->isEmpty() ? null : $domains->map(fn (MailcowDomain $d) => [
            'read_at' => $d->last_seen_at?->toDateTimeString(), 'domain' => $d->domain, 'active' => $d->active,
            'mailboxes' => $d->mailboxes, 'mailbox_limit' => $d->mailbox_limit, 'aliases' => $d->aliases, 'alias_limit' => $d->alias_limit,
            'quota_mb' => self::mb($d->quota_bytes), 'used_mb' => self::mb($d->used_bytes), 'messages' => $d->messages,
            'dns_status' => $d->dns_status, 'dns_issues' => $d->dns_issues, 'dns_checked_at' => $d->dns_checked_at?->toDateTimeString(),
            'mailbox_list' => $d->mailboxList->map(fn (MailcowMailbox $m) => ['address' => $m->address, 'active' => $m->active,
                'quota_mb' => self::mb($m->quota_bytes), 'used_mb' => self::mb($m->used_bytes), 'messages' => $m->messages,
                'last_login' => $m->last_login_at?->toDateTimeString()])->all(),
        ])->all();
    }

    /** @return array<string, mixed> */
    private static function ssl(Service $service): array
    {
        $order = $service->sslOrder;

        return [
            'read_at' => $order->synced_at === null ? null : (string) $order->synced_at, 'status' => $order->status, 'common_name' => $order->common_name,
            'host_names' => $order->host_names, 'validation_method' => $order->validation_method,
            'requested_at' => $order->requested_at === null ? null : (string) $order->requested_at,
            'expires_at' => $order->expires_at === null ? null : (string) $order->expires_at,
            'sync_error' => $order->sync_error === null ? null : Scrub::text((string) $order->sync_error),
        ];
    }

    /** @return array<string, mixed>|null its machine's BMC as the Redfish sync last read it */
    public static function machine(int $serverId): ?array
    {
        $machine = RedfishMachine::query()->where('server_id', $serverId)->first();

        return $machine === null ? null : [
            'read_at' => $machine->read_at?->toDateTimeString(), 'power' => $machine->power_state, 'health' => $machine->health,
            'watts' => $machine->watts, 'inlet_celsius' => $machine->inlet_celsius, 'attention' => $machine->attentionLines(),
            'failed_at' => $machine->failed_at?->toDateTimeString(), 'error' => $machine->error === null ? null : Scrub::text((string) $machine->error),
        ];
    }

    private static function mb(mixed $bytes): ?int
    {
        return $bytes === null ? null : intdiv((int) $bytes, 1048576);
    }
}
