<?php

namespace App\Policies;

use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\User;

class TicketDepartmentPolicy
{
    public function viewAny(User|Staff $user): bool
    {
        return $user->hasPermission('admin.ticket_departments.viewAny');
    }

    public function view(User|Staff $user, TicketDepartment $department): bool
    {
        return $user->hasPermission('admin.ticket_departments.viewAny');
    }

    public function create(User|Staff $user): bool
    {
        return $user->hasPermission('admin.ticket_departments.create');
    }

    public function update(User|Staff $user, TicketDepartment $department): bool
    {
        return $user->hasPermission('admin.ticket_departments.update');
    }

    /** One with tickets is hidden instead: its tickets keep their department. */
    public function delete(User|Staff $user, TicketDepartment $department): bool
    {
        return $user->hasPermission('admin.ticket_departments.delete') && !$department->tickets()->exists();
    }
}
