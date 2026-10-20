<?php

namespace App\Contracts;

use App\Models\Contact;
use App\Support\Domains\DomainRegistrarCredentials;

/**
 * A registrar whose contact a customer's edit reaches (P1b-2): its address, phone and email set to the contact's. Never
 * its name or company (P1b-1: who a contact is stays as the registrar holds it). The same request again changes nothing
 * more, so a retry after a lost answer is safe.
 */
interface UpdatesContacts
{
    public function updateContact(string $handle, Contact $contact, DomainRegistrarCredentials $credentials): void;
}
