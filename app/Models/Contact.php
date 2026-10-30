<?php

namespace App\Models;

use App\Enums\DomainStatus;
use App\Events\Service\RenewalTaxChanged;
use App\Exceptions\DisplayException;
use App\Jobs\CheckVatNumber;
use App\Services\Domains\ContactPush;
use App\Services\Tax\EuVat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A contact of a customer account (docs/accounts.md, PR 3a): WHMCS `tblcontacts` and Ekdosi's `mod_ekdosi_contacts`
 * as one. The primary contact is the account's details (as `tblclients` holds them). The same contact can receive the
 * account's mails, be an invoice's recipient (Ekdosi) and a domain's registrant, admin, tech or billing contact; what
 * it must have filled in depends on that role, not on the contact (decision 2026-10-14).
 *
 * @property int $id
 * @property int $client_id
 * @property int|null $primary_of_client_id
 * @property string $type
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $company_name
 * @property string|null $tax_id
 * @property string|null $vat_number
 * @property string|null $vat_status what VIES (or staff) last said of vat_checked_number: pending, valid, invalid (PR G)
 * @property string|null $vat_checked_number
 * @property Carbon|null $vat_checked_at
 * @property string|null $tax_office
 * @property string|null $activity
 * @property string|null $address1
 * @property string|null $address2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postcode
 * @property string|null $country
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $notes
 * @property string|null $document_type
 * @property array<int, string>|null $email_preferences
 * @property Carbon|null $archived_at
 * @property-read Client $client
 */
class Contact extends Model implements Auditable
{
    // Audited (closeout): contacts are edited on the account and on the domain page, and are WHOIS and invoice data.
    use HasFactory, Traits\Auditable;

    public const TYPES = ['individual', 'business'];

    public const DOCUMENT_TYPES = ['invoice', 'receipt', 'none'];

    /** The mails a contact can receive (tblcontacts' generalemails, invoiceemails…). The primary's gate the owner's (App\Support\EmailKinds). */
    public const EMAIL_PREFERENCES = ['general', 'invoice', 'support', 'product', 'domain', 'affiliate'];

    /**
     * What each role needs filled in (decision 2026-10-14). An invoice recipient is the Greek document's
     * counterparty when it is an invoice (τιμολόγιο): company, tax id, tax office and address; a receipt needs a
     * name. A domain contact what registries ask for (no tax id). An email recipient only an email.
     */
    public const REQUIRED = [
        'invoice' => ['company_name', 'tax_id', 'tax_office', 'address1', 'city', 'postcode', 'country'],
        'receipt' => ['first_name', 'last_name'],
        'domain' => ['first_name', 'last_name', 'email', 'phone', 'address1', 'city', 'postcode', 'country'],
        'email' => ['email'],
    ];

    protected $fillable = [
        'type', 'first_name', 'last_name', 'company_name', 'tax_id', 'vat_number', 'tax_office', 'activity',
        'address1', 'address2', 'city', 'state', 'postcode', 'country', 'email', 'phone', 'notes', 'document_type',
        'email_preferences',
    ];

    /** A new VAT number was saved: VIES checks it after the commit (PR G). Not a column. */
    public bool $vatCheckDue = false;

    /**
     * Who the contact is, as its registrars hold it (P1b, owner 2026-10-20): never changed while a domain uses it in any
     * role. The .gr registry never changes a contact's name, and a holder's is an owner change (charged); at Openprovider
     * often a trade. A new contact, given the role, is the way; for the holder, the owner change.
     */
    public const IDENTITY = ['type', 'first_name', 'last_name', 'company_name'];

