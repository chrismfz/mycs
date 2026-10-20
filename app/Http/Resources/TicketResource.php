<?php

namespace App\Http\Resources;

use TiMacDonald\JsonApi\JsonApiResource;

class TicketResource extends JsonApiResource
{
    public $attributes = [
        'id',
        'reference',
        'subject',
        'status',
        'priority',
        'assigned_to',
        'client_id',
        'guest_name',
        'guest_email',
        'department_id',
        'updated_at',
        'created_at',
    ];

    public $relationships = [
        'messages' => TicketMessageResource::class,
        'client' => ClientResource::class,
        'assigned_to' => StaffResource::class,
    ];
}
