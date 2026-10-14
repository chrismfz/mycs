<?php

namespace App\Observers;

use App\Events\User as UserEvent;
use App\Exceptions\DisplayException;
use App\Models\ClientUser;
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
     * A login that owns an account is not deleted while the account is there (docs/accounts.md, PR 2a): the account
     * would be left without its owner. The account goes first (with its own guards), or another user becomes owner.
     */
    public function deleting(User $user): void
    {
        if (ClientUser::query()->where('user_id', $user->id)->whereNotNull('owner_of_client_id')->exists()) {
            throw new DisplayException(__('This user owns a client account: delete the account first.'));
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
