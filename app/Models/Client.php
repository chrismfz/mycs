<?php

namespace App\Models;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Traits\HasProperties;
use App\Observers\ClientObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A customer account (docs/accounts.md, PR 2a), WHMCS `tblclients`: services, invoices, credit and tickets are the
 * account's; the logins that see it are users (`client_user`, WHMCS `tblusers_clients`). The id is the WHMCS client
 * id. Its details (name, email, address, country) are its primary contact's (PR 3a), as tblclients holds them.
 *
 * @property string|null $language
 * @property-read string $name
 * @property-read string|null $email
 * @property-read string|null $first_name
 * @property-read string|null $last_name
 * @property-read string|null $country
 * @property-read Contact|null $primaryContact
 */
#[ObservedBy([ClientObserver::class])]
class Client extends Model implements Auditable
{
    use HasFactory, HasProperties, Traits\Auditable;

    protected $fillable = [
        // Its mails' and PDFs' language (docs/accounts.md); null: the instance's.
        'language',
        'credit_auto_apply',
        'tax_exempt',
        'separate_invoices',
        'dunning_exempt',
        'no_overdue_reminders',
        'default_billing_agreement_id',
        'auto_charge',
        'client_group_id',
        // Ekdosi's (owner 2026-10-24): kept for the bridge, staff only.
        'ignore_invoicing',
        'immediate_invoicing',
    ];

