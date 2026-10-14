<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\Staff;
use App\Models\User;

class OrderPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.orders.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, Order $order): bool
    {
        return $user->hasPermission('admin.orders.viewAny');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.orders.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, Order $order): bool
    {
        return $user->hasPermission('admin.orders.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, Order $order): bool
    {
        return $user->hasPermission('admin.orders.delete');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.orders.deleteAny');
    }
}
