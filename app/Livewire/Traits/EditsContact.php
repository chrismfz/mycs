<?php

namespace App\Livewire\Traits;

use App\Models\Contact;
use App\Support\CurrentClient;
use App\Support\EmailKinds;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

/**
 * A contact's fields in a client-area form (docs/accounts.md, PR 3a): the account's details (its primary contact)
 * on the account page and at registration, the other contacts on the contacts page. What a role needs (an invoice's
 * tax details, a domain's address) is checked where the contact is used, not here.
 */
trait EditsContact
{
    /** @var array<string, mixed> */
    public array $contact = [];

    /** The fields a customer edits (not the primary flag, the account or the archive). */
    public const CONTACT_FIELDS = ['type', 'first_name', 'last_name', 'company_name', 'tax_id', 'vat_number', 'tax_office', 'activity',
        'address1', 'address2', 'city', 'state', 'postcode', 'country', 'email', 'phone', 'document_type', 'email_preferences'];

    /**
     * Who the contact is cannot change: a domain uses it (P1b, Contact::IDENTITY). Shown as such, and not sent; set by the
     * server only.
     */
    #[Locked]
    public bool $identityLocked = false;

    protected function fillContact(?Contact $contact): void
    {
        $this->identityLocked = $contact !== null && $contact->identityLocked();
        $this->contact = [];
        foreach (self::CONTACT_FIELDS as $field) {
            $this->contact[$field] = $contact->{$field} ?? match ($field) {
                'type' => 'individual',
                // None chosen (null): as it is read (Contact::takesEmail); a new contact starts with none.
                'email_preferences' => $contact?->emailPreferencesShown() ?? [],
                default => '',
            };
        }
    }

    /** @return array<string, mixed> */
    protected function contactRules(string $prefix = 'contact'): array
    {
        $text = ['nullable', 'string', 'max:255'];

        return [
            "{$prefix}.type" => ['required', Rule::in(Contact::TYPES)],
            "{$prefix}.first_name" => $text,
            "{$prefix}.last_name" => $text,
            "{$prefix}.company_name" => $text,
            "{$prefix}.tax_id" => ['nullable', 'string', 'max:50'],
            // An EU VAT number: country prefix (EL for Greece) and 2–13 characters; spaces, dots and dashes are allowed.
            "{$prefix}.vat_number" => ['nullable', 'string', 'max:50', 'regex:/^[A-Z]{2}[\s.\-]*[0-9A-Z+*][0-9A-Z+*\s.\-]{1,20}$/i'],
            "{$prefix}.tax_office" => $text,
            "{$prefix}.activity" => $text,
            "{$prefix}.address1" => $text,
            "{$prefix}.address2" => $text,
            "{$prefix}.city" => $text,
            "{$prefix}.state" => $text,
            "{$prefix}.postcode" => ['nullable', 'string', 'max:20'],
            "{$prefix}.country" => ['nullable', Rule::in(array_keys(array_slice((array) config('app.countries'), 1, null, true)))],
            "{$prefix}.email" => ['nullable', 'email', 'max:255'],
            "{$prefix}.phone" => ['nullable', 'string', 'max:50'],
            "{$prefix}.document_type" => ['nullable', Rule::in(Contact::DOCUMENT_TYPES)],
            "{$prefix}.email_preferences" => ['array'],
            "{$prefix}.email_preferences.*" => [Rule::in(Contact::EMAIL_PREFERENCES)],
        ];
    }

    /**
     * The account's details (its primary contact): the country is required, as WHMCS asks it, because the VAT is the
     * country's (AUD5-1). Other contacts may leave it empty.
     *
     * @return array<string, mixed>
     */
    protected function primaryContactRules(string $prefix = 'contact'): array
    {
        $rules = $this->contactRules($prefix);
        $rules["{$prefix}.country"][0] = 'required';

        return $rules;
    }

    /** @return array<string, mixed> The validated values, empty strings as null. */
    protected function contactValues(): array
    {
        $values = [];
        foreach (self::CONTACT_FIELDS as $field) {
            $value = $this->contact[$field] ?? null;
            $values[$field] = $value === '' ? null : $value;
        }
        $values['country'] = $values['country'] !== null ? strtoupper((string) $values['country']) : null;
        $values['vat_number'] = $values['vat_number'] !== null ? strtoupper(preg_replace('/[\s.\-]/', '', (string) $values['vat_number'])) : null;
        $values['email_preferences'] = array_values((array) ($values['email_preferences'] ?? []));
        if ($this->identityLocked) {
            $values = array_diff_key($values, array_flip(Contact::IDENTITY));
        }

        return $values;
    }

    /**
     * The mails it takes, as far as the login may choose them (EmailKinds::PERMISSIONS): a kind it may not see stays as
     * it was (a new contact: not taken).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function withAllowedKinds(array $values, ?Contact $contact): array
    {
        $was = $contact?->emailPreferencesShown() ?? [];
        $notAllowed = $this->kindsNotAllowed();
        $kinds = array_filter(Contact::EMAIL_PREFERENCES, fn (string $kind) => !in_array($kind, $notAllowed, true)
            ? in_array($kind, $values['email_preferences'], true) : in_array($kind, $was, true));
        $values['email_preferences'] = array_values($kinds);

        return $values;
    }

    /** @return list<string> the kinds of mail the login may not choose for a contact (EmailKinds::PERMISSIONS) */
    protected function kindsNotAllowed(): array
    {
        return array_values(array_filter(Contact::EMAIL_PREFERENCES, fn (string $kind) => !CurrentClient::allows(EmailKinds::PERMISSIONS[$kind])));
    }
}
