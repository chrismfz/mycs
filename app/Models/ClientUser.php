<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A login's grant on a customer account (docs/accounts.md, PR 2a): WHMCS `tblusers_clients` (owner, permissions,
 * last login), with Ekdosi's history (who granted it and when; revoked, not deleted).
 *
 * @property int $id
 * @property int $client_id
 * @property int $user_id
 * @property bool $owner
 * @property int|null $owner_of_client_id
 * @property array<int, string>|null $permissions
 */
class ClientUser extends Pivot
{
    protected $table = 'client_user';

    public $incrementing = true;

    /** The WHMCS permission names: an owner, or a grant with NULL permissions, has them all. */
    public const PERMISSIONS = ['profile', 'contacts', 'products', 'manageproducts', 'productsso', 'domains', 'managedomains',
        'invoices', 'quotes', 'tickets', 'affiliates', 'emails', 'orders', 'chat'];

    protected $fillable = ['client_id', 'user_id', 'owner', 'owner_of_client_id', 'permissions', 'granted_by', 'granted_at', 'revoked_at', 'last_login_at'];

    protected function casts(): array
    {
        return [
            'owner' => 'boolean',
            'permissions' => 'array',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** Only live grants count. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Staff, $this> */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'granted_by');
    }

    public function allows(string $permission): bool
    {
        return $this->revoked_at === null && ($this->owner || $this->permissions === null || in_array($permission, $this->permissions, true));
    }
}
