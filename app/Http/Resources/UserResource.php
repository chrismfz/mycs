<?php

namespace App\Http\Resources;

use App\Models\User;
use TiMacDonald\JsonApi\JsonApiResource;

/** @mixin User */
class UserResource extends JsonApiResource
{
    public $attributes = [
        'id',
        'first_name',
        'last_name',
        'email',
        'email_verified_at',
        'updated_at',
        'created_at',
    ];

    public $relationships = [
        'clients' => ClientResource::class,
    ];
}
