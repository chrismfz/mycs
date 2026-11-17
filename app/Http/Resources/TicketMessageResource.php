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
        // An internal note is staff's only: whoever mirrors messages to a customer must be able to tell (audit 2026-11-17).
        'is_internal',
        'via',
        'sender_email',
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
