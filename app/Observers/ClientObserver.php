<?php

namespace App\Observers;

use App\Events\Service\RenewalTaxChanged;
use App\Exceptions\DisplayException;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Service;
use App\Services\Billing\ClientGroupPricing;

class ClientObserver
{
    /** Its renewals are charged only to one of its own methods (docs/accounts.md, PR 2a; decision 6). */
    public function saving(Client $client): void
    {
        if ($client->isDirty('default_billing_agreement_id') && $client->default_billing_agreement_id !== null
            && !BillingAgreement::query()->whereKey($client->default_billing_agreement_id)->where('client_id', $client->id)->exists()) {
            throw new DisplayException(__('This payment method belongs to another account.'));
        }
    }

    /** Tax exempt or not changes what its subscriptions bill (Δ1). */
    public function updated(Client $client): void
    {
        if ($client->wasChanged('tax_exempt')) {
            RenewalTaxChanged::forSubscribed(Service::query()->where('client_id', $client->id));
        }
        // Moved group (ROADMAP 5): its services' computed prices take the new discount.
        if ($client->wasChanged('client_group_id')) {
            $client->unsetRelation('group');
            ClientGroupPricing::reprice($client);
        }
    }

    /**
     * An account with money history is never deleted (docs/money-trail.md §8): its invoices would lose their customer
     * and its credit would go by cascade. The journal's foreign key refuses it too; this gives the reason.
     */
    public function deleting(Client $client): void
    {
        if ($client->hasMoneyHistory()) {
            throw new DisplayException(__('A customer with payments or account credit history cannot be deleted.'));
        }
        // Domains end by status and keep their history (docs/domains.md D-18): an account that has any is kept.
        if ($client->domains()->exists()) {
            throw new DisplayException(__('domains.client_has_domains'));
        }
        // Its services are deleted first, one way (DeleteService: the server, the subscription, the invoices): the
        // database cascade took them silently, and on MariaDB it fails on the orders' restrict (R7 of #82).
        if ($client->services()->exists()) {
            throw new DisplayException(__('This customer has services: delete them first.'));
        }
    }
}
