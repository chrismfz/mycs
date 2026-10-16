<?php

namespace App\Models;

use App\Enums\DomainStatus;
use App\Events\Service\RenewalTaxChanged;
use App\Exceptions\DisplayException;
use App\Jobs\CheckVatNumber;
use App\Services\Tax\EuVat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
class Contact extends Model
{
    use HasFactory;

    public const TYPES = ['individual', 'business'];

    public const DOCUMENT_TYPES = ['invoice', 'receipt', 'none'];

    /** The mails a contact can receive (tblcontacts' generalemails, invoiceemails…). */
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

    protected static function booted(): void
    {
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
     * primary_of_client_id is freed before it is taken, in one transaction.
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
            $this->forceFill(['primary_of_client_id' => $this->client_id, 'archived_at' => null])->save();
        });
    }

    /** Archived, not deleted (it may be on documents or domains). The primary contact stays. */
    public function archive(): void
    {
        if ($this->isPrimary()) {
            throw new DisplayException(__('The primary contact is the account\'s details: make another contact primary first.'));
        }
        // A contact role of a domain still held (docs/domains.md D-3): its registrar data is this contact. A deleted
        // domain counts: a restore can bring it back (review G).
        foreach (Domain::ROLES as $role) {
            $domain = Domain::query()->where("{$role}_contact_id", $this->id)
                ->whereNotIn('status', [DomainStatus::TransferredAway->value, DomainStatus::Cancelled->value])
                ->first();
            if ($domain !== null) {
                throw new DisplayException(__('domains.contact_in_use', ['role' => __('domains.role.' . $role), 'domain' => $domain->fqdn]));
            }
        }
        $this->forceFill(['archived_at' => now()])->save();
    }
}
