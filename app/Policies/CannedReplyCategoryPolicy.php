<?php

namespace App\Policies;

use App\Models\CannedReplyCategory;
use App\Models\Staff;
use App\Models\User;

/** The categories are kept by whoever keeps the predefined replies. */
class CannedReplyCategoryPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.canned_replies.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.canned_replies.create');
    }

    public function update(User|Staff $user, CannedReplyCategory $category): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.canned_replies.update');
    }

    public function delete(User|Staff $user, CannedReplyCategory $category): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.canned_replies.delete');
    }
}
