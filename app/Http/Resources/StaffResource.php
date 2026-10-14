<?php

namespace App\Http\Resources;

use TiMacDonald\JsonApi\JsonApiResource;

/** A member of staff as the API shows them (a ticket's assignee): who, not how to log in. */
class StaffResource extends JsonApiResource
{
    public $attributes = [
        'id',
        'first_name',
        'last_name',
    ];
}
