<?php

namespace App\Models;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Traits\HasProperties;
use App\Observers\UserObserver;
use Dedoc\Scramble\Attributes\SchemaName;
use Filament\Models\Contracts\FilamentUser;
// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property-read mixed $initials
 * @property-read mixed $avatar
 * @property-read mixed $name
 */
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
     *
     * @return HasMany<UserSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /**
     * Get the user's orders.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the user's services
     *
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Get the user's invoices.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
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
     *
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Money history (docs/money-trail.md §8): journal events, a payment (a processing one too: it lands later), or
     * account credit ≠ 0 (set before the journal, or by a path MT-1b moves to the ledger). Such a customer is never
     * deleted: their invoices would lose their customer and the credit would go by cascade.
     */
    public function hasMoneyHistory(): bool
    {
        return MoneyEvent::query()->where('user_id', $this->id)->exists()
            || InvoiceTransaction::query()
                ->whereIn('status', [InvoiceTransactionStatus::Succeeded->value, InvoiceTransactionStatus::Processing->value])
                ->whereHas('invoice', fn ($query) => $query->where('user_id', $this->id))->exists()
            || Credit::query()->where('user_id', $this->id)->where('amount', '!=', 0)->exists();
    }

    /** The customer lock of the payment paths is held through the guard (UserObserver::deleting) and the DELETE. */
    public function delete(): ?bool
    {
        if (!$this->exists) {
            return parent::delete();
        }

        return DB::transaction(function () {
            static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            return parent::delete();
        });
    }

    /**
     * Get the user's credits
     *
     * @return HasMany<Credit, $this>
     */
    public function credits(): HasMany
    {
        return $this->hasMany(Credit::class);
    }

    /** @return HasOne<Cart, $this> */
    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    /** @return HasMany<BillingAgreement, $this> */
    public function billingAgreements(): HasMany
    {
        return $this->hasMany(BillingAgreement::class);
    }

    /** @return HasManyThrough<InvoiceTransaction, Invoice, $this> */
    public function transactions(): HasManyThrough
    {
        return $this->hasManyThrough(InvoiceTransaction::class, Invoice::class, 'user_id', 'invoice_id', 'id', 'id');
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
