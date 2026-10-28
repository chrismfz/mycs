<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $suspended_at
 * @property Carbon|null $created_on_server_at
 * @property Carbon|null $lve_read_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $gone_at
 *
 * An account of a WHM server, as cpanel:sync last read it, and what it is to us: a customer's service, internal, or not
 * mapped yet.
 */
class CpanelAccount extends Model
{
    /** Near a limit from here (90%): the list's filter, the service page's warning. */
    public const NEAR = 0.9;

    protected $fillable = ['server_id', 'user', 'domain', 'owner', 'plan', 'ip', 'reseller', 'disk_used_mb', 'disk_limit_mb', 'inodes_used',
        'inodes_limit', 'bandwidth_used_mb', 'bandwidth_limit_mb', 'suspended', 'suspend_reason', 'suspended_at', 'mail_held', 'mail_suspended',
        'max_email_per_hour', 'backup', 'created_on_server_at', 'last_seen_at', 'gone_at', 'service_id', 'internal', 'note',
        'lve_memory_limit_mb', 'lve_entry_processes_limit', 'lve_processes_limit', 'lve_io_limit_kbs', 'lve_iops_limit', 'lve_read_at'];

    /**
     * CloudLinux's limits (cpanel:lve): each one's column in a sample and its limit's on the account (CPU is a percent of
     * the account's own limit: 100).
     */
    public const LVE = [
        'cpu' => ['sample' => 'cpu', 'limit' => null],
        'memory' => ['sample' => 'memory_mb', 'limit' => 'lve_memory_limit_mb'],
        'entry_processes' => ['sample' => 'entry_processes', 'limit' => 'lve_entry_processes_limit'],
        'processes' => ['sample' => 'processes', 'limit' => 'lve_processes_limit'],
        'io' => ['sample' => 'io_kbs', 'limit' => 'lve_io_limit_kbs'],
        'iops' => ['sample' => 'iops', 'limit' => 'lve_iops_limit'],
    ];

