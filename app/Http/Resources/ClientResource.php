<?php

namespace App\Http\Resources;

use App\Models\Client;
use TiMacDonald\JsonApi\JsonApiResource;

/** @mixin Client */
class ClientResource extends JsonApiResource
{
    public $attributes = [
        'id',
        'name',
        'email',
        'credit_auto_apply',
        'updated_at',
        'created_at',
    ];

    public $relationships = [
        'users' => UserResource::class,
        'properties' => PropertyResource::class,
        'orders' => OrderResource::class,
        'services' => ServiceResource::class,
        'invoices' => InvoiceResource::class,
        'tickets' => TicketResource::class,
        'credits' => CreditResource::class,
    ];

    public function toMeta($request): array
    {
        $meta = [];
        if ($this->resource->relationLoaded('properties')) {
            $meta['properties'] = $this->resource->properties->mapWithKeys(fn ($property) => [$property->key => $property->value]);
        }

        return $meta;
    }
}
