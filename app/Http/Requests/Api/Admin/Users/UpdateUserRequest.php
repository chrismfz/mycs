<?php

namespace App\Http\Requests\Api\Admin\Users;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class UpdateUserRequest extends AdminApiRequest
{
    protected $permission = 'users.update';

    public function rules(): array
    {
        return [
            'first_name' => 'sometimes|nullable|string|max:255',
            'last_name' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|required|email|max:255|unique:users,email,' . $this->route()->parameter('user')->id,
            'password' => 'sometimes|string|min:8',
            'email_verified_at' => 'sometimes|nullable|date',
            // Users are customers: roles are staff's only (docs/accounts.md, PR 1).
            'role_id' => 'prohibited',
        ];
    }
}