    protected function casts(): array
    {
        return ['reseller' => 'boolean', 'suspended' => 'boolean', 'mail_held' => 'boolean', 'mail_suspended' => 'boolean', 'backup' => 'boolean',
            'internal' => 'boolean', 'lve_read_at' => 'datetime', 'suspended_at' => 'datetime', 'created_on_server_at' => 'datetime', 'last_seen_at' => 'datetime', 'gone_at' => 'datetime'];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** What the server says against what MyCS says (owner 2026-10-28: the mistakes a move from WHMCS leaves). */
    public const DIFFERS = [
        'suspended_on_server' => 'Suspended on the server, active in MyCS',
        'active_on_server' => 'Active on the server, suspended in MyCS',
        'ended_here' => 'Still on the server, ended in MyCS',
    ];

    /**
     * How its state differs from its service's (a key of DIFFERS), or null. Only once the server was read after the
     * service last changed: a suspend MyCS just sent is not «differs» until the next read shows it.
     */
    public function differs(): ?string
    {
        $service = $this->service;
        if ($service === null || $this->gone_at !== null || $this->last_seen_at === null || $this->last_seen_at->lt($service->updated_at)) {
            return null;
        }

        return match (true) {
            $service->status === Service::STATUS_ACTIVE && $this->suspended => 'suspended_on_server',
            $service->status === Service::STATUS_SUSPENDED && !$this->suspended => 'active_on_server',
            in_array($service->status, [Service::STATUS_CANCELLED, Service::STATUS_TERMINATED], true) => 'ended_here',
            default => null,
        };
    }

    /**
     * The accounts whose state differs from their service's, as differs() reads it, in one query.
     *
     * @param  Builder<CpanelAccount>  $query
     */
    public static function whereDiffers(Builder $query): void
    {
        $query->whereNull('cpanel_accounts.gone_at')->whereHas('service', fn ($q) => $q
            ->whereColumn('cpanel_accounts.last_seen_at', '>=', 'services.updated_at')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('services.status', Service::STATUS_ACTIVE)->where('cpanel_accounts.suspended', true))
                ->orWhere(fn ($q) => $q->where('services.status', Service::STATUS_SUSPENDED)->where('cpanel_accounts.suspended', false))
                ->orWhereIn('services.status', [Service::STATUS_CANCELLED, Service::STATUS_TERMINATED])));
    }

    /** @return HasMany<CpanelLveSample, $this> */
    public function lveSamples(): HasMany
    {
        return $this->hasMany(CpanelLveSample::class);
    }

    /**
     * Its peaks of the last 24 hours, one subquery each (the list's 50 rows in one query): lve_<key>_peak.
     *
     * @param  Builder<CpanelAccount>  $query
     */
    public static function withLvePeaks(Builder $query): void
    {
        foreach (self::LVE as $key => $columns) {
            $query->withMax(["lveSamples as lve_{$key}_peak" => fn ($q) => $q->where('sampled_at', '>=', now()->subDay())], $columns['sample']);
        }
    }

    /**
     * CloudLinux's limits and its use of them (none when never read, or suspended): each one's highest reading of the last 24 hours, its last
     * reading and its limit.
     *
     * @return array<string, array{peak: int|null, now: int|null, limit: int|null}>
     */
    public function lve(): array
    {
        // A suspended account is not read: its last readings are not what it uses now (review of #497).
        if ($this->lve_read_at === null || $this->suspended) {
            return [];
        }
        $peaks = array_key_exists('lve_cpu_peak', $this->getAttributes()) ? null
            : $this->lveSamples()->where('sampled_at', '>=', now()->subDay())
                ->selectRaw(implode(', ', array_map(fn (array $c) => "max({$c['sample']}) as {$c['sample']}", self::LVE)))->first();
        // «Now» only a recent reading (its reads failing since: none).
        $last = $this->lveSamples()->where('sampled_at', '>=', now()->subHours(2))->latest('sampled_at')->first();
        $read = [];
        foreach (self::LVE as $key => $columns) {
            $peak = $peaks === null ? $this->getAttribute("lve_{$key}_peak") : $peaks->getAttribute($columns['sample']);
            $read[$key] = ['peak' => $peak === null ? null : (int) $peak, 'now' => $last?->getAttribute($columns['sample']),
                'limit' => $columns['limit'] === null ? 100 : $this->getAttribute($columns['limit'])];
        }

        return $read;
    }

    /** @return list<string> CloudLinux's limits its peak of the last 24 hours is near or at */
    public function lveNear(): array
    {
        return array_keys(array_filter($this->lve(), fn (array $l) => self::near($l['peak'], $l['limit'])));
    }

    /** @return list<string> the limits it is near or past: disk, inodes, bandwidth */
    public function nearLimits(): array
    {
        return array_keys(array_filter([
            'disk' => self::near($this->disk_used_mb, $this->disk_limit_mb),
            'inodes' => self::near($this->inodes_used, $this->inodes_limit),
            'bandwidth' => self::near($this->bandwidth_used_mb, $this->bandwidth_limit_mb),
        ]));
    }

    public static function near(?int $used, ?int $limit): bool
    {
        return $used !== null && $limit !== null && $limit > 0 && $used >= self::NEAR * $limit;
    }

    /**
     * The one live service (active or suspended) this account is on this server: its cPanel username the account's (the
     * service's property, else its username), the service on this server or its product's; never a reseller's customer's
     * account. None when none or more.
     */
    public function suggestedServiceId(): ?int
    {
        // A reseller's own customer's account is never one of our services by its name alone (a username reused under a
        // reseller would show another customer's account: review of #495); staff map one by hand.
        // Ours: root's, its own, or the server's login's (a server reached with a reseller's login lists that reseller's
        // accounts, which MyCS made: round 2 of #495).
        $login = strtolower(trim((string) $this->server?->settings->firstWhere('key', 'username')?->value));
        if ($this->owner !== null && !in_array($this->owner, array_filter(['root', $this->user, $login]), true)) {
            return null;
        }
        $ids = Service::query()->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
            ->where(fn ($q) => $q->where('server_id', $this->server_id)
                ->orWhere(fn ($q) => $q->whereNull('server_id')->whereHas('product', fn ($q) => $q->where('server_id', $this->server_id))))
            // Compared lower-cased, as the extension does (an imported «Bob» is bob on any database: review of #495).
            ->where(fn ($q) => $q->whereHas('properties', fn ($q) => $q->where('key', 'cpanel_username')->whereRaw('lower(value) = ?', [$this->user]))
                ->orWhere(fn ($q) => $q->whereDoesntHave('properties', fn ($q) => $q->where('key', 'cpanel_username'))->whereRaw('lower(username) = ?', [$this->user])))
            ->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
