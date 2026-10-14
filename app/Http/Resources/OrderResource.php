<?php

namespace App\Http\Resources;

use TiMacDonald\JsonApi\JsonApiResource;

class OrderResource extends JsonApiResource
{
    public $attributes = [
        'id',
        'currency_code',
        'client_id',
        'notes',
        'updated_at',
        'created_at',
    ];

    public $relationships = [
        'services' => ServiceResource::class,
        'client' => ClientResource::class,
    ];
}
