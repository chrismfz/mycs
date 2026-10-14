<?php

namespace App\Policies;

use App\Models\Currency;
use App\Models\Staff;
use App\Models\User;

class CurrencyPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.currencies.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, Currency $currency): bool
    {
        return $user->hasPermission('admin.currencies.viewAny');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.currencies.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, Currency $currency): bool
    {
        return $user->hasPermission('admin.currencies.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, Currency $currency): bool
    {
        return $user->hasPermission('admin.currencies.delete');
    }

    /**
     * Determine whether the user can delete any models.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.currencies.deleteAny');
    }
}
