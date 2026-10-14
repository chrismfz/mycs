<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WHMCS's server group: the servers a product's new accounts go to, picked by its fill rule (ServerSelector).
 *
 * @property string $fill_type least_full | in_order
 */
class ServerGroup extends Model
{
    public const FILL_LEAST_FULL = 'least_full';

    /** WHMCS's «Fill active server until full then switch to next least used»: the servers in their order. */
    public const FILL_IN_ORDER = 'in_order';

    protected $fillable = ['name', 'fill_type'];

    /** @return BelongsToMany<Server, $this> */
    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'server_group_server', 'server_group_id', 'server_id')
            ->withPivot('position')->orderByPivot('position');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
