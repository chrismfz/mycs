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
        return $this->services()->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED, Service::STATUS_PENDING])->count();
    }

    public function hasRoom(): bool
    {
        return $this->max_accounts === null || $this->accountsCount() < (int) $this->max_accounts;
    }
}
