<?php

namespace App\Http\Requests\Api\Admin\Clients;

use App\Http\Requests\Api\Admin\AdminApiRequest;

class GetClientsRequest extends AdminApiRequest
{
    protected $permission = 'clients.view';
}