    protected function casts(): array
    {
        return [
            'credit_auto_apply' => 'boolean',
            'tax_exempt' => 'boolean',
            'separate_invoices' => 'boolean',
            'dunning_exempt' => 'boolean',
            'no_overdue_reminders' => 'boolean',
            'auto_charge' => 'boolean',
            'ignore_invoicing' => 'boolean',
            'immediate_invoicing' => 'boolean',
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

    /** The owner: who the account's mail goes to until the contacts choose recipients per kind (PR 3b). */
    public function owner(): ?User
    {
        return $this->ownerGrant?->user;
    }

    /**
     * The account's contacts (PR 3a), archived ones included.
     *
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * The primary contact: the account's details, as WHMCS keeps them in tblclients (decision 2026-10-14).
     *
     * @return HasOne<Contact, $this>
     */
    public function primaryContact(): HasOne
    {
        return $this->hasOne(Contact::class, 'primary_of_client_id');
    }

    /** Its primary contact, made from the owner when an account has none (legacy rows, factories). */
    public function ensurePrimaryContact(): Contact
    {
        if ($this->primaryContact !== null) {
            return $this->primaryContact;
        }
        $owner = $this->owner();
        $contact = $this->contacts()->make(['first_name' => $owner?->first_name, 'last_name' => $owner?->last_name, 'email' => $owner?->email]);
        $contact->forceFill(['primary_of_client_id' => $this->id])->save();
        $this->setRelation('primaryContact', $contact);

        return $contact;
    }

    /**
     * The account's name: its primary contact's (the company for a business), else its owner's.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::make(get: fn () => ($this->primaryContact?->displayName() ?: null)
            ?? $this->owner()->name ?? __('Client') . ' #' . $this->id);
    }

    /** @return Attribute<string|null, never> */
    protected function email(): Attribute
    {
        return Attribute::make(get: fn () => $this->primaryContact->email ?? $this->owner()?->email);
    }

    /** @return Attribute<string|null, never> */
    protected function firstName(): Attribute
    {
        return Attribute::make(get: fn () => $this->primaryContact->first_name ?? $this->owner()?->first_name);
    }

    /** @return Attribute<string|null, never> */
    protected function lastName(): Attribute
    {
        return Attribute::make(get: fn () => $this->primaryContact->last_name ?? $this->owner()?->last_name);
    }

    /** @return Attribute<string|null, never> */
    protected function country(): Attribute
    {
        return Attribute::make(get: fn () => $this->primaryContact?->country);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return BelongsTo<ClientGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ClientGroup::class, 'client_group_id');
    }

    /** Its renewals are invoiced one per service: its own setting, or its group's (WHMCS). */
    public function separatesInvoices(): bool
    {
        return $this->separate_invoices || (bool) $this->group?->separate_invoices;
    }

    /** The cron never suspends or terminates its services: its own setting, or its group's (WHMCS). */
    public function dunningExempt(): bool
    {
        return $this->dunning_exempt || (bool) $this->group?->dunning_exempt;
    }

    /** It gets the overdue reminders (the ones before the due date are sent all the same): unless it or its group says no. */
    public function remindedWhenOverdue(): bool
    {
        return !$this->no_overdue_reminders && !$this->group?->no_overdue_reminders;
    }

    /**
     * The accounts a flag is set for, on the account or on its group, as a query on clients.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public static function whereFlag(Builder $query, string $flag): Builder
    {
        return $query->where(fn (Builder $q) => $q->where($flag, true)->orWhereHas('group', fn (Builder $g) => $g->where($flag, true)));
    }

    /** Its group's discount for pricing: null when none. */
    public function groupPercent(): ?string
    {
        return $this->group?->percent();
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** @return HasMany<Domain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
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

    /** @return HasMany<ClientNote, $this> staff's notes on the account (never the client area's) */
    public function notes(): HasMany
    {
        return $this->hasMany(ClientNote::class);
    }

    /** @return HasMany<BillingAgreement, $this> */
    public function billingAgreements(): HasMany
    {
        return $this->hasMany(BillingAgreement::class);
    }

    /**
     * The saved method that pays its renewals (decision 6: one per account, as WHMCS and Blesta).
     *
     * @return BelongsTo<BillingAgreement, $this>
     */
    public function defaultBillingAgreement(): BelongsTo
    {
        return $this->belongsTo(BillingAgreement::class, 'default_billing_agreement_id');
    }

    /** The method the cron charges: its default, unless «Auto-charge» is off or the method is gone. */
    public function autoChargeAgreement(): ?BillingAgreement
    {
        $agreement = $this->autoChargeMethod();

        // Of a gateway staff disabled: kept, but it pays nothing on its own (ExtensionHelper::customerGateways()).
        return $agreement !== null && ExtensionHelper::gatewayEnabled($agreement->gateway_id) ? $agreement : null;
    }

    /**
     * The account's method for its renewals with «Auto-charge» on, whether its gateway is enabled or not: the cron still
     * resends a charge of unknown outcome on it with its key (AutoCharge::agreementFor, adversarial ADV-540-2).
     */
    public function autoChargeMethod(): ?BillingAgreement
    {
        if (!$this->auto_charge || $this->default_billing_agreement_id === null) {
            return null;
        }
        $agreement = $this->defaultBillingAgreement;

        return $agreement !== null && (int) $agreement->client_id === (int) $this->id ? $agreement : null;
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
            // Its details start from the owner's (PR 3a): the forms fill in the rest.
            $client->contacts()->make(['first_name' => $owner->first_name, 'last_name' => $owner->last_name, 'email' => $owner->email])
                ->forceFill(['primary_of_client_id' => $client->id])->save();

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
                // The owner has everything; a revoked owner's grant (imported data) only comes back.
                if ($grant->revoked_at !== null) {
                    $grant->forceFill(['revoked_at' => null])->save();
                }

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

    /**
     * Where the account's mails go (owner 2026-10-30, as WHMCS's tblclients.email): its primary contact's address; the
     * owner's login's when it has none that is valid. The login keeps its own (reset, verification) and the in-app.
     */
    public function mailAddress(): string
    {
        $email = trim((string) $this->primaryContact?->email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : (string) $this->owner()?->email;
    }

    /**
     * The account's contacts still mailed at this address (owner 2026-10-30): a member's own address on a contact keeps
     * taking the account's mails after their access is removed, so whoever removes it is told (ADV-550 note).
     *
     * @return Collection<int, Contact>
     */
    public function contactsMailedAt(?string $email): Collection
    {
        $email = mb_strtolower(trim((string) $email));

        // The primary too: its address is the account's (mailAddress).
        return $email === '' ? collect() : $this->contacts()->active()->get()
            ->filter(fn (Contact $contact) => mb_strtolower(trim((string) $contact->email)) === $email
                && ($contact->isPrimary() || $contact->emailPreferencesShown() !== []))
            ->values();
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

    /** The mails sent to this account (the mail audit, docs/mail.md). */
    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }
}
