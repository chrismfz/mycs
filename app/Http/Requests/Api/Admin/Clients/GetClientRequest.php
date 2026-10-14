<?php

namespace App\Http\Requests\Api\Admin\Clients;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class GetClientRequest extends AdminApiRequest
{
    protected $permission = 'clients.view';
}
