<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Extension
{
    protected $table = 'extensions';

    public function newQuery()
    {
        return parent::newQuery()->where('type', 'server');
    }

    /** @return HasMany<Service, $this> the accounts on this server (WHMCS's tblhosting.server) */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'server_id');
    }

    /** @return BelongsToMany<ServerGroup, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ServerGroup::class, 'server_group_server', 'server_id', 'server_group_id');
    }

    /** Accounts it holds, as WHMCS counts them for «max accounts»: active, suspended and pending ones. */
    public function accountsCount(): int
    {
        return self::liveAccountsOn($this->id);
    }

    public static function liveAccountsOn(int $serverId): int
    {
        return Service::query()->where('server_id', $serverId)
            ->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED, Service::STATUS_PENDING])->count();
    }

    /** It has its settings (a host, a login): a server added but not set up yet takes no new account (closeout F2). */
    public function isConfigured(): bool
    {
        return $this->settings()->exists();
    }

    public function hasRoom(): bool
    {
        return $this->max_accounts === null || $this->accountsCount() < (int) $this->max_accounts;
    }
}
