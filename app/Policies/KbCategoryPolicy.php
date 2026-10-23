<?php

namespace App\Policies;

use App\Models\KbCategory;
use App\Models\Staff;
use App\Models\User;

/** Who keeps the knowledgebase (admin.knowledgebase.*); the public reads it on its own pages. */
class KbCategoryPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.knowledgebase.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.knowledgebase.create');
    }

    public function update(User|Staff $user, KbCategory $record): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.knowledgebase.update');
    }

    public function delete(User|Staff $user, KbCategory $record): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.knowledgebase.delete');
    }
}
