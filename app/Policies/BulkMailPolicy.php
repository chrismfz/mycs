<?php

namespace App\Policies;

use App\Models\BulkMail;
use App\Models\Staff;
use App\Models\User;

class BulkMailPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.bulk_mails.viewAny');
    }

    public function view(User|Staff $user, BulkMail $mail): bool
    {
        return $user->hasPermission('admin.bulk_mails.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.bulk_mails.create');
    }

    /** Its page is its edit page: viewing a sent one too (its form disabled). */
    public function update(User|Staff $user, BulkMail $mail): bool
    {
        return $user->hasPermission('admin.bulk_mails.update');
    }

    /** A draft only: what was sent stays on record. */
    public function delete(User|Staff $user, BulkMail $mail): bool
    {
        return $mail->isDraft() && $user->hasPermission('admin.bulk_mails.delete');
    }

    public function deleteAny(User|Staff $user): bool
    {
        return false;
    }
}
