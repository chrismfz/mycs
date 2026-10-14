<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;

class TaxRatePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.tax_rates.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, TaxRate $taxRate): bool
    {
        return $user->hasPermission('admin.tax_rates.viewAny');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.tax_rates.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, TaxRate $taxRate): bool
    {
        return $user->hasPermission('admin.tax_rates.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, TaxRate $taxRate): bool
    {
        return $user->hasPermission('admin.tax_rates.delete');
    }

    /**
     * Determine whether the user can delete multiple models.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.tax_rates.deleteAny');
    }
}
