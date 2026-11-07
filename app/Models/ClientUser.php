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

    /**
     * On an account that is not closed (WG-8): a closed account is not worked on, nor reached by id (ADV-A6-1), as
     * CurrentClient (review of #651). Staff in «Login as client» may, so the callers skip it for them.
     */
    public function scopeOnOpenAccount(Builder $query): Builder
    {
        return $query->whereHas('client', fn (Builder $client) => $client->whereNull('closed_at'));
    }

    /**
     * The login's live grant on this account, as every by-id check of the client area asks it (review 2, A3): not
     * revoked, and on an open account unless $includeClosed (staff in «Login as client», the relay's check of a console
     * staff opened, a mail reply to a ticket). One place, so no caller drops the closed filter by hand (A4: callers
     * behind a policy gate keep it as defence in depth).
     */
    public static function liveGrant(User|int $user, int $clientId, bool $includeClosed = false): ?self
    {
        return self::query()->where('user_id', $user instanceof User ? $user->id : $user)->where('client_id', $clientId)
            ->whereNull('revoked_at')->when(!$includeClosed, fn (Builder $open) => $open->onOpenAccount())->first();
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
