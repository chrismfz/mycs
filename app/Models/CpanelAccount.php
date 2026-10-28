<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $suspended_at
 * @property Carbon|null $created_on_server_at
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
        'max_email_per_hour', 'backup', 'created_on_server_at', 'last_seen_at', 'gone_at', 'service_id', 'internal', 'note'];

    protected function casts(): array
    {
        return ['reseller' => 'boolean', 'suspended' => 'boolean', 'mail_held' => 'boolean', 'mail_suspended' => 'boolean', 'backup' => 'boolean',
            'internal' => 'boolean', 'suspended_at' => 'datetime', 'created_on_server_at' => 'datetime', 'last_seen_at' => 'datetime', 'gone_at' => 'datetime'];
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
