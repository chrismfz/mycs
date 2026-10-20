<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
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

    /** The department's name, as the API gave it before departments were a table. */
    public function toAttributes(Request $request): array
    {
        return ['department' => fn () => $this->resource->department?->name];
    }

    public $relationships = [
        'messages' => TicketMessageResource::class,
        'client' => ClientResource::class,
        'assigned_to' => StaffResource::class,
    ];
}
