<?php

namespace App\Observers;

use App\Models\ClientGroup;
use App\Services\Billing\ClientGroupPricing;

class ClientGroupObserver
{
    /** Its percentage changed: its members' computed prices follow (ClientGroupPricing). */
    public function updated(ClientGroup $group): void
    {
        if ($group->wasChanged('discount_percent')) {
            $before = (float) $group->getOriginal('discount_percent') > 0 ? (string) $group->getOriginal('discount_percent') : null;
            foreach ($group->clients()->get() as $client) {
                ClientGroupPricing::reprice($client->setRelation('group', $group), $before);
            }
        }
    }

    /** Deleted: its members leave it first, and lose its discount as for a move. */
    public function deleting(ClientGroup $group): void
    {
        foreach ($group->clients()->get() as $client) {
            $client->update(['client_group_id' => null]);
        }
    }
}
