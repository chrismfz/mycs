<?php

namespace App\Models;

use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\RoutesNotifications;
use Illuminate\Support\Carbon;
use LogicException;
use OwenIt\Auditing\Contracts\Auditable;
use SensitiveParameter;

/**
 * A member of staff (docs/accounts.md, PR 1): the only one who enters the admin panel, with the `staff` guard, its
 * own login, password reset and optional two-factor authentication. Never a customer: a person who is both has two
 * accounts, one in each table.
 *
 * @property-read string $name
 * @property-read string $avatar
 * @property bool $active
 * @property Carbon|null $last_seen_at
 * @property string $presence_status
 * @property string|null $app_authentication_secret
 * @property array<string>|null $app_authentication_recovery_codes
 */
class Staff extends Authenticatable implements Auditable, CanResetPasswordContract, FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasAvatar, HasName
{
    // RoutesNotifications, not Notifiable: mail (password reset) only; the `notifications` table is the customers'.
    use CanResetPassword, HasFactory, RoutesNotifications, Traits\Auditable;

    protected $table = 'staff';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'role_id',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    /** Never in an audit: secrets and the password hash. */
    protected $auditExclude = ['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return HasMany<TicketMessage, $this> */
    public function ticketMessages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /** @return HasMany<ApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function hasPermission(string $permission): bool
    {
        if (!$this->active || $this->role === null) {
            return false;
        }
        $permissions = $this->role->permissions ?? [];

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->active && $this->role !== null;
    }

    /**
     * What staff did stays theirs (DATA-3): ticket replies and money they moved. Such a member is disabled, not deleted.
     */
    public function hasHistory(): bool
    {
        return $this->ticketMessages()->exists()
            || MoneyEvent::query()->where('actor_type', $this->getMorphClass())->where('actor_id', $this->id)->exists();
    }

    public function delete(): ?bool
    {
        if ($this->exists && $this->hasHistory()) {
            throw new LogicException('A member of staff with ticket replies or money history is disabled, not deleted.');
        }

        return parent::delete();
    }

    /** @return Attribute<string, never> */
    protected function name(): Attribute
    {
        return Attribute::make(get: fn (): string => trim($this->first_name . ' ' . $this->last_name) ?: (string) $this->email);
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    /** @return Attribute<string, never> */
    protected function avatar(): Attribute
    {
        return Attribute::make(get: function (): string {
            $initials = mb_strtoupper(mb_substr((string) $this->first_name, 0, 1) . mb_substr((string) $this->last_name, 0, 1));
            $initials = htmlspecialchars($initials !== '' ? $initials : '?', ENT_QUOTES | ENT_XML1);
            $hue = crc32('staff' . $this->id) % 360;
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
                . '<rect width="64" height="64" fill="hsl(' . $hue . ',45%,45%)"/>'
                . '<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="sans-serif" font-size="26" fill="#fff">' . $initials . '</text>'
                . '</svg>';

            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        });
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->avatar;
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return (string) $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    /**
     * A new password ends what the old one let in: the MCP clients approved with this login (session; a key-approved
     * grant goes with its key) are revoked, as the admin's own sessions end (docs/mcp.md, review MCPO-3).
     */
    protected static function booted(): void
    {
        static::updated(function (self $staff) {
            // A new password or 2FA (a reset, or set up again), or a disabled member: whoever approved by session a grant
            // with the old ones loses it (security audit API-6).
            $why = match (true) {
                $staff->wasChanged('password') => 'Password changed',
                $staff->wasChanged('app_authentication_secret') => '2FA changed',
                $staff->wasChanged('active') && !$staff->active => 'Staff member disabled',
                default => null,
            };
            if ($why !== null) {
                McpOauthGrant::query()->where('staff_id', $staff->id)->where('approved_by', 'session')->whereNull('revoked_at')->get()
                    ->each(fn (McpOauthGrant $grant) => $grant->revoke($why));
            }
        });
    }

    /** 2FA is set up: the admin, and whatever acts as staff outside it, need it first (security audit, decided 2026-10-22). */
    public function hasTwoFactor(): bool
    {
        return filled($this->app_authentication_secret);
    }
}
