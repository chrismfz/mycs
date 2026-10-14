<?php

namespace App\Models;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Models\Traits\HasProperties;
use App\Observers\ClientObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A customer account (docs/accounts.md, PR 2a), WHMCS `tblclients`: services, invoices, credit and tickets are the
 * account's; the logins that see it are users (`client_user`, WHMCS `tblusers_clients`). The id is the WHMCS client
 * id. Until the contacts (PR 3) the account's name and email are its owner's.
 *
 * @property-read string $name
 * @property-read string|null $email
 * @property-read string|null $first_name
 * @property-read string|null $last_name
 */
#[ObservedBy([ClientObserver::class])]
class Client extends Model implements Auditable
{
    use HasFactory, HasProperties, Traits\Auditable;

    protected $fillable = [
        'credit_auto_apply',
    ];

    protected function casts(): array
    {
        return [
            'credit_auto_apply' => 'boolean',
        ];
    }

    /**
     * The logins with a live grant on the account.
     *
     * @return BelongsToMany<User, $this, ClientUser>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(ClientUser::class)
            ->withPivot(['id', 'owner', 'permissions', 'granted_by', 'granted_at', 'revoked_at', 'last_login_at'])
            ->withTimestamps()->wherePivotNull('revoked_at');
    }

    /**
     * Every grant, revoked ones too (the history).
     *
     * @return HasMany<ClientUser, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(ClientUser::class);
    }

    /**
     * Invitations of logins to the account (PR 2b).
     *
     * @return HasMany<ClientInvite, $this>
     */
    public function invites(): HasMany
    {
        return $this->hasMany(ClientInvite::class);
    }

    /**
     * The owner's live grant: one per account at most (the database keeps it so). A revoked owner is no owner: no
     * mail, no name on the account, no «Login as client» as them.
     *
     * @return HasOne<ClientUser, $this>
     */
    public function ownerGrant(): HasOne
    {
        return $this->hasOne(ClientUser::class)->whereNotNull('owner_of_client_id')->whereNull('revoked_at');
    }

    /** The owner: who the account's mail goes to and whose name it carries until the contacts (PR 3). */
    public function owner(): ?User
    {
        return $this->ownerGrant?->user;
    }

    /** @return Attribute<string, never> */
    protected function name(): Attribute
    {
        return Attribute::make(get: fn () => $this->owner()->name ?? __('Client') . ' #' . $this->id);
    }

    /** @return Attribute<string|null, never> */
    protected function email(): Attribute
    {
        return Attribute::make(get: fn () => $this->owner()?->email);
    }

    /** @return Attribute<string|null, never> */
    protected function firstName(): Attribute
    {
        return Attribute::make(get: fn () => $this->owner()?->first_name);
    }

    /** @return Attribute<string|null, never> */
    protected function lastName(): Attribute
    {
        return Attribute::make(get: fn () => $this->owner()?->last_name);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** @return HasMany<Credit, $this> */
    public function credits(): HasMany
    {
        return $this->hasMany(Credit::class);
    }

    /**
     * The account's money journal (docs/money-trail.md §1): read only.
     *
     * @return HasMany<MoneyEvent, $this>
     */
    public function moneyEvents(): HasMany
    {
        return $this->hasMany(MoneyEvent::class);
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
        return $this->hasManyThrough(InvoiceTransaction::class, Invoice::class, 'client_id', 'invoice_id', 'id', 'id');
    }

    /**
     * Money history (docs/money-trail.md §8): journal events, a payment (a processing one too: it lands later), or
     * account credit ≠ 0. Such an account is never deleted: its invoices would lose their customer and the credit would
     * go by cascade.
     */
    public function hasMoneyHistory(): bool
    {
        return MoneyEvent::query()->where('client_id', $this->id)->exists()
            || InvoiceTransaction::query()
                ->whereIn('status', [InvoiceTransactionStatus::Succeeded->value, InvoiceTransactionStatus::Processing->value])
                ->whereHas('invoice', fn ($query) => $query->where('client_id', $this->id))->exists()
            || Credit::query()->where('client_id', $this->id)->where('amount', '!=', 0)->exists();
    }

    /** The account lock of the payment paths is held through the guard (ClientObserver::deleting) and the DELETE. */
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
     * A new account with its owner (registration, staff, API, import): in one transaction, so no account is ever
     * without its owner. The importer passes the WHMCS client id.
     */
    public static function openFor(User $owner, ?int $id = null, ?Staff $grantedBy = null): self
    {
        return DB::transaction(function () use ($owner, $id, $grantedBy) {
            $client = new self;
            if ($id !== null) {
                $client->id = $id;
            }
            $client->save();
            ClientUser::query()->create([
                'client_id' => $client->id, 'user_id' => $owner->id, 'owner' => true, 'owner_of_client_id' => $client->id,
                'granted_by' => $grantedBy?->id, 'granted_at' => now(),
            ]);

            return $client;
        });
    }

    /**
     * Give a login access, or change what an existing one may do (PR 2b): a revoked grant comes back. The owner's
     * grant always has everything and is not changed here.
     *
     * @param  array<int, string>|null  $permissions  WHMCS names; null = all
     */
    public function grantTo(User $user, ?array $permissions, ?Staff $by = null): ClientUser
    {
        return DB::transaction(function () use ($user, $permissions, $by) {
            $grant = $this->grants()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($grant?->owner) {
                return $grant;
            }
            $grant ??= new ClientUser(['client_id' => $this->id, 'user_id' => $user->id]);
            $wasLive = $grant->exists && $grant->revoked_at === null;
            $grant->forceFill([
                'permissions' => ClientInvite::normalise($permissions),
                'revoked_at' => null,
            ] + ($wasLive ? [] : ['granted_by' => $by?->id, 'granted_at' => now()]))->save();

            return $grant;
        });
    }

    /** Take a login's access away; kept as history (revoked_at). The owner's grant is not revoked: transfer first. */
    public function revoke(ClientUser $grant): void
    {
        if ((int) $grant->client_id !== $this->id) {
            throw new InvalidArgumentException('Not a grant of this account.');
        }
        if ($grant->owner) {
            throw new DisplayException(__('The owner cannot be removed: make another user the owner first.'));
        }
        $grant->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * Make another login the owner (staff only, as WHMCS): the old owner keeps access with all permissions, the new
     * one gets the owner's grant. The unique owner_of_client_id is freed before it is taken, in one transaction.
     */
    public function transferOwnership(User $to, ?Staff $by = null): void
    {
        DB::transaction(function () use ($to, $by) {
            self::lock($this->id);
            $old = $this->grants()->whereNotNull('owner_of_client_id')->lockForUpdate()->first();
            if ($old !== null && (int) $old->user_id === $to->id) {
                if ($old->revoked_at !== null) {
                    $old->forceFill(['revoked_at' => null])->save();
                }

                return;
            }
            $old?->forceFill(['owner' => false, 'owner_of_client_id' => null, 'permissions' => null])->save();

            $new = $this->grants()->where('user_id', $to->id)->lockForUpdate()->first()
                ?? new ClientUser(['client_id' => $this->id, 'user_id' => $to->id, 'granted_by' => $by?->id, 'granted_at' => now()]);
            $new->forceFill(['owner' => true, 'owner_of_client_id' => $this->id, 'permissions' => null, 'revoked_at' => null])->save();
        });
        $this->unsetRelation('ownerGrant');
    }

    /** The account's row lock, which every path that moves its money takes first (one order: account, then the rest). */
    public static function lock(int $clientId): void
    {
        static::query()->whereKey($clientId)->lockForUpdate()->first();
    }
}
