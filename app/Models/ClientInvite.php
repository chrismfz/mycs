<?php

namespace App\Models;

use App\Exceptions\DisplayException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An invitation of a login to a customer account (docs/accounts.md, PR 2b; WHMCS `tbluser_invites`). Only the token's
 * hash is stored; the link in the mail is the only copy. Used once, expires, revoked rather than deleted.
 *
 * @property int $id
 * @property int $client_id
 * @property string $email
 * @property array<int, string>|null $permissions
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property-read Client $client
 * @property-read User|null $invitedByUser
 * @property-read Staff|null $invitedByStaff
 */
class ClientInvite extends Model
{
    /** How long an invitation can be accepted (WHMCS: 7 days). */
    public const VALID_DAYS = 7;

    protected $fillable = ['client_id', 'email', 'token_hash', 'permissions', 'invited_by_user_id', 'invited_by_staff_id', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** @return BelongsTo<Staff, $this> */
    public function invitedByStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'invited_by_staff_id');
    }

    /** Still waiting to be accepted. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * Invite an email to the account; a pending invitation to the same email is replaced (its link stops working).
     *
     * @param  array<int, string>|null  $permissions  WHMCS names; null = all
     * @return array{0: self, 1: string} the invitation and its token (for the link; not stored)
     */
    public static function issue(Client $client, string $email, ?array $permissions, User|Staff|null $by): array
    {
        $email = Str::lower(trim($email));
        $token = Str::random(48);

        $invite = DB::transaction(function () use ($client, $email, $permissions, $by, $token) {
            self::query()->where('client_id', $client->id)->where('email', $email)->pending()->update(['revoked_at' => now()]);

            return self::query()->create([
                'client_id' => $client->id,
                'email' => $email,
                'token_hash' => self::hash($token),
                'permissions' => self::normalise($permissions),
                'invited_by_user_id' => $by instanceof User ? $by->id : null,
                'invited_by_staff_id' => $by instanceof Staff ? $by->id : null,
                'expires_at' => now()->addDays(self::VALID_DAYS),
            ]);
        });

        return [$invite, $token];
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', self::hash($token))->first();
    }

    /**
     * The login accepts: it gets a live grant with the invitation's permissions (a revoked one of theirs comes back
     * with them; a live one is kept as it is, never lowered). Once, under a lock, so two clicks make one grant.
     */
    public function accept(User $user): ClientUser
    {
        return DB::transaction(function () use ($user) {
            $invite = self::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();
            if (!$invite->isPending()) {
                throw new DisplayException(__('This invitation is no longer valid.'));
            }

            $grant = ClientUser::query()->where('client_id', $invite->client_id)->where('user_id', $user->id)->lockForUpdate()->first();
            if ($grant === null) {
                $grant = ClientUser::query()->create([
                    'client_id' => $invite->client_id,
                    'user_id' => $user->id,
                    'permissions' => $invite->permissions,
                    'granted_by' => $invite->invited_by_staff_id,
                    'granted_at' => now(),
                ]);
            } elseif ($grant->revoked_at !== null) {
                $grant->forceFill([
                    'permissions' => $invite->permissions,
                    'granted_by' => $invite->invited_by_staff_id,
                    'granted_at' => now(),
                    'revoked_at' => null,
                ])->save();
            }

            $invite->forceFill(['accepted_at' => now(), 'accepted_by_user_id' => $user->id])->save();
            $this->setRawAttributes($invite->getAttributes(), true);

            return $grant;
        });
    }

    /**
     * Only known names, in their order; all of them is stored as null (= all, also those added later).
     *
     * @param  array<int, string>|null  $permissions
     * @return array<int, string>|null
     */
    public static function normalise(?array $permissions): ?array
    {
        if ($permissions === null) {
            return null;
        }
        $known = array_values(array_intersect(ClientUser::PERMISSIONS, $permissions));

        return count($known) === count(ClientUser::PERMISSIONS) ? null : $known;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
