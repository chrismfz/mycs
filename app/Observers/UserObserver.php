<?php

namespace App\Observers;

use App\Events\User as UserEvent;
use App\Exceptions\DisplayException;
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
     * A customer with money history is never deleted (docs/money-trail.md §8): their invoices would lose their
     * customer and their credit would go by cascade. The journal's foreign key refuses it too; this gives the reason.
     */
    public function deleting(User $user): void
    {
        if ($user->hasMoneyHistory()) {
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
