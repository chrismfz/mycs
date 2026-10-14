<?php

namespace App\Http\Requests\Api\Admin\Clients;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class UpdateClientRequest extends AdminApiRequest
{
    protected $permission = 'clients.update';

    public function rules(): array
    {
        return [
            'credit_auto_apply' => 'sometimes|boolean',
        ];
    }
}
