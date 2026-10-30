<?php

namespace App\Livewire\Client;

use App\Livewire\Component;
use App\Livewire\Traits\EditsContact;
use App\Models\Client;
use App\Models\Contact;
use App\Services\Tax\EuVat;
use App\Support\CurrentClient;
use App\Support\EmailKinds;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * The account's other contacts (docs/accounts.md, PR 3a; WHMCS «Contacts», permission `contacts`): mail recipients,
 * invoice recipients and, with the domains, domain contacts. The account's own details (its primary contact) are on
 * the account page. A contact is archived, not deleted.
 */
class Contacts extends Component
{
    use EditsContact;

    /** The contact being edited; null with the form open = a new one. */
    #[Locked]
    public ?int $editing = null;

    public bool $showForm = false;

    private function account(): Client
    {
        $client = CurrentClient::model();
        abort_unless($client !== null && CurrentClient::allows('contacts'), 403);

        return $client;
    }

    /** One of the account's own contacts, never its primary (that is the account page) nor another account's. */
    private function contactOf(int $id): Contact
    {
        // Not an archived one either: it is off the list, and may be on documents (or domains).
        return $this->account()->contacts()->whereKey($id)->whereNull('primary_of_client_id')->whereNull('archived_at')->firstOrFail();
    }

    public function create(): void
    {
        $this->account();
        $this->editing = null;
        $this->fillContact(null);
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $contact = $this->contactOf($id);
        $this->editing = $contact->id;
        $this->fillContact($contact);
        $this->showForm = true;
    }

    public function save(): void
    {
        $client = $this->account();
        $this->validate($this->contactRules());

        // The lock as the contact saved has it now, not as the form kept it (adversarial F4 of #247): a new one has none.
        if ($this->editing !== null) {
            $contact = $this->contactOf($this->editing);
            $this->identityLocked = $contact->identityLocked();
            // Another address on a contact taking a kind the login may not see would be theirs to read (code review 1 of #550).
            $email = mb_strtolower(trim((string) ($this->contact['email'] ?? '')));
            if ($email !== mb_strtolower(trim((string) $contact->email)) && array_intersect($contact->emailPreferencesShown(), $this->kindsNotAllowed()) !== []) {
                throw ValidationException::withMessages(['contact.email' => __('account.contact_email_kinds_not_allowed')]);
            }
            $contact->update($this->withAllowedKinds($this->contactValues(), $contact));
        } else {
            $this->identityLocked = false;
            $contact = $client->contacts()->create($this->withAllowedKinds($this->contactValues(), null));
        }

        $this->showForm = false;
        $this->editing = null;
        // A new EU VAT number is charged VAT until VIES finds it valid (PR G): the customer is told.
        $this->notify(__('account.contact_saved') . (EuVat::enabled() && $contact->fresh()?->vat_status === EuVat::STATUS_PENDING
            ? ' ' . __('vat.saved_pending') : ''), 'success');
    }

    /**
     * The mails it takes, as far as the login may choose them (EmailKinds::PERMISSIONS): a kind it may not see stays as
     * it was (a new contact: not taken).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withAllowedKinds(array $values, ?Contact $contact): array
    {
        $was = $contact?->emailPreferencesShown() ?? [];
        $notAllowed = $this->kindsNotAllowed();
        $kinds = array_filter(Contact::EMAIL_PREFERENCES, fn (string $kind) => !in_array($kind, $notAllowed, true)
            ? in_array($kind, $values['email_preferences'], true) : in_array($kind, $was, true));
        $values['email_preferences'] = array_values($kinds);

        return $values;
    }

    /** @return list<string> the kinds of mail the login may not choose for a contact (EmailKinds::PERMISSIONS) */
    private function kindsNotAllowed(): array
    {
        return array_values(array_filter(Contact::EMAIL_PREFERENCES, fn (string $kind) => !CurrentClient::allows(EmailKinds::PERMISSIONS[$kind])));
    }

    public function archive(int $id): void
    {
        $this->contactOf($id)->archive();
        $this->notify(__('account.contact_archived'), 'success');
    }

    public function render()
    {
        $contacts = $this->account()->contacts()->whereNull('primary_of_client_id')->active()->orderBy('id')->get();

        return view('client.account.contacts', [
            'contacts' => $contacts,
            // Where each is a domain's contact (P1b): its name stays as its registrars hold it, it is not archived.
            'uses' => Contact::domainRolesFor($contacts),
            // Shown, not changeable: what the login may not see (withAllowedKinds keeps them as they were).
            'lockedKinds' => $this->kindsNotAllowed(),
        ])->layoutData([
            'sidebar' => true,
            'title' => __('account.contacts'),
        ]);
    }
}
