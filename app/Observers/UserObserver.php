<?php

namespace App\Observers;

use App\Events\User as UserEvent;
use App\Exceptions\DisplayException;
use App\Models\MoneyEvent;
use App\Models\User;

class UserObserver
{
    /**
     * Handle the User "creating" event.
     */
    public function creating(User $user): void
    {
        event(new UserEvent\Creating($user));
    }

    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        event(new UserEvent\Created($user));
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        event(new UserEvent\Updated($user));
    }

    /**
     * A customer with money movements is never deleted (docs/money-trail.md §8): the cascade would take their
     * invoices, transactions and credit. The journal's foreign key refuses it too; this gives the reason.
     */
    public function deleting(User $user): void
    {
        if (MoneyEvent::query()->where('user_id', $user->id)->exists()) {
            throw new DisplayException(__('A customer with payments or account credit history cannot be deleted.'));
        }
    }

    /**
     * Handle the User "deleted" event.
     */
    public function deleted(User $user): void
    {
        //
    }
}
