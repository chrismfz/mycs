<?php

namespace App\Policies;

use App\Models\Offer;
use App\Models\Staff;
use App\Models\User;

class OfferPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.offers.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, Offer $offer): bool
    {
        return $user->hasPermission('admin.offers.viewAny');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.offers.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, Offer $offer): bool
    {
        return $user->hasPermission('admin.offers.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, Offer $offer): bool
    {
        return $user->hasPermission('admin.offers.delete');
    }

    /**
     * Determine whether the user can delete multiple models.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.offers.deleteAny');
    }
}
