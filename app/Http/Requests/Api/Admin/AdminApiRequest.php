<?php

namespace App\Http\Requests\Api\Admin;

use App\Classes\PermissionGuard;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

abstract class AdminApiRequest extends FormRequest
{
    protected $permission;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->hasApiPermission('admin.' . $this->permission);
    }

    protected function hasApiPermission(string $permission): bool
    {
        return in_array($permission, $this->instance()->attributes->get('api_key_permissions', []));
    }

    /** The admin who made the API key; null for keys made before keys had a creator. */
    protected function keyOwner(): ?User
    {
        return $this->instance()->attributes->get('api_key')?->user;
    }

    /**
     * PermissionGuard for the API, as in the admin panel: the key may change or delete $target only if its owner
     * outranks them. A key without an owner may act on customers (no role) only.
     */
    protected function mayActOn(User $target): bool
    {
        $owner = $this->keyOwner();

        return $owner === null ? $target->role === null : PermissionGuard::outranks($owner, $target);
    }

    /** Assigning a role: only one whose permissions the key's owner could grant. No role (null) is always allowed. */
    protected function mayAssignRole(mixed $roleId): bool
    {
        if ($roleId === null || $roleId === '') {
            return true;
        }
        $role = Role::query()->find($roleId);

        return $role === null || PermissionGuard::canGrant($this->keyOwner(), $role->permissions ?? []); // unknown: validation answers
    }

    /**
     * Default set of rules to apply to API requests.
     */
    public function rules(): array
    {
        return [];
    }
}
