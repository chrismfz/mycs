<?php

namespace App\Models;

use App\Models\Traits\HasProperties;
// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Observers\UserObserver;
use Dedoc\Scramble\Attributes\SchemaName;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use OwenIt\Auditing\Contracts\Auditable;

#[SchemaName('UserModel')]
#[ObservedBy([UserObserver::class])]
class User extends Authenticatable implements Auditable, FilamentUser, HasAvatar, OAuthenticatable
{
    use HasApiTokens, HasFactory, HasProperties, Notifiable, Traits\Auditable;

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
        'role_id',
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
    ];

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
        ];
    }

    /**
     * Initials of the user.
     *
     * @return string
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
     * @return string
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
     * @return string
     */
    public function name(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->first_name . ' ' . $this->last_name) ?: $this->email,
        );
    }

    public function hasPermission($permission): bool
    {
        if (is_null($this->role)) {
            return false;
        }

        // If the user has all permissions, return true
        if (in_array('*', $this->role->permissions)) {
            return true;
        }

        return in_array($permission, $this->role->permissions);
    }

    /** Relationships */
    /**
     * Get the role that the user belongs to.
     * Can be null if the user is a normal user (non-admin).
     */
    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the user's sessions.
     */
    public function sessions()
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * Get the user's orders.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the user's services
     */
    public function services()
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Get the user's invoices.
     */
    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'admin') {
            return !is_null($this->role);
        }

        return false;
    }

    /**
     * Get the user tickets
     */
    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Get the user's credits
     */
    public function credits()
    {
        return $this->hasMany(Credit::class);
    }

    public function cart()
    {
        return $this->hasOne(Cart::class);
    }

    public function billingAgreements()
    {
        return $this->hasMany(BillingAgreement::class);
    }

    public function transactions()
    {
        return $this->hasManyThrough(InvoiceTransaction::class, Invoice::class, 'user_id', 'invoice_id', 'id', 'id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function notificationsPreferences()
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function pushSubscriptions()
    {
        return $this->hasMany(NotificationSubscription::class);
    }

    public function authenticationLogs()
    {
        return $this->hasMany(UserAuthenticationLog::class);
    }
}
