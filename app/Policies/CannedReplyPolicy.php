<?php

namespace App\Policies;

use App\Models\CannedReply;
use App\Models\Staff;
use App\Models\User;

/** Who keeps the predefined replies; any staff who may answer a ticket inserts them. */
class CannedReplyPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.canned_replies.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.canned_replies.create');
    }

    public function update(User|Staff $user, CannedReply $reply): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.canned_replies.update');
    }

    public function delete(User|Staff $user, CannedReply $reply): bool
    {
        return $user instanceof Staff && $user->hasPermission('admin.canned_replies.delete');
    }
}
