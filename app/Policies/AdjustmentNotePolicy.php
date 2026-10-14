<?php

namespace App\Policies;

use App\Models\AdjustmentNote;
use App\Models\Staff;
use App\Models\User;

class AdjustmentNotePolicy extends BasePolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.adjustment_notes.viewAny');
    }

    public function view(User|Staff $user, AdjustmentNote $adjustmentNote): bool
    {
        return $this->adminPermission($user, 'admin.adjustment_notes.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.adjustment_notes.create');
    }

    public function update(User|Staff $user, AdjustmentNote $adjustmentNote): bool
    {
        return $this->adminPermission($user, 'admin.adjustment_notes.update');
    }

    public function delete(User|Staff $user, AdjustmentNote $adjustmentNote): bool
    {
        return $user->hasPermission('admin.adjustment_notes.delete');
    }

    public function deleteAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.adjustment_notes.deleteAny');
    }
}
