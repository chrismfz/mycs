<?php

namespace App\Http\Resources;

use TiMacDonald\JsonApi\JsonApiResource;

class TicketMessageResource extends JsonApiResource
{
    public $attributes = [
        'id',
        'message',
        'user_id',
        'staff_id',
        'updated_at',
        'created_at',
    ];

    public $relationships = [
        'user' => UserResource::class,
        'staff' => StaffResource::class,
        'ticket' => TicketResource::class,
        'attachments' => TicketAttachmentResource::class,
    ];
}
