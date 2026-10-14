<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\Staff;
use App\Models\User;

class CategoryPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.categories.viewAny');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User|Staff $user, Category $category): bool
    {
        return $user->hasPermission('admin.categories.viewAny');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.categories.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User|Staff $user, Category $category): bool
    {
        return $user->hasPermission('admin.categories.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User|Staff $user, Category $category): bool
    {
        return $user->hasPermission('admin.categories.delete');
    }

    /**
     * Determine whether the user can bulk delete the model.
     */
    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.categories.deleteAny');
    }

    /** Reordering changes the catalog: the right to change it, not only to see it (Filament allows an undefined ability). */
    public function reorder(User|Staff $user): bool
    {
        return $user->hasPermission('admin.categories.update');
    }
}
