<?php

namespace App\Observers;

use App\Events\Service\RenewalTaxChanged;
use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\Service;

class ClientObserver
{
    /** Tax exempt or not changes what its subscriptions bill (Δ1). */
    public function updated(Client $client): void
    {
        if ($client->wasChanged('tax_exempt')) {
            RenewalTaxChanged::forSubscribed(Service::query()->where('client_id', $client->id));
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
        // Its services are deleted first, one way (DeleteService: the server, the subscription, the invoices): the
        // database cascade took them silently, and on MariaDB it fails on the orders' restrict (R7 of #82).
        if ($client->services()->exists()) {
            throw new DisplayException(__('This customer has services: delete them first.'));
        }
    }
}
