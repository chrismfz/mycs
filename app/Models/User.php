<?php

namespace App\Models;

use App\Observers\UserObserver;
use App\Support\CurrentClient;
use Dedoc\Scramble\Attributes\SchemaName;
use Filament\Models\Contracts\HasAvatar;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property-read mixed $initials
 * @property-read mixed $avatar
 * @property-read mixed $name
 */
#[SchemaName('UserModel')]
#[ObservedBy([UserObserver::class])]
/**
 * A customer login. Never staff (docs/accounts.md, PR 1): staff are App\Models\Staff, with their own guard. Not the
 * account either (PR 2a): services, invoices and credit are a Client's, which logins see through `client_user`.
 */
class User extends Authenticatable implements Auditable, HasAvatar
{
    use HasFactory, Notifiable, Traits\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'tfa_secret',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'tfa_secret',
        'tfa_recovery_codes',
    ];

    /** Never in the audit trail (as staff's): the password hash, the remember token, the 2FA secret and recovery codes (PR #158 review A2). */
    protected $auditExclude = ['password', 'remember_token', 'tfa_secret', 'tfa_recovery_codes'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'tfa_secret' => 'encrypted',
            // The hashes of its unused 2FA recovery codes (RecoveryCodes), never the codes.
            'tfa_recovery_codes' => 'encrypted:array',
        ];
    }

    /** Its 2FA secret changed (turned off by the customer or staff, a new one): the recovery codes of the old one go. */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->isDirty('tfa_secret') && !$user->isDirty('tfa_recovery_codes')) {
                $user->tfa_recovery_codes = null;
            }
        });
    }

    /**
     * Initials of the user.
     *
     * @return Attribute<mixed, mixed>
     */
    public function initials(): Attribute
    {
        return Attribute::make(
            get: fn () => strtoupper(substr($this->first_name, 0, 1) . substr($this->last_name, 0, 1)),
        );
    }

    /**
     * Avatar for the user: a locally generated SVG with the initials, so no
     * third party (e.g. Gravatar) learns who is logged in.
     *
     * @return Attribute<mixed, mixed>
     */
    public function avatar(): Attribute
    {
        return Attribute::make(
            get: function () {
                $initials = mb_strtoupper(mb_substr((string) $this->first_name, 0, 1) . mb_substr((string) $this->last_name, 0, 1));
                $initials = htmlspecialchars($initials !== '' ? $initials : '?', ENT_QUOTES | ENT_XML1);
                $hue = crc32((string) $this->id) % 360;
                $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
                    . '<rect width="64" height="64" fill="hsl(' . $hue . ',45%,45%)"/>'
                    . '<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="sans-serif" font-size="26" fill="#fff">' . $initials . '</text>'
                    . '</svg>';

                return 'data:image/svg+xml;base64,' . base64_encode($svg);
            },
        );
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar;
    }

    /**
     * Get the display name for the user.
     *
     * @return Attribute<mixed, mixed>
     */
    public function name(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->first_name . ' ' . $this->last_name) ?: $this->email,
        );
    }

    /** A customer holds no admin permission (only Staff do). */
    public function hasPermission(string $permission): bool
    {
        return false;
    }

    /**
     * Get the user's sessions.
     *
     * @return HasMany<UserSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * The customer accounts this login sees (live grants), WHMCS `tblusers_clients`.
     *
     * @return BelongsToMany<Client, $this, ClientUser>
     */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class)->using(ClientUser::class)
            ->withPivot(['id', 'owner', 'permissions', 'granted_by', 'granted_at', 'revoked_at', 'last_login_at'])
            ->withTimestamps()->wherePivotNull('revoked_at');
    }

    /**
     * Every grant of this login, revoked ones too.
     *
     * @return HasMany<ClientUser, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(ClientUser::class);
    }

    /**
     * The account this login works on (docs/accounts.md, PR 2a): in the client area the current one (CurrentClient),
     * otherwise the first it sees, its own first. Null for a login without any account.
     */
    public function client(): ?Client
    {
        if (Auth::guard('web')->id() === $this->id) {
            return app(CurrentClient::class)->get();
        }

        return CurrentClient::defaultFor($this);
    }

    /** @return HasMany<Notification, $this> */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /** @return HasMany<NotificationPreference, $this> */
    public function notificationsPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    /** @return HasMany<NotificationSubscription, $this> */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(NotificationSubscription::class);
    }

    /** @return HasMany<UserAuthenticationLog, $this> */
    public function authenticationLogs(): HasMany
    {
        return $this->hasMany(UserAuthenticationLog::class);
    }
}