    protected static function booted(): void
    {
        static::updating(function (self $contact) {
            if ($contact->isDirty(self::IDENTITY) && ($use = $contact->domainRoles(atRegistrar: true)->first()) !== null) {
                throw new DisplayException(__('domains.contact_identity_in_use', ['role' => __('domains.role.' . $use['role']), 'domain' => $use['fqdn']]));
            }
        });
        // A new VAT number is not known yet (PR G): VAT is charged until VIES says it is valid (fail closed). A new
        // country is one too when the number has no prefix: it is read with the contact's country (review CR-1).
        static::saving(function (self $contact) {
            if (!$contact->isDirty(['vat_number', 'country'])) {
                return;
            }
            $number = EuVat::normalized($contact->vat_number, $contact->country);
            if ($number === null) {
                $contact->forceFill(['vat_status' => null, 'vat_checked_number' => null, 'vat_checked_at' => null]);
            } elseif ($number !== $contact->vat_checked_number) {
                $contact->forceFill(['vat_status' => EuVat::STATUS_PENDING, 'vat_checked_number' => $number, 'vat_checked_at' => null]);
                $contact->vatCheckDue = true;
            }
        });
        // Checked once the save commits; the hourly vat:recheck tries again while VIES gives no answer. Only the
        // primary contact's number bills (review CR-3); another one is checked when it becomes primary.
        static::saved(function (self $contact) {
            if ($contact->vatCheckDue && $contact->isPrimary() && EuVat::enabled()) {
                CheckVatNumber::dispatch($contact->id)->afterCommit();
            }
            $contact->vatCheckDue = false;
        });
        // The account's country is its primary contact's (PR 3a): a new country, or another contact made primary,
        // changes what its subscriptions bill (Δ1).
        // Its address, phone or email changed: sent to the registrars of the domains that use it (P1b-2), once committed.
        static::updated(function (self $contact) {
            if ($contact->wasChanged(ContactPush::DETAILS)) {
                ContactPush::dispatch($contact);
            }
        });
        static::updated(function (self $contact) {
            if ($contact->wasChanged('primary_of_client_id') || ($contact->isPrimary() && $contact->wasChanged(['country', 'vat_number', 'vat_status']))) {
                RenewalTaxChanged::forSubscribed(Service::query()->where('client_id', $contact->client_id));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_preferences' => 'array',
            'archived_at' => 'datetime',
            'vat_checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * It takes this kind of mail (EMAIL_PREFERENCES); an empty list none. None chosen (null): the primary every kind, as
     * WHMCS's tblclients; another contact none (a domain's registrant or an invoice's recipient never chose the
     * account's mails, owner 2026-10-30).
     */
    public function takesEmail(string $kind): bool
    {
        return $this->email_preferences === null ? $this->isPrimary() : in_array($kind, $this->email_preferences, true);
    }

    /** What its form shows ticked: null is every kind for the primary, none for another contact (takesEmail). */
    public function emailPreferencesShown(): array
    {
        return $this->email_preferences ?? ($this->isPrimary() ? self::EMAIL_PREFERENCES : []);
    }

    public function isPrimary(): bool
    {
        return $this->primary_of_client_id !== null;
    }

    public function fullName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    /** The name a document or a list shows: the company for a business, else the person. */
    public function displayName(): string
    {
        return $this->type === 'business' && $this->company_name ? $this->company_name : ($this->fullName() ?: (string) $this->company_name);
    }

    /**
     * How a list or a page names it: its name, else its tax id (a company entered by its ΑΦΜ only, the rest filled in
     * by Ekdosi from the registry: live test of 2026-10-27), its EU VAT number, its email, its number. Never blank: a
     * select with a blank option refused it as invalid. The account's name and the invoice keep displayName().
     */
    public function label(): string
    {
        return $this->displayName()
            ?: ($this->tax_id ? __('invoices.tax_id_line', ['tax_id' => $this->tax_id])
            : ($this->vat_number ? __('invoices.vat_number_line', ['vat_number' => $this->vat_number])
            : ((string) $this->email ?: '#' . $this->id)));
    }

    /**
     * The fields a role needs that are empty (`invoice` follows the contact's document type: a receipt needs less).
     *
     * @return array<int, string>
     */
    public function missingFor(string $role): array
    {
        if ($role === 'invoice' && $this->document_type !== 'invoice') {
            $role = 'receipt';
        }

        return array_values(array_filter(self::REQUIRED[$role] ?? [], fn (string $field) => trim((string) $this->{$field}) === ''));
    }

    public function isCompleteFor(string $role): bool
    {
        return $this->missingFor($role) === [];
    }

    /** The address as lines, for documents (invoice, PDF). @return array<int, string> */
    public function addressLines(): array
    {
        $country = $this->country ? (config('app.countries')[$this->country] ?? $this->country) : null;

        return array_values(array_filter([
            $this->address1,
            $this->address2,
            trim($this->postcode . ' ' . $this->city),
            $this->state,
            $country,
        ], fn ($line) => trim((string) $line) !== ''));
    }

    /**
     * Make this contact the account's primary (its details): the old primary stays as an ordinary contact. The unique
     * primary_of_client_id is freed before it is taken, in one transaction. The account's mails stay as they were: the
     * new primary takes the old one's email preferences (owner 2026-10-30, ADV-545-6).
     */
    public function makePrimary(): void
    {
        DB::transaction(function () {
            // The account's row: two «make primary» at once wait for each other, also when there is no primary yet.
            Client::lock($this->client_id);
            $current = self::query()->where('primary_of_client_id', $this->client_id)->lockForUpdate()->first();
            if ($current?->is($this)) {
                return;
            }
            $current?->forceFill(['primary_of_client_id' => null])->save();
            $this->forceFill(['primary_of_client_id' => $this->client_id, 'archived_at' => null]
                + ($current !== null ? ['email_preferences' => $current->email_preferences] : []))->save();
        });
    }

    /**
     * The domains of its own account it is a contact of, by role (P1b): those still held (a deleted one too: archive()
     * keeps it, a restore could bring it back; not for the lock). $atRegistrar: only the roles its registrar holds it in
     * (the role has a handle), not ended: what locks who it is (adversarial F1 of #247: an order sets the account's
     * contact on a domain long before any registrar has it, and an unpaid one never sends it).
     *
     * @return Collection<int, array{fqdn: string, role: string, domain_id: int}>
     */
    public function domainRoles(bool $atRegistrar = false): Collection
    {
        if (!$this->exists) {
            return collect();
        }
        $ended = $atRegistrar ? DomainStatus::terminalValues() : [DomainStatus::TransferredAway->value, DomainStatus::Cancelled->value];
        $roles = collect();
        foreach (Domain::ROLES as $role) {
            Domain::query()->where("{$role}_contact_id", $this->id)->where('client_id', $this->client_id)
                ->whereNotIn('status', $ended)
                ->when($atRegistrar, fn ($q) => $q->whereNotNull("{$role}_handle")->where("{$role}_handle", '!=', ''))
                ->orderBy('fqdn')->get(['id', 'fqdn'])
                ->each(fn (Domain $domain) => $roles->push(['fqdn' => $domain->fqdn, 'role' => $role, 'domain_id' => $domain->id]));
        }

        return $roles->sortBy(fn (array $use) => $use['fqdn'] . '|' . array_search($use['role'], Domain::ROLES, true))->values();
    }

    /**
     * Who it is cannot change: a registrar holds it as a domain's contact (P1b-1). One query, kept for the request (a form
     * asks it for each field, code review of #247); the model's own check on save is always fresh.
     */
    public function identityLocked(): bool
    {
        if (!$this->exists) {
            return false;
        }
        $cache = request()->attributes->get('contact_identity_locked', []);
        if (!array_key_exists($this->id, $cache)) {
            $cache[$this->id] = Domain::query()->where('client_id', $this->client_id)->whereNotIn('status', DomainStatus::terminalValues())
                ->where(function ($query) {
                    foreach (Domain::ROLES as $role) {
                        $query->orWhere(fn ($q) => $q->where("{$role}_contact_id", $this->id)->whereNotNull("{$role}_handle")->where("{$role}_handle", '!=', ''));
                    }
                })->exists();
            request()->attributes->set('contact_identity_locked', $cache);
        }

        return $cache[$this->id];
    }

    /**
     * domainRoles() of several contacts of one account at once (the portal's list: 4 queries, not 4 per contact).
     *
     * @param  Collection<int, Contact>  $contacts
     * @return array<int, Collection<int, array{fqdn: string, role: string, domain_id: int}>>
     */
    public static function domainRolesFor(Collection $contacts): array
    {
        $uses = $contacts->mapWithKeys(fn (Contact $contact) => [$contact->id => collect()])->all();
        $byAccount = $contacts->groupBy('client_id');
        foreach (Domain::ROLES as $role) {
            foreach ($byAccount as $clientId => $group) {
                Domain::query()->where('client_id', $clientId)->whereIn("{$role}_contact_id", $group->pluck('id')->all())
                    ->whereNotIn('status', [DomainStatus::TransferredAway->value, DomainStatus::Cancelled->value])
                    ->orderBy('fqdn')->get(['id', 'fqdn', "{$role}_contact_id"])
                    ->each(function (Domain $domain) use (&$uses, $role) {
                        $uses[(int) $domain->{"{$role}_contact_id"}]->push(['fqdn' => $domain->fqdn, 'role' => $role, 'domain_id' => $domain->id]);
                    });
            }
        }

        return array_map(fn ($list) => $list->sortBy(fn (array $use) => $use['fqdn'] . '|' . array_search($use['role'], Domain::ROLES, true))->values(), $uses);
    }

    /** Archived, not deleted (it may be on documents or domains). The primary contact stays. */
    public function archive(): void
    {
        if ($this->isPrimary()) {
            throw new DisplayException(__('The primary contact is the account\'s details: make another contact primary first.'));
        }
        // A contact role of a domain still held (docs/domains.md D-3): its registrar data is this contact. A deleted
        // domain counts: a restore can bring it back (review G).
        if (($use = $this->domainRoles()->first()) !== null) {
            throw new DisplayException(__('domains.contact_in_use', ['role' => __('domains.role.' . $use['role']), 'domain' => $use['fqdn']]));
        }
        $this->forceFill(['archived_at' => now()])->save();
    }
}
