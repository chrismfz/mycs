<?php

namespace App\Http\Requests\Api\Admin\Clients;

use App\Http\Requests\Api\Admin\AdminApiRequest;

/** A new account with its owner (docs/accounts.md, PR 2a): an existing user, or a new one from its details. */
class CreateClientRequest extends AdminApiRequest
{
    protected $permission = 'clients.create';

    public function rules(): array
    {
        return [
            'user_id' => 'nullable|required_without:email|prohibits:email|exists:users,id',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|required_without:user_id|email|max:255|unique:users,email',
            'password' => 'required_with:email|nullable|string|min:8',
            'credit_auto_apply' => 'sometimes|boolean',
        ];
    }
}
