<?php

namespace App\Http\Requests\Api\Admin\Clients;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class DeleteClientRequest extends AdminApiRequest
{
    protected $permission = 'clients.delete';
}
