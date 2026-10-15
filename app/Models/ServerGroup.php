<?php

namespace App\Models;

use App\Exceptions\DisplayException;
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

    protected static function booted(): void
    {
        // Not while products send their new accounts to it (sweep §7): they would quietly fall back to their own server.
        static::deleting(function (self $group) {
            if (($products = $group->products()->count()) > 0) {
                throw new DisplayException("Server group «{$group->name}» is used by {$products} product(s): choose another group for them first.");
            }
        });
    }

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
