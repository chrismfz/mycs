<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;

/**
 * Custom field values (Properties tab) of a service or an account in the admin: seen and changed with the permissions of
 * what they belong to (SA-12).
 */
class PropertyPolicy extends BasePolicy
{
    private function owner(Property $property, string $action): string
    {
        return $property->model_type === Service::class ? "admin.services.{$action}" : "admin.clients.{$action}";
    }

    public function viewAny(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.services.viewAny') || $this->adminPermission($user, 'admin.clients.viewAny');
    }

    public function view(User|Staff $user, Property $property): bool
    {
        return $this->adminPermission($user, $this->owner($property, 'viewAny'));
    }

    public function create(User|Staff $user): bool
    {
        return $this->adminPermission($user, 'admin.services.update') || $this->adminPermission($user, 'admin.clients.update');
    }

    public function update(User|Staff $user, Property $property): bool
    {
        return $this->adminPermission($user, $this->owner($property, 'update'));
    }

    public function delete(User|Staff $user, Property $property): bool
    {
        return $this->adminPermission($user, $this->owner($property, 'update'));
    }

    public function deleteAny(User|Staff $user): bool
    {
        return $this->create($user);
    }
}
