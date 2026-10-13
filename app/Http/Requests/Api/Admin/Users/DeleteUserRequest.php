<?php

namespace App\Http\Requests\Api\Admin\Users;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class DeleteUserRequest extends AdminApiRequest
{
    protected $permission = 'users.delete';

    public function authorize(): bool
    {
        return parent::authorize() && $this->mayActOn($this->route('user'));
    }
}
