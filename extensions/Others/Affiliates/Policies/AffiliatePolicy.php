<?php

namespace Paymenter\Extensions\Others\Affiliates\Policies;

use App\Models\Staff;
use App\Models\User;
use App\Policies\BasePolicy;
use Paymenter\Extensions\Others\Affiliates\Models\Affiliate;

class AffiliatePolicy extends BasePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.affiliates.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, Affiliate $affiliate): bool
    {
        return $user->hasPermission('admin.affiliates.view');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.affiliates.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, Affiliate $affiliate): bool
    {
        return $user->hasPermission('admin.affiliates.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, Affiliate $affiliate): bool
    {
        return $user->hasPermission('admin.affiliates.delete');
    }

    /**
     * Determine whether the user can delete multiple models.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.affiliates.delete');
    }
}
