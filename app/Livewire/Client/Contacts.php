<?php

namespace App\Livewire\Client;

use App\Livewire\Component;
use App\Livewire\Traits\EditsContact;
use App\Models\Client;
use App\Models\Contact;
use App\Services\Tax\EuVat;
use App\Support\CurrentClient;
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
            $contact->update($this->contactValues());
        } else {
            $this->identityLocked = false;
            $contact = $client->contacts()->create($this->contactValues());
        }

        $this->showForm = false;
        $this->editing = null;
        // A new EU VAT number is charged VAT until VIES finds it valid (PR G): the customer is told.
        $this->notify(__('account.contact_saved') . (EuVat::enabled() && $contact->fresh()?->vat_status === EuVat::STATUS_PENDING
            ? ' ' . __('vat.saved_pending') : ''), 'success');
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
        ])->layoutData([
            'sidebar' => true,
            'title' => __('account.contacts'),
        ]);
    }
}
